<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'wamid',
        'reply_to_wamid',
        'type',
        'content',
        'media_id',
        'media_url',
        'media_mime_type',
        'filename',
        'direction',
        'is_internal',
        'mentions',
        'status',
        'sent_by',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'error_message',
        'error_code',
        'error_details',
        'retry_of_message_id',
        'retry_count',
        'last_retried_at',
        'last_retried_by',
        'metadata',
        'incoming_invoice_id',
        'incoming_contract_id',
        'incoming_payment_id',
        'incoming_company_nit',
        'template_id',
        'campaign_id'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_retried_at' => 'datetime',
        'retry_count' => 'integer',
        'metadata' => 'array',
        'mentions' => 'array',
        'is_internal' => 'boolean',
    ];

    /**
     * El chat necesita saber si puede ofrecer el adjunto sin conocer los
     * detalles de dónde quedó guardado el media_id.
     */
    protected $appends = ['media_available'];

    public function getMediaAvailableAttribute(): bool
    {
        return $this->isMediaAvailable();
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isFromCustomer()
    {
        return $this->direction === 'inbound';
    }

    public function hasMedia()
    {
        return in_array($this->type, ['image', 'document', 'audio', 'video']);
    }

    /**
     * media_id de Meta con el que todavía se puede recuperar el archivo cuando
     * el mensaje no tiene una copia propia (`media_url` vacío).
     *
     * Los mensajes salientes llegan por varios caminos (webhook, API externa,
     * envío de plantilla) y cada uno deja el id en un sitio distinto, así que
     * los revisamos todos antes de dar el archivo por perdido.
     */
    public function resolvableMediaId(): ?string
    {
        $candidates = [
            $this->media_id,
            $this->metadata['header_media_id'] ?? null,
            $this->metadata['media_id'] ?? null,
        ];

        foreach ($this->metadata['components'] ?? [] as $component) {
            foreach ($component['parameters'] ?? [] as $param) {
                $mediaKey = $param['type'] ?? '';
                if (in_array($mediaKey, ['document', 'image', 'video'], true)) {
                    $candidates[] = $param[$mediaKey]['id'] ?? null;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (! empty($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Nombre original del adjunto declarado por quien envió el mensaje, antes
     * de caer en el nombre aleatorio con el que se guarda la copia en S3.
     */
    public function resolvableFilename(): ?string
    {
        $candidates = [
            $this->filename,
            $this->metadata['filename'] ?? null,
        ];

        foreach ($this->metadata['components'] ?? [] as $component) {
            foreach ($component['parameters'] ?? [] as $param) {
                $mediaKey = $param['type'] ?? '';
                if (in_array($mediaKey, ['document', 'image', 'video'], true)) {
                    $candidates[] = $param[$mediaKey]['filename'] ?? null;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (! empty($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Payload que Meta exige para enviar una plantilla: nombre, idioma y las
     * variables. Devuelve null cuando el mensaje no lo guarda, y entonces la
     * plantilla no se puede (re)enviar desde aquí.
     *
     * No todos los productores dejan lo mismo: el chat y el envío por API sí
     * guardan el payload completo, pero `/api/messages/register` lo acepta vacío
     * porque el sistema externo ya envió la plantilla por su cuenta y solo nos
     * pide dejar constancia. De esos mensajes lo único que queda del nombre es
     * el texto de la burbuja, "[Plantilla: nombre]".
     */
    public function templatePayload(): ?array
    {
        $metadata = $this->metadata ?? [];

        $name = $metadata['template']
            ?? $metadata['template_name']
            ?? $metadata['name']
            ?? null;

        $reconstructed = false;

        if (! $name && preg_match('/^\[\s*Plantilla:\s*(.+?)\s*\]$/u', (string) $this->content, $matches)) {
            $name = $matches[1];
            $reconstructed = true;
        }

        if (! $name) {
            return null;
        }

        return [
            'name'          => (string) $name,
            'language'      => (string) ($metadata['language'] ?? 'es'),
            'components'    => $metadata['components'] ?? [],
            // El nombre salió de la burbuja, no del payload: las variables que
            // llevaba la plantilla original se perdieron.
            'reconstructed' => $reconstructed,
        ];
    }

    /**
     * ¿El chat puede ofrecer el archivo? Hay copia propia o todavía queda el
     * media_id para pedírselo a Meta.
     */
    public function isMediaAvailable(): bool
    {
        return ! empty($this->media_url) || $this->resolvableMediaId() !== null;
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Tipos que `DeliverWhatsAppMessage` sabe volver a poner en el aire. El
     * resto (sticker, location, contacts…) llega por webhook y no se reenvía.
     */
    public const RETRYABLE_TYPES = ['text', 'image', 'audio', 'document', 'template'];

    /**
     * Minutos que un saliente puede quedarse en "pending" antes de considerarlo
     * atascado (cola caída, worker muerto) y no simplemente en vuelo.
     */
    public const PENDING_STUCK_MINUTES = 5;

    /**
     * Horas que un "sent" puede pasar sin que Meta confirme la entrega antes de
     * tratarlo como no entregado (teléfono apagado, número inexistente…).
     */
    public const SENT_UNCONFIRMED_HOURS = 1;

    /**
     * El mensaje original del que este es un reintento.
     */
    public function retryOf()
    {
        return $this->belongsTo(self::class, 'retry_of_message_id');
    }

    /**
     * Reintentos generados a partir de este mensaje.
     */
    public function retries()
    {
        return $this->hasMany(self::class, 'retry_of_message_id');
    }

    public function retriedBy()
    {
        return $this->belongsTo(User::class, 'last_retried_by');
    }

    /**
     * Salientes reales (no notas internas) que el cliente nunca recibió:
     * fallidos, atascados en cola, o aceptados por Meta sin confirmación.
     */
    public function scopeUndelivered($query)
    {
        // Columnas calificadas: el panel Master cruza esta consulta con
        // `whatsapp_conversations` e `instances`, que también tienen `status`.
        return $query
            ->where('whatsapp_messages.direction', 'outbound')
            ->where('whatsapp_messages.type', '!=', 'note')
            ->where(function ($q) {
                $q->where('whatsapp_messages.status', 'failed')
                    ->orWhere(function ($q2) {
                        $q2->where('whatsapp_messages.status', 'pending')
                            ->where('whatsapp_messages.created_at', '<=', now()->subMinutes(self::PENDING_STUCK_MINUTES));
                    })
                    ->orWhere(function ($q2) {
                        $q2->where('whatsapp_messages.status', 'sent')
                            ->where('whatsapp_messages.created_at', '<=', now()->subHours(self::SENT_UNCONFIRMED_HOURS));
                    });
            });
    }

    /**
     * Momento en el que el mensaje se dio por no entregado. `failed_at` solo
     * existe en fallos; para los atascados el hito es cuando se creó.
     */
    public function getFailureMomentAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->failed_at ?? $this->sent_at ?? $this->created_at;
    }
}
