<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;

/**
 * Deja el globo verde de la bandeja diciendo la verdad.
 *
 * `unread_count` sólo sabía subir —con cada entrante— y bajar en un único sitio:
 * al abrir el chat en el CRM. En un número en coexistencia el asesor contesta
 * desde la app del celular y no abre el CRM jamás, así que el contador crecía
 * para siempre: el 19-sep-2026 Transinternet tenía **783 conversaciones con el
 * globo encendido cuyo último mensaje era una respuesta del propio asesor**,
 * 3.694 "sin leer" que no existían. Desde la bandeja parecían clientes
 * abandonados; al abrirlos estaban atendidos.
 *
 * El eco del celular ya apaga el contador desde entonces
 * (`CoexistenceIngestService`), pero eso sólo vale para lo que entre a partir de
 * ahora. Esto repara lo que quedó atrás.
 *
 * Qué cuenta como atendido: una respuesta **de una persona**. No lo son los
 * mensajes de campaña —una tanda masiva no atiende a nadie, y sin excluirla
 * apagaría de golpe los avisos de todos los chats con preguntas pendientes— ni
 * los del menú o la IA, que responden solos sin que nadie haya leído nada.
 *
 * Solo simula, salvo que se pase --apply.
 */
class RecalcularSinLeer extends Command
{
    protected $signature = 'whatsapp:recalcular-sin-leer
        {--apply : Aplica los cambios (sin esta opción solo se simula)}
        {--company= : Limita la revisión a una empresa}';

    protected $description = 'Recalcula el contador de mensajes sin leer de las conversaciones abiertas';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? '<comment>MODO REAL: se van a modificar datos.</comment>'
            : '<info>Simulación. Añade --apply para ejecutar los cambios.</info>');
        $this->newLine();

        $conversaciones = WhatsAppConversation::query()
            ->where('status', 'open')
            ->when($this->option('company'), function ($q, $companyId) {
                $q->whereIn('instance_id', Instance::where('company_id', $companyId)->pluck('id'));
            })
            ->pluck('id');

        $this->line("Conversaciones abiertas a revisar: {$conversaciones->count()}");

        $corregidas = 0;
        $globosApagados = 0;
        $barra = $this->output->createProgressBar($conversaciones->count());

        foreach ($conversaciones->chunk(500) as $lote) {
            $actuales = WhatsAppConversation::whereIn('id', $lote)->pluck('unread_count', 'id');
            $atendidas = $this->ultimaRespuestaHumana($lote->all());

            foreach ($lote as $id) {
                $real = $this->sinLeerDe($id, $atendidas[$id] ?? null);
                $actual = (int) ($actuales[$id] ?? 0);

                if ($real !== $actual) {
                    $corregidas++;
                    $globosApagados += max(0, $actual - $real);

                    if ($apply) {
                        WhatsAppConversation::where('id', $id)->update(['unread_count' => $real]);
                    }
                }

                $barra->advance();
            }
        }

        $barra->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s: %d conversaciones corregidas, %d "sin leer" fantasma retirados.',
            $apply ? 'Aplicado' : 'Se aplicaría',
            $corregidas,
            $globosApagados
        ));

        if (! $apply && $corregidas) {
            $this->newLine();
            $this->line('Para ejecutarlo: <comment>php artisan whatsapp:recalcular-sin-leer --apply</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * El id de la última respuesta de una persona, por conversación.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function ultimaRespuestaHumana(array $ids): array
    {
        return WhatsAppMessage::whereIn('conversation_id', $ids)
            ->where('direction', 'outbound')
            ->where('is_internal', false)
            ->where('type', '!=', 'system')
            // Una tanda masiva no atiende a nadie.
            ->whereNull('campaign_id')
            // Ni el menú ni la IA: contestan solos, sin que nadie haya leído.
            ->whereRaw("JSON_EXTRACT(metadata, '$.ia') IS NULL")
            ->whereRaw("JSON_EXTRACT(metadata, '$.menu_id') IS NULL")
            ->selectRaw('conversation_id, MAX(id) as ultimo')
            ->groupBy('conversation_id')
            ->pluck('ultimo', 'conversation_id')
            ->all();
    }

    /** Lo que el cliente escribió después de esa respuesta. */
    private function sinLeerDe(int $conversationId, ?int $desde): int
    {
        return WhatsAppMessage::where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->where('is_internal', false)
            // Un aviso del sistema no es el cliente pidiendo nada.
            ->where('type', '!=', 'system')
            ->when($desde, fn ($q) => $q->where('id', '>', $desde))
            ->count();
    }
}
