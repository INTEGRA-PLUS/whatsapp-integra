<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Une los hilos que quedaron partidos por la forma de escribir el número y deja
 * todos los `wa_id` en su forma canónica (solo dígitos).
 *
 * El síntoma que repara: un aviso saliente creó el hilo con el número tal como
 * lo tenía el sistema externo ("57300 825 3303"), el cliente respondió, y el
 * webhook —que recibe el número limpio— abrió un segundo hilo. El agente seguía
 * mirando el primero y no veía nunca la respuesta.
 *
 * Solo simula, salvo que se pase --apply.
 */
class FixDuplicateConversations extends Command
{
    protected $signature = 'whatsapp:fix-duplicate-conversations
        {--apply : Aplica los cambios (sin esta opción solo se simula)}
        {--instance= : Limita la revisión a una instancia}';

    protected $description = 'Une hilos duplicados del mismo cliente y normaliza los números guardados';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? '<comment>MODO REAL: se van a modificar datos.</comment>'
            : '<info>Simulación. Añade --apply para ejecutar los cambios.</info>');
        $this->newLine();

        $merged = $this->mergeDuplicates($apply);
        $normalized = $this->normalizeRemaining($apply);

        $this->newLine();
        $this->info(sprintf(
            '%s: %d hilos unidos, %d números normalizados.',
            $apply ? 'Aplicado' : 'Se aplicaría',
            $merged,
            $normalized
        ));

        if (! $apply && ($merged || $normalized)) {
            $this->newLine();
            $this->line('Para ejecutarlo: <comment>php artisan whatsapp:fix-duplicate-conversations --apply</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * Grupos de hilos de una misma instancia cuyos números, ya normalizados,
     * son el mismo. Se conserva uno y los demás se vuelcan en él.
     */
    private function mergeDuplicates(bool $apply): int
    {
        $groups = DB::table('whatsapp_conversations')
            ->selectRaw("instance_id, REGEXP_REPLACE(wa_id, '[^0-9]', '') as digits, COUNT(*) as total")
            ->when($this->option('instance'), fn ($q, $id) => $q->where('instance_id', $id))
            ->groupBy('instance_id', 'digits')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->line('No hay hilos duplicados por variantes del número.');
            return 0;
        }

        $this->line("Hilos duplicados: {$groups->count()} grupo(s).");
        $mergedCount = 0;

        foreach ($groups as $group) {
            $conversations = WhatsAppConversation::where('instance_id', $group->instance_id)
                ->whereRaw("REGEXP_REPLACE(wa_id, '[^0-9]', '') = ?", [$group->digits])
                ->withCount('messages')
                ->get();

            $survivor = $this->pickSurvivor($conversations);
            $losers = $conversations->reject(fn ($c) => $c->id === $survivor->id);

            $this->line(sprintf(
                '  instancia %s · %s → se conserva #%d (%d mensajes, CRM %s)',
                $group->instance_id,
                $group->digits,
                $survivor->id,
                $survivor->messages_count,
                $survivor->kanban_column_id ?? 'sin columna'
            ));

            foreach ($losers as $loser) {
                $this->line(sprintf(
                    '      absorbe #%d ("%s", %d mensajes)',
                    $loser->id,
                    $loser->wa_id,
                    $loser->messages_count
                ));

                if ($apply) {
                    $this->absorb($survivor, $loser);
                }

                $mergedCount++;
            }

            if ($apply) {
                $this->refreshSurvivor($survivor, $group->digits);
            }
        }

        return $mergedCount;
    }

    /**
     * Se queda el hilo que más contexto tiene: primero el que está en una columna
     * del CRM (es el que el equipo está mirando), luego el de más mensajes y, a
     * igualdad, el más antiguo.
     */
    private function pickSurvivor($conversations): WhatsAppConversation
    {
        return $conversations
            ->sortByDesc(fn ($c) => [
                $c->kanban_column_id ? 1 : 0,
                $c->messages_count,
                -$c->id,
            ])
            ->first();
    }

    private function absorb(WhatsAppConversation $survivor, WhatsAppConversation $loser): void
    {
        DB::transaction(function () use ($survivor, $loser) {
            WhatsAppMessage::where('conversation_id', $loser->id)
                ->update(['conversation_id' => $survivor->id]);

            // Las etiquetas del hilo absorbido se conservan, sin duplicar las que
            // el superviviente ya tenía. Se hace por la relación para no depender
            // del nombre de las columnas de la tabla pivote.
            $tagIds = $loser->tags()->pluck('tags.id')->all();

            if ($tagIds) {
                $survivor->tags()->syncWithoutDetaching($tagIds);
            }

            $loser->tags()->detach();

            $survivor->unread_count += $loser->unread_count;

            // El hilo absorbido puede traer datos que al superviviente le faltan.
            $survivor->assigned_to ??= $loser->assigned_to;
            $survivor->contact_id ??= $loser->contact_id;
            $survivor->kanban_column_id ??= $loser->kanban_column_id;

            if ($loser->last_message_at && (! $survivor->last_message_at || $loser->last_message_at->gt($survivor->last_message_at))) {
                $survivor->last_message_at = $loser->last_message_at;
                $survivor->last_message = $loser->last_message;
            }

            // Si el cliente escribió en cualquiera de los dos, el hilo está vivo.
            if ($loser->status === 'open') {
                $survivor->status = 'open';
            }

            $survivor->save();

            $loser->delete();
        });
    }

    /**
     * Deja el superviviente con el número canónico y su último mensaje al día.
     */
    private function refreshSurvivor(WhatsAppConversation $survivor, string $digits): void
    {
        $last = WhatsAppMessage::where('conversation_id', $survivor->id)
            ->where('is_internal', false)
            ->orderByDesc('created_at')
            ->first();

        $survivor->forceFill([
            'wa_id'           => $digits,
            'phone_number'    => $digits,
            'last_message'    => $last?->content ?: $survivor->last_message,
            'last_message_at' => $last?->created_at ?? $survivor->last_message_at,
        ])->save();
    }

    /**
     * Los hilos que no chocan con nadie solo necesitan que su número quede en
     * forma canónica, para que el webhook los encuentre a la primera.
     */
    private function normalizeRemaining(bool $apply): int
    {
        $pending = WhatsAppConversation::whereRaw("wa_id REGEXP '[^0-9]' OR phone_number REGEXP '[^0-9]'")
            ->when($this->option('instance'), fn ($q, $id) => $q->where('instance_id', $id))
            ->get(['id', 'instance_id', 'wa_id', 'phone_number']);

        if ($pending->isEmpty()) {
            $this->line('Todos los números guardados ya están en forma canónica.');
            return 0;
        }

        $this->newLine();
        $this->line("Números por normalizar: {$pending->count()}");

        foreach ($pending->take(10) as $conversation) {
            $this->line(sprintf(
                '  #%d "%s" → "%s"',
                $conversation->id,
                $conversation->wa_id,
                WhatsAppConversation::normalizePhone($conversation->wa_id)
            ));
        }

        if ($pending->count() > 10) {
            $this->line('  … y ' . ($pending->count() - 10) . ' más.');
        }

        if (! $apply) {
            return $pending->count();
        }

        $count = 0;

        foreach ($pending as $conversation) {
            $digits = WhatsAppConversation::normalizePhone($conversation->wa_id);

            if ($digits === '') {
                $this->warn("  #{$conversation->id} no tiene ningún dígito, se deja como está.");
                continue;
            }

            // El merge previo debería haber despejado los choques, pero si
            // aparece uno nuevo se salta en vez de romper el índice único.
            $clash = WhatsAppConversation::where('instance_id', $conversation->instance_id)
                ->where('wa_id', $digits)
                ->where('id', '!=', $conversation->id)
                ->exists();

            if ($clash) {
                $this->warn("  #{$conversation->id} choca con otro hilo ya normalizado, se salta.");
                continue;
            }

            DB::table('whatsapp_conversations')
                ->where('id', $conversation->id)
                ->update([
                    'wa_id'        => $digits,
                    'phone_number' => WhatsAppConversation::normalizePhone($conversation->phone_number) ?: $digits,
                ]);

            $count++;
        }

        return $count;
    }
}
