<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppCall extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_calls';

    protected $fillable = [
        'instance_id',
        'conversation_id',
        'wacid',
        'direction',
        'status',
        'from',
        'to',
        'offer_sdp',
        'answer_sdp',
        'handled_by',
        'started_at',
        'connected_at',
        'ended_at',
        'duration_seconds',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'connected_at' => 'datetime',
        'ended_at' => 'datetime',
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

    public function handledBy()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Marca la llamada como finalizada y calcula la duración si hubo conexión.
     */
    public function markEnded(string $status = 'completed'): void
    {
        $endedAt = now();
        $duration = $this->connected_at ? $this->connected_at->diffInSeconds($endedAt) : 0;

        $this->update([
            'status' => $status,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
        ]);
    }

    public function scopeForInstance($query, $instanceId)
    {
        return $query->where('instance_id', $instanceId);
    }
}
