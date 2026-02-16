<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppConversation extends Model
{
    use HasFactory;
    
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'instance_id',
        'wa_id',
        'phone_number',
        'name',
        'profile_pic_url',
        'last_message',
        'last_message_at',
        'status',
        'assigned_to',
        'unread_count',
        'metadata'
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['initials'];

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function markAsRead()
    {
        $this->update(['unread_count' => 0]);
    }

    public function incrementUnread()
    {
        $this->increment('unread_count');
    }

    public function getInitialsAttribute()
    {
        $words = explode(' ', $this->name ?? 'U');
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }
        return strtoupper(substr($words[0], 0, 2));
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeForInstance($query, $instanceId)
    {
        return $query->where('instance_id', $instanceId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('phone_number', 'like', "%{$search}%");
        });
    }
}
