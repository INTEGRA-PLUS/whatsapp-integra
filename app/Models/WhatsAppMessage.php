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
        'error_message',
        'error_code',
        'error_details',
        'metadata',
        'incoming_invoice_id',
        'incoming_contract_id',
        'incoming_payment_id',
        'incoming_company_nit',
        'template_id'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
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
}
