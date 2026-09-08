<?php

use App\Support\MensajeNoEntregado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reescribe los mensajes que el chat mostraba como "Mensaje no compatible (X)".
 *
 * Son dos poblaciones distintas y se arreglan distinto:
 *
 *  - 7 filas recientes (desde el 6-ago) SÍ guardaron el payload de Meta en
 *    `metadata.unhandled`. De ahí sale el contenido real: una respuesta a un
 *    botón de plantilla se recupera entera; los `errors` (código 131051) no,
 *    porque Meta nunca manda el contenido, pero al menos se explican bien.
 *  - ~2.100 filas anteriores no guardaron nada: el código de entonces
 *    descartaba el payload. Su contenido no existe en ninguna parte y no hay
 *    forma de recuperarlo. Lo único que se puede hacer es cambiar un texto que
 *    no dice nada por uno que sí: qué mandó el cliente y por qué no está.
 *
 * Las reacciones antiguas sí se quitan, y son la mayoría (991 de 2.111). Una
 * reacción nunca debió ser una fila: hoy el webhook le cuelga el emoji al
 * mensaje al que apunta. Las viejas no guardaron ni el emoji ni a qué mensaje
 * apuntaban, así que no queda nada que colgar ni nada que contar: sólo ruido
 * en mitad de la conversación. Es irreversible y está aprobado.
 */
return new class extends Migration
{
    /** Dónde queda la copia de las filas que se quitan. Se puede tirar cuando sobre. */
    private const RESPALDO = 'whatsapp_messages_retirados_2026_09';

    /** Lo que era cada tipo, para las filas viejas donde sólo queda el nombre. */
    private const LEGADO = [
        'sticker'     => 'un sticker',
        'reaction'    => 'una reacción',
        'contacts'    => 'un contacto',
        'location'    => 'una ubicación',
        'button'      => 'la respuesta a un botón',
        'interactive' => 'una respuesta a un menú',
        'order'       => 'un pedido del catálogo',
    ];

    public function up(): void
    {
        $this->quitarReaccionesHuerfanas();

        DB::table('whatsapp_messages')
            ->where('content', 'like', 'Mensaje no compatible (%')
            ->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $fila) {
                    $cambios = $this->reparar($fila);

                    if ($cambios) {
                        DB::table('whatsapp_messages')->where('id', $fila->id)->update($cambios);
                    }
                }
            });

        // La lista de chats guarda su propia copia del último mensaje.
        $this->repararVistasPrevias();
    }

    /**
     * Retira las reacciones que quedaron como burbuja suelta en el hilo.
     *
     * Sólo las que no guardaron payload: si lo guardaron hay emoji y mensaje de
     * destino, y eso se aprovecha en vez de tirarlo. Va en tandas para no
     * bloquear la tabla entera mientras el CRM está atendiendo.
     *
     * Antes de quitar nada se deja una copia en `whatsapp_messages_retirados_2026_09`.
     * Las filas no valen nada —ése es justo el motivo de quitarlas— pero borrar
     * mil mensajes de producción sin red es otra cosa. La tabla se puede tirar
     * cuando se haya visto que el chat quedó bien.
     */
    private function quitarReaccionesHuerfanas(): void
    {
        $this->respaldar();

        do {
            // Los ids se resuelven aparte a propósito: `DELETE ... LIMIT` no
            // existe en SQLite y la suite corre sobre SQLite.
            $ids = DB::table('whatsapp_messages')
                ->where('content', 'Mensaje no compatible (reaction)')
                ->whereNull('metadata')
                ->limit(500)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('whatsapp_messages')->whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());
    }

    /** La copia de seguridad de lo que se va a quitar. */
    private function respaldar(): void
    {
        if (Schema::hasTable(self::RESPALDO)) {
            return;
        }

        $filas = DB::table('whatsapp_messages')
            ->where('content', 'Mensaje no compatible (reaction)')
            ->whereNull('metadata')
            ->get();

        if ($filas->isEmpty()) {
            return;
        }

        Schema::create(self::RESPALDO, function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('id')->primary();
            $tabla->unsignedBigInteger('conversation_id')->nullable();
            $tabla->string('wamid', 500)->nullable();
            $tabla->string('direction', 20)->nullable();
            $tabla->timestamp('sent_at')->nullable();
            $tabla->timestamp('created_at')->nullable();
        });

        foreach ($filas->chunk(500) as $tanda) {
            DB::table(self::RESPALDO)->insert($tanda->map(fn ($f) => [
                'id'              => $f->id,
                'conversation_id' => $f->conversation_id,
                'wamid'           => $f->wamid,
                'direction'       => $f->direction,
                'sent_at'         => $f->sent_at,
                'created_at'      => $f->created_at,
            ])->all());
        }
    }

    private function reparar(object $fila): array
    {
        $saliente = $fila->direction === 'outbound';
        $metadata = json_decode($fila->metadata ?? 'null', true);
        $payload  = $metadata['unhandled'] ?? null;

        // Caso 1: el payload está. Se reinterpreta como lo haría el código de hoy.
        if (is_array($payload)) {
            $tipo = $payload['type'] ?? null;

            if ($tipo === 'button') {
                return [
                    'type'     => 'text',
                    'content'  => $payload['button']['text'] ?? $payload['button']['payload'] ?? 'Botón',
                    'metadata' => json_encode(['button' => $payload['button'] ?? []], JSON_UNESCAPED_UNICODE),
                ];
            }

            if ($tipo === 'interactive') {
                return [
                    'type'     => 'text',
                    'content'  => $payload['interactive']['button_reply']['title']
                        ?? $payload['interactive']['list_reply']['title']
                        ?? 'Respuesta interactiva',
                    'metadata' => json_encode(['interactive' => $payload['interactive'] ?? []], JSON_UNESCAPED_UNICODE),
                ];
            }

            // `history_context` sólo lo pone el volcado de coexistencia: es lo
            // que distingue "no llegó nunca" de "sigue en el celular".
            $columnas = MensajeNoEntregado::columnas(
                $payload,
                $saliente,
                isset($payload['history_context'])
            );

            return [
                'type'     => $columnas['type'],
                'content'  => $columnas['content'],
                'metadata' => json_encode($columnas['metadata'], JSON_UNESCAPED_UNICODE),
            ];
        }

        // Caso 2: sin payload. Sólo queda el nombre del tipo dentro del texto.
        if (!preg_match('/^Mensaje no compatible \((.+)\)$/', $fila->content, $m)) {
            return [];
        }

        $tipo  = $m[1];
        $quien = $saliente ? 'Enviaste' : 'El cliente envió';

        $texto = $tipo === 'unsupported' || $tipo === 'errors'
            ? "{$quien} un mensaje cuyo contenido WhatsApp no entrega a la API, así que no se puede mostrar."
            : sprintf(
                '%s %s. Llegó antes de que el CRM guardara este tipo de mensaje, así que su contenido no quedó registrado.',
                $quien,
                self::LEGADO[$tipo] ?? 'un mensaje que el CRM no supo interpretar'
            );

        return [
            'type'     => 'system',
            'content'  => $texto,
            'metadata' => json_encode([
                'no_entregado'  => true,
                'tipo_original' => $tipo,
                'sin_payload'   => true,
                'resumen'       => isset(self::LEGADO[$tipo])
                    ? MensajeNoEntregado::sinArticulo(self::LEGADO[$tipo]) . ' (sin contenido)'
                    : 'Mensaje sin contenido',
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Vuelve a calcular `last_message` en los hilos donde el último mensaje era
     * uno de los reparados: esa columna es una copia, no se recalcula sola.
     */
    private function repararVistasPrevias(): void
    {
        $hilos = DB::table('whatsapp_conversations')
            ->where('last_message', 'like', '%Mensaje no compatible (%')
            ->pluck('id');

        foreach ($hilos as $id) {
            $ultimo = DB::table('whatsapp_messages')
                ->where('conversation_id', $id)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first(['content', 'type', 'metadata']);

            if (!$ultimo) {
                continue;
            }

            $metadata = json_decode($ultimo->metadata ?? 'null', true);
            $texto    = $metadata['resumen'] ?? $ultimo->content;

            DB::table('whatsapp_conversations')->where('id', $id)->update([
                'last_message' => ($ultimo->type === 'system' ? 'ℹ️ ' : '') . ($texto ?: 'Archivo adjunto'),
            ]);
        }
    }

    public function down(): void
    {
        // El texto original ("Mensaje no compatible (X)") tampoco era el
        // contenido real del mensaje: no hay nada que valga la pena restaurar.
    }
};
