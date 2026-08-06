<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;

/**
 * Responde a "a esta empresa no le llegan los mensajes de este número".
 *
 * Separa los tres escenarios que se confunden entre sí:
 *   1. El webhook nunca llegó      -> el número no aparece en los logs.
 *   2. Llegó y se descartó         -> aparece en el log con un warning.
 *   3. Llegó y se guardó           -> está en la BD, pero en otra empresa,
 *                                     en otra instancia o en un hilo distinto.
 */
class DiagnoseInboundWhatsApp extends Command
{
    protected $signature = 'whatsapp:diagnose
        {phone : Número del remitente, con o sin indicativo (ej. 573001112233)}
        {--days=7 : Días de logs a revisar}';

    protected $description = 'Diagnostica por qué no aparecen los mensajes entrantes de un número';

    public function handle(): int
    {
        $input = preg_replace('/\D+/', '', (string) $this->argument('phone'));

        if (strlen($input) < 7) {
            $this->error('Número inválido.');
            return self::FAILURE;
        }

        $variants = $this->numberVariants($input);
        $this->line('Buscando: ' . implode(', ', $variants));

        $this->section('1. Instancias configuradas');
        $this->reportInstances();

        $this->section('2. Conversaciones que coinciden (todas las empresas)');
        $conversations = $this->reportConversations($variants);

        $this->section('3. Últimos mensajes de ese número');
        $this->reportMessages($conversations);

        $this->section('4. Rastro en los logs del webhook');
        $this->reportLogs($variants);

        return self::SUCCESS;
    }

    /**
     * Un mismo teléfono llega escrito de varias formas según quién lo guardó
     * (con indicativo, sin él, con el 1/9 que agregan algunos países). Si el hilo
     * se creó con una variante y el webhook llega con otra, el agente ve el chat
     * "vacío" aunque el mensaje sí se guardó.
     */
    private function numberVariants(string $digits): array
    {
        $variants = [$digits];

        if (str_starts_with($digits, '57')) {
            $variants[] = substr($digits, 2);
        } else {
            $variants[] = '57' . $digits;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function reportInstances(): void
    {
        $rows = Instance::orderBy('phone_number_id')->get()
            ->map(fn ($i) => [
                $i->id,
                $i->company_id,
                $i->name,
                $i->phone_number_id ?: '—',
                $i->active ? 'sí' : 'NO',
            ])->all();

        if (!$rows) {
            $this->warn('No hay instancias registradas.');
            return;
        }

        $this->table(['id', 'empresa', 'nombre', 'phone_number_id', 'activa'], $rows);

        // El índice único es (company_id, phone_number_id): dos empresas pueden
        // reclamar el mismo número y el webhook se lo entrega solo a la primera.
        $duplicates = Instance::where('active', true)
            ->whereNotNull('phone_number_id')
            ->get()
            ->groupBy('phone_number_id')
            ->filter(fn ($group) => $group->count() > 1);

        foreach ($duplicates as $phoneNumberId => $group) {
            $this->error("🚨 phone_number_id {$phoneNumberId} está activo en "
                . $group->count() . ' empresas: ' . $group->pluck('company_id')->implode(', ')
                . '. Todos sus mensajes van a la instancia ' . $group->sortBy('id')->first()->id . '.');
        }

        $inactive = Instance::where('active', false)->count();
        if ($inactive) {
            $this->warn("Hay {$inactive} instancia(s) inactiva(s): sus webhooks se descartan sin guardar nada.");
        }
    }

    private function reportConversations(array $variants): \Illuminate\Support\Collection
    {
        $conversations = WhatsAppConversation::with('instance')
            ->where(function ($q) use ($variants) {
                foreach ($variants as $v) {
                    $q->orWhere('wa_id', $v)->orWhere('phone_number', $v);
                }
            })
            ->orderByDesc('last_message_at')
            ->get();

        if ($conversations->isEmpty()) {
            $this->warn('Ninguna conversación con ese número. El mensaje nunca se guardó.');
            return $conversations;
        }

        $this->table(
            ['id', 'empresa', 'instancia', 'wa_id', 'estado', 'no leídos', 'último mensaje'],
            $conversations->map(fn ($c) => [
                $c->id,
                $c->instance?->company_id ?? '—',
                $c->instance?->name ?? '—',
                $c->wa_id,
                $c->status,
                $c->unread_count,
                $c->last_message_at?->diffForHumans() ?? '—',
            ])->all()
        );

        if ($conversations->count() > 1) {
            $this->warn('Hay más de un hilo para el mismo número: el agente puede estar mirando el que no recibe.');
        }

        return $conversations;
    }

    private function reportMessages(\Illuminate\Support\Collection $conversations): void
    {
        if ($conversations->isEmpty()) {
            return;
        }

        $messages = WhatsAppMessage::whereIn('conversation_id', $conversations->pluck('id'))
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            $this->warn('La conversación existe pero no tiene mensajes.');
            return;
        }

        $this->table(
            ['fecha', 'conv', 'dirección', 'tipo', 'estado', 'contenido'],
            $messages->map(fn ($m) => [
                $m->created_at?->format('Y-m-d H:i'),
                $m->conversation_id,
                $m->direction,
                $m->type,
                $m->status,
                mb_strimwidth((string) $m->content, 0, 40, '…'),
            ])->all()
        );

        $lastInbound = $messages->where('direction', 'inbound')->last();
        $this->line($lastInbound
            ? 'Último entrante: ' . $lastInbound->created_at?->diffForHumans()
            : 'No hay ni un mensaje entrante: el webhook nunca entregó nada de este número.');
    }

    private function reportLogs(array $variants): void
    {
        $days = (int) $this->option('days');
        $files = collect(glob(storage_path('logs/whatsapp*.log')))
            ->filter(fn ($f) => filemtime($f) >= now()->subDays($days)->getTimestamp())
            ->values();

        if ($files->isEmpty()) {
            $this->warn('No hay logs de whatsapp de los últimos ' . $days . ' días en ' . storage_path('logs') . '.');
            return;
        }

        $hits = 0;
        $discarded = 0;

        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            while (($line = fgets($handle)) !== false) {
                foreach ($variants as $v) {
                    if (str_contains($line, $v)) {
                        $hits++;
                        if (str_contains($line, 'No se encontró instancia activa')
                            || str_contains($line, 'Error guardando mensaje entrante')
                            || str_contains($line, 'Mensaje duplicado')) {
                            $discarded++;
                            $this->warn(trim(mb_strimwidth($line, 0, 200, '…')));
                        }
                        break;
                    }
                }
            }
            fclose($handle);
        }

        $this->line("Menciones del número en los logs: {$hits} (descartes explícitos: {$discarded}).");

        if ($hits === 0) {
            $this->error('El número no aparece en ningún log: Meta nunca envió el webhook. '
                . 'Revisa en Meta que la WABA esté suscrita al campo "messages" y que la URL del webhook sea la de este servidor.');
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info("── {$title}");
    }
}
