<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppCallPermission extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_call_permissions';

    protected $fillable = [
        'instance_id',
        'conversation_id',
        'wa_id',
        'status',
        'expires_at',
        'requested_at',
        'responded_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    /**
     * ¿El permiso está concedido y aún dentro de su ventana de validez?
     * Si no hay fecha de expiración se considera vigente mientras esté "granted".
     */
    public function isActive(): bool
    {
        if ($this->status !== 'granted') {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Devuelve el permiso vigente (concedido y no expirado) para un contacto,
     * o null si el negocio aún no puede llamarlo.
     */
    public static function activeFor(int $instanceId, string $waId): ?self
    {
        $permission = static::where('instance_id', $instanceId)
            ->where('wa_id', $waId)
            ->first();

        return $permission && $permission->isActive() ? $permission : null;
    }
}
