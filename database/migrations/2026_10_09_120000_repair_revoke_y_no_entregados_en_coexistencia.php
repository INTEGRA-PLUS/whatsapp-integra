<?php

use App\Support\MensajeNoEntregado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescribe los avisos de «mensaje no entregado» con el texto de hoy.
 *
 * Migración de datos, no de esquema. El 9-oct-2026 el chat de River enseñaba:
 *
 *  - «El cliente envió un mensaje (revoke). … Pídele que lo reenvíe» cuando el
 *    cliente había BORRADO un mensaje. Ahora: «El cliente eliminó un mensaje.»
 *  - «El cliente envió un mensaje (unknown). … Pídele que lo reenvíe» para un
 *    video que el asesor tenía delante en el celular. En coexistencia el
 *    mensaje está en la app del negocio, y ahora se dice.
 *
 * Sólo se tocan filas que guardaron el payload de Meta (`metadata.unhandled`):
 * de ahí se vuelve a calcular la frase igual que lo haría el webhook hoy. Las
 * que no lo guardaron se dejan como están.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enCoexistencia = $this->instanciasEnCoexistencia();
        $hilos = [];

        DB::table('whatsapp_messages')
            ->where('type', 'system')
            // Por ruta JSON y no con LIKE: MySQL normaliza la columna con
            // espacios (`"no_entregado": true`) y el LIKE no encontraba nada.
            ->where('metadata->no_entregado', true)
            ->orderBy('id')
            ->chunkById(500, function ($filas) use ($enCoexistencia, &$hilos) {
                $instancias = DB::table('whatsapp_conversations')
                    ->whereIn('id', $filas->pluck('conversation_id')->unique())
                    ->pluck('instance_id', 'id');

                foreach ($filas as $fila) {
                    $metadata = json_decode($fila->metadata ?? 'null', true);
                    $payload = $metadata['unhandled'] ?? null;

                    if (! is_array($payload)) {
                        continue;
                    }

                    $delHistorial = (bool) ($metadata['del_historial'] ?? false);
                    $columnas = MensajeNoEntregado::columnas(
                        $payload,
                        $fila->direction === 'outbound',
                        $delHistorial,
                        ! $delHistorial && isset($enCoexistencia[$instancias[$fila->conversation_id] ?? 0])
                    );

                    if ($columnas['content'] === $fila->content) {
                        continue;
                    }

                    DB::table('whatsapp_messages')->where('id', $fila->id)->update([
                        'content' => $columnas['content'],
                        'metadata' => json_encode($columnas['metadata'], JSON_UNESCAPED_UNICODE),
                    ]);

                    $hilos[$fila->conversation_id] = true;
                }
            });

        $this->repararVistasPrevias(array_keys($hilos));
    }

    /** Ids de instancia cuyo número vive también en la app del celular. */
    private function instanciasEnCoexistencia(): array
    {
        $ids = DB::table('coexistence_syncs')->pluck('instance_id')
            ->merge(
                DB::table('instances')->where('meta->plataforma->coexistencia', true)->pluck('id')
            );

        return array_fill_keys($ids->all(), true);
    }

    /** `last_message` es una copia: se recalcula en los hilos tocados. */
    private function repararVistasPrevias(array $hilos): void
    {
        foreach ($hilos as $id) {
            $ultimo = DB::table('whatsapp_messages')
                ->where('conversation_id', $id)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first(['content', 'type', 'metadata']);

            if (! $ultimo) {
                continue;
            }

            $metadata = json_decode($ultimo->metadata ?? 'null', true);
            $texto = $metadata['resumen'] ?? $ultimo->content;

            DB::table('whatsapp_conversations')->where('id', $id)->update([
                'last_message' => ($ultimo->type === 'system' ? 'ℹ️ ' : '').($texto ?: 'Archivo adjunto'),
            ]);
        }
    }

    public function down(): void
    {
        // El texto anterior decía lo contrario de lo que pasó: no se restaura.
    }
};
