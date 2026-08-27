<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Menú que la conversación tiene delante ahora mismo.
 *
 * Sólo sirve para entender al cliente que escribe en vez de tocar: "1", "dos",
 * o el título de la opción. El toque en el botón se resuelve por el id que
 * vuelve en el webhook y no necesita esta tabla.
 */
class WhatsAppMenuSession extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_menu_sessions';

    protected $fillable = [
        'conversation_id',
        'menu_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function menu()
    {
        return $this->belongsTo(WhatsAppMenu::class, 'menu_id');
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Deja constancia de que la conversación está esperando este menú. */
    public static function open(int $conversationId, WhatsAppMenu $menu): self
    {
        return self::updateOrCreate(
            ['conversation_id' => $conversationId],
            ['menu_id' => $menu->id, 'expires_at' => now()->addMinutes(WhatsAppMenu::SESSION_MINUTES)]
        );
    }

    public static function close(int $conversationId): void
    {
        self::where('conversation_id', $conversationId)->delete();
    }
}
