<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La captura de un pago, leída y esperando aprobación.
 *
 * **Nunca registra nada sola.** El modelo de visión sólo propone monto, fecha y
 * referencia; el pago en Integra lo registra una persona con `pagos.aprobar`.
 * Registrar un pago reconecta al cliente en el router en el acto, y un
 * comprobante editado se lee igual que uno real.
 */
class ComprobanteDePago extends Model
{
    protected $table = 'comprobantes_de_pago';

    public const PENDIENTE = 'pendiente';

    /** Reservado mientras se habla con Integra: es lo que impide el doble pago. */
    public const APROBANDO = 'aprobando';

    public const APROBADO = 'aprobado';

    public const RECHAZADO = 'rechazado';

    /** Integra no respondió a tiempo: el pago pudo quedar registrado o no. */
    public const REVISAR = 'revisar';

    /** Desde dónde se puede aprobar o rechazar. */
    public const ABIERTOS = [self::PENDIENTE, self::REVISAR];

    protected $fillable = [
        'company_id', 'instance_id', 'conversation_id', 'whatsapp_message_id',
        'estado', 'lectura', 'monto', 'fecha', 'referencia', 'referencia_normalizada', 'banco', 'destino',
        'imagen_sha256', 'duplicado_de_id',
        'factura_id', 'factura_codigo', 'monto_aprobado', 'resultado', 'error',
        'revisado_por', 'revisado_at', 'motivo_rechazo',
    ];

    protected $casts = [
        'lectura' => 'array',
        'resultado' => 'array',
        'monto' => 'float',
        'monto_aprobado' => 'float',
        'fecha' => 'date:Y-m-d',
        'revisado_at' => 'datetime',
    ];

    protected $hidden = ['imagen_sha256', 'lectura', 'referencia_normalizada'];

    public function mensaje()
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    public function conversacion()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function revisor()
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function duplicadoDe()
    {
        return $this->belongsTo(self::class, 'duplicado_de_id');
    }

    public function estaAbierto(): bool
    {
        return in_array($this->estado, self::ABIERTOS, true);
    }

    /**
     * La referencia tal como se compara: sin espacios, guiones ni ceros a la
     * izquierda, en mayúsculas. «00123-456» y «123456» son el mismo pago.
     */
    public static function referenciaComparable(?string $referencia): ?string
    {
        $limpia = ltrim(strtoupper(preg_replace('/[\s\-\.]+/', '', (string) $referencia)), '0');

        return $limpia === '' ? null : $limpia;
    }
}
