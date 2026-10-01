<?php

namespace App\Jobs;

use App\Events\WhatsAppMessageEvent;
use App\Models\CompanyIntegration;
use App\Models\ComprobanteDePago;
use App\Models\WhatsAppMessage;
use App\Support\Documentos\ImagenDelCliente;
use App\Support\Pagos\LectorDeComprobante;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Mira la foto que acaba de entrar y, si es la captura de un pago, la deja
 * pendiente de aprobación con el monto, la fecha y la referencia ya leídos.
 *
 * **No registra ningún pago ni le contesta al cliente.** Sólo prepara el
 * trabajo del asesor: el pago lo registra una persona con `pagos.aprobar`,
 * desde el chat.
 *
 * Corre aparte del chat IA y aunque haya un asesor asignado: el comprobante
 * hay que aprobarlo igual, y es justo el asesor quien lo aprueba.
 */
class LeerComprobanteDePago implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un segundo intento sólo cubre un tropiezo del modelo; más sería pagarlo dos veces por nada. */
    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout;

    public function __construct(public int $messageId)
    {
        $this->timeout = (int) config('services.vision.timeout', 90) + 60;
    }

    /** ¿Tiene sentido encolarlo para este mensaje? Lo decide el webhook. */
    public static function aplica(WhatsAppMessage $mensaje, int $companyId): bool
    {
        return $mensaje->type === 'image'
            && $mensaje->direction === 'inbound'
            && trim((string) $mensaje->media_url) !== ''
            && ImagenDelCliente::configurado()
            && CompanyIntegration::leeComprobantesDe($companyId);
    }

    public function handle(): void
    {
        $mensaje = WhatsAppMessage::with('conversation.instance')->find($this->messageId);
        $instance = $mensaje?->conversation?->instance;

        if (! $mensaje || ! $instance) {
            return;
        }

        // Idempotente: el webhook reintenta y este job también.
        if (ComprobanteDePago::where('whatsapp_message_id', $mensaje->id)->exists()) {
            return;
        }

        // Se vuelve a mirar aquí y no sólo al encolar: la empresa pudo apagarlo
        // mientras esperaba en la cola.
        if (! CompanyIntegration::leeComprobantesDe($instance->company_id)) {
            return;
        }

        $bytes = ImagenDelCliente::descargar((string) $mensaje->media_url);

        if ($bytes === null) {
            return;
        }

        $lectura = LectorDeComprobante::leer($bytes);

        if ($lectura === null) {
            // Que lo reintente la cola: el modelo no respondió.
            throw new \RuntimeException('El modelo de visión no pudo leer la imagen.');
        }

        if (! $lectura['es_comprobante']) {
            return;
        }

        $companyId = $instance->company_id;
        $hash = hash('sha256', $bytes);
        $referencia = ComprobanteDePago::referenciaComparable($lectura['referencia']);

        // La misma captura, o el mismo número de referencia, ya llegó antes.
        // No se bloquea aquí —puede ser el cliente reenviándola porque nadie le
        // contestó—: se marca, y el aprobar sí se niega si la anterior ya se
        // aprobó.
        $anterior = ComprobanteDePago::where('company_id', $companyId)
            ->where(function ($q) use ($hash, $referencia) {
                $q->where('imagen_sha256', $hash);
                if ($referencia !== null) {
                    $q->orWhere('referencia_normalizada', $referencia);
                }
            })
            ->orderBy('id')
            ->first();

        try {
            $comprobante = ComprobanteDePago::create([
                'company_id' => $companyId,
                'instance_id' => $instance->id,
                'conversation_id' => $mensaje->conversation_id,
                'whatsapp_message_id' => $mensaje->id,
                'estado' => ComprobanteDePago::PENDIENTE,
                'lectura' => $lectura,
                'monto' => $lectura['monto'],
                'fecha' => $lectura['fecha'],
                'referencia' => $lectura['referencia'],
                'referencia_normalizada' => $referencia,
                'banco' => $lectura['banco'],
                'destino' => $lectura['destino'],
                'imagen_sha256' => $hash,
                'duplicado_de_id' => $anterior?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return; // Otro intento llegó primero.
        }

        Log::channel('whatsapp')->info('🧾 Comprobante de pago pendiente de aprobación', [
            'comprobante_id' => $comprobante->id,
            'conversation_id' => $mensaje->conversation_id,
            'monto' => $comprobante->monto,
            'duplicado_de' => $anterior?->id,
        ]);

        // Que la tarjeta aparezca bajo la foto sin recargar el chat.
        try {
            broadcast(new WhatsAppMessageEvent(
                $mensaje->load(['sender', 'comprobanteDePago']),
                $instance->id,
                'edited'
            ));
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo avisar del comprobante en tiempo real', [
                'comprobante_id' => $comprobante->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
