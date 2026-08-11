<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AutoResponse extends Model
{
    use HasFactory;

    public const KEYWORD_TYPES = ['exact', 'contains', 'starts_with'];
    public const TRIGGERLESS_TYPES = ['always', 'welcome', 'reopen'];

    public const DEFAULT_REOPEN_HOURS = 24;

    protected $fillable = [
        'company_id',
        'instance_id',
        'name',
        'trigger_text',
        'match_type',
        'match_types',
        'response_message',
        'active',
        'cooldown_minutes',
        'reopen_hours',
        'fires_count',
        'last_fired_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'cooldown_minutes' => 'integer',
        'reopen_hours' => 'integer',
        'match_types' => 'array',
        'fires_count' => 'integer',
        'last_fired_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Tipos de coincidencia configurados para esta regla.
     * Usa la columna nueva match_types y cae al match_type legacy si está vacía.
     */
    public function matchTypes(): array
    {
        $types = $this->match_types;

        if (empty($types)) {
            return $this->match_type ? [$this->match_type] : [];
        }

        return array_values(array_unique($types));
    }

    /**
     * Prioridad de la regla (menor = más prioritaria) cuando varias podrían disparar.
     * welcome/reopen primero, luego palabra clave, y "always" de último.
     */
    public function priority(): int
    {
        $map = ['welcome' => 0, 'reopen' => 0, 'exact' => 1, 'contains' => 1, 'starts_with' => 1, 'always' => 2];
        $best = 2;

        foreach ($this->matchTypes() as $type) {
            $best = min($best, $map[$type] ?? 1);
        }

        return $best;
    }

    /**
     * ¿La regla aplica? Lógica OR: basta con que CUALQUIER tipo configurado se cumpla.
     *
     * @param array{is_first_inbound?: bool, gap_minutes?: int|null} $context
     */
    public function qualifies(string $incoming, array $context = []): bool
    {
        foreach ($this->matchTypes() as $type) {
            if ($this->typeQualifies($type, $incoming, $context)) {
                return true;
            }
        }

        return false;
    }

    private function typeQualifies(string $type, string $incoming, array $context): bool
    {
        return match ($type) {
            'always' => true,
            'welcome' => (bool) ($context['is_first_inbound'] ?? false),
            'reopen' => $this->reopenQualifies($context['gap_minutes'] ?? null),
            'exact', 'contains', 'starts_with' => $this->keywordMatches($type, $incoming),
            default => false,
        };
    }

    private function reopenQualifies(?int $gapMinutes): bool
    {
        if ($gapMinutes === null) {
            return false;
        }

        $thresholdHours = $this->reopen_hours ?: self::DEFAULT_REOPEN_HOURS;

        return $gapMinutes >= $thresholdHours * 60;
    }

    /**
     * Deja el texto en la forma con la que se comparan disparador y mensaje.
     *
     * Además de bajar a minúsculas, quita las tildes y colapsa los espacios
     * repetidos: el cliente escribe "cómo pago" —el teclado del móvil pone la
     * tilde solo— y el disparador está guardado como "COMO PAGO". Comparando en
     * crudo eso no casaba y la respuesta automática no salía.
     */
    private static function normalizeForMatch(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));

        return preg_replace('/\s+/', ' ', $value);
    }

    private function keywordMatches(string $type, string $incoming): bool
    {
        $haystack = self::normalizeForMatch($incoming);

        if ($haystack === '') {
            return false;
        }

        $keywords = $this->keywords();

        if (empty($keywords)) {
            return false;
        }

        foreach ($keywords as $needle) {
            $matched = match ($type) {
                'exact' => $haystack === $needle,
                'starts_with' => str_starts_with($haystack, $needle),
                default => str_contains($haystack, $needle),
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    public function keywords(): array
    {
        return collect(explode(',', (string) $this->trigger_text))
            ->map(fn ($k) => self::normalizeForMatch((string) $k))
            ->filter(fn ($k) => $k !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function renderMessage(WhatsAppConversation $conversation): string
    {
        return strtr($this->response_message, [
            '{name}' => $conversation->name ?? '',
            '{phone}' => $conversation->phone_number ?? '',
            '{wa_id}' => $conversation->wa_id ?? '',
        ]);
    }
}
