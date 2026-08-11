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
        'contact_id',
        'wa_id',
        'phone_number',
        'name',
        'profile_pic_url',
        'last_message',
        'last_message_at',
        'status',
        'kanban_column_id',
        'assigned_to',
        'unread_count',
        'metadata'
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['initials'];

    /**
     * Forma canónica de un teléfono: solo dígitos.
     *
     * Meta siempre manda el número limpio, pero los demás caminos (la API de
     * Integra, un agente escribiendo a mano, una importación) aceptaban lo que
     * llegara: "57300 825 3303", "57 +1 (386) 7957322", "1-5993172978920".
     */
    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }

    /**
     * Hilo de una instancia para un teléfono, creándolo si no existe.
     *
     * Sustituye al `firstOrCreate(['wa_id' => $phone])` que había repartido por
     * media docena de sitios. Ese patrón partía en dos la conversación de un
     * mismo cliente: si el hilo se creó con el número escrito de una forma y el
     * webhook llegaba con otra, el mensaje entrante abría un hilo nuevo y la
     * respuesta del cliente quedaba invisible en el chat y en el CRM.
     */
    public static function resolveFor(int $instanceId, ?string $phone, array $defaults = []): self
    {
        $digits = self::normalizePhone($phone);

        if ($digits === '') {
            // Nada que normalizar: se conserva el comportamiento antiguo para no
            // perder el mensaje, pero queda el rastro de quién manda basura.
            \Illuminate\Support\Facades\Log::warning('Teléfono sin dígitos al resolver conversación', [
                'instance_id' => $instanceId,
                'phone'       => $phone,
            ]);
            $digits = (string) $phone;
        }

        $conversation = static::where('instance_id', $instanceId)->where('wa_id', $digits)->first();

        if ($conversation) {
            return $conversation;
        }

        // Esto corre dentro del webhook: si la búsqueda de variantes falla por
        // cualquier motivo, es preferible abrir un hilo nuevo que perder el
        // mensaje del cliente.
        try {
            if ($variant = self::findVariant($instanceId, $digits)) {
                return $variant;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo buscar variantes del número', [
                'instance_id' => $instanceId,
                'error'       => $e->getMessage(),
            ]);
        }

        try {
            return static::create(array_merge($defaults, [
                'instance_id'  => $instanceId,
                'wa_id'        => $digits,
                'phone_number' => $defaults['phone_number'] ?? $digits,
            ]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Un lote del webhook puede traer dos mensajes del mismo número nuevo
            // y competir por el índice único (instance_id, wa_id).
            return static::where('instance_id', $instanceId)->where('wa_id', $digits)->firstOrFail();
        }
    }

    /**
     * Hilo ya existente del mismo abonado escrito de otra forma: con espacios,
     * sin indicativo o con el indicativo repetido ("57573012146547").
     *
     * Se busca por los últimos 10 dígitos —que en la práctica identifican al
     * abonado— y se exige además que un número sea sufijo del otro, para no unir
     * jamás dos clientes distintos que casualmente terminen igual.
     */
    private static function findVariant(int $instanceId, string $digits): ?self
    {
        if (strlen($digits) < 10) {
            return null;
        }

        $candidates = static::where('instance_id', $instanceId)
            ->whereRaw("REGEXP_REPLACE(wa_id, '[^0-9]', '') LIKE ?", ['%' . substr($digits, -10)])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();

        foreach ($candidates as $candidate) {
            $candidateDigits = self::normalizePhone($candidate->wa_id);

            if ($candidateDigits === '') {
                continue;
            }

            if (str_ends_with($candidateDigits, $digits) || str_ends_with($digits, $candidateDigits)) {
                return $candidate;
            }
        }

        return null;
    }

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

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function kanbanColumn()
    {
        return $this->belongsTo(\App\Models\KanbanColumn::class, 'kanban_column_id');
    }

    /**
     * The tags that belong to the conversation.
     */
    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'whatsapp_conversation_tag', 'whatsapp_conversation_id', 'tag_id');
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
        $name = $this->name ?? $this->phone_number ?? 'U';
        $words = explode(' ', $name);
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Ventana de servicio de 24h de Meta: solo se puede enviar texto/adjuntos
     * libres si el cliente escribió en las últimas 24h. Fuera de esa ventana
     * hay que reabrir con una plantilla aprobada.
     */
    public function isWindowOpen(): bool
    {
        return $this->messages()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    public function scopeForInstance($query, $instanceId)
    {
        return $query->where('instance_id', $instanceId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('phone_number', 'like', "%{$search}%")
              ->orWhere('last_message', 'like', "%{$search}%");
        });
    }
}
