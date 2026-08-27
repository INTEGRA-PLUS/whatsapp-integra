<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Menú interactivo de WhatsApp: el mensaje con opciones que el cliente toca en
 * vez de escribir lo que quiere.
 *
 * El formato lo decide el propio menú, no el admin: la Cloud API ofrece dos
 * tipos incompatibles —botones (máximo 3) y lista (hasta 10)— y obligar a quien
 * configura a conocer ese límite sólo produce menús rechazados por Meta.
 */
class WhatsAppMenu extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_menus';

    /** Tipos con texto disparador; misma semántica que AutoResponse. */
    public const KEYWORD_TYPES = ['exact', 'contains', 'starts_with'];
    public const TRIGGERLESS_TYPES = ['welcome'];
    public const MATCH_TYPES = ['exact', 'contains', 'starts_with', 'welcome'];

    /** Límites de la Cloud API. Superarlos es un 400 de Meta, no un aviso. */
    public const MAX_BUTTONS = 3;
    public const MAX_ROWS = 10;
    public const MAX_BUTTON_TITLE = 20;
    public const MAX_ROW_TITLE = 24;
    public const MAX_ROW_DESCRIPTION = 72;
    public const MAX_BODY = 1024;
    public const MAX_HEADER = 60;
    public const MAX_FOOTER = 60;

    /** Cuánto sigue en pie un menú ya enviado para aceptar respuesta escrita. */
    public const SESSION_MINUTES = 60;

    protected $fillable = [
        'company_id',
        'instance_id',
        'name',
        'header_text',
        'body_text',
        'footer_text',
        'list_button_text',
        'is_root',
        'trigger_text',
        'match_types',
        'active',
        'cooldown_minutes',
        'fires_count',
        'last_fired_at',
    ];

    protected $casts = [
        'is_root' => 'boolean',
        'active' => 'boolean',
        'match_types' => 'array',
        'cooldown_minutes' => 'integer',
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

    public function options()
    {
        return $this->hasMany(WhatsAppMenuOption::class, 'menu_id')->orderBy('position');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /** Menús que pueden dispararse por su cuenta (no submenús). */
    public function scopeRoot($query)
    {
        return $query->where('is_root', true);
    }

    public function matchTypes(): array
    {
        return array_values(array_unique($this->match_types ?? []));
    }

    /**
     * Formato con el que saldrá este menú. Con 3 opciones o menos gana el botón
     * —un toque en vez de abrir un listado—; a partir de 4 sólo cabe la lista.
     */
    public function format(): string
    {
        return $this->options->count() <= self::MAX_BUTTONS ? 'button' : 'list';
    }

    /**
     * Prioridad cuando varios menús podrían dispararse: la palabra clave gana a
     * la bienvenida, porque quien escribe "menu" está pidiendo algo concreto
     * mientras que la bienvenida es el saludo por defecto.
     */
    public function priority(): int
    {
        return count(array_intersect($this->matchTypes(), self::KEYWORD_TYPES)) > 0 ? 0 : 1;
    }

    /**
     * @param array{is_first_inbound?: bool} $context
     */
    public function qualifies(string $incoming, array $context = []): bool
    {
        foreach ($this->matchTypes() as $type) {
            $matched = match ($type) {
                'welcome' => (bool) ($context['is_first_inbound'] ?? false),
                'exact', 'contains', 'starts_with' => $this->keywordMatches($type, $incoming),
                default => false,
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deja el texto en la forma con la que se comparan disparador y mensaje:
     * sin tildes, en minúsculas y con los espacios colapsados. El cliente
     * escribe "menú" —la tilde la pone el teclado del móvil solo— y el
     * disparador está guardado como "MENU".
     */
    public static function normalizeForMatch(string $value): string
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

        foreach ($this->keywords() as $needle) {
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

    /** Mismas variables que las respuestas automáticas, para no sorprender. */
    public function renderBody(WhatsAppConversation $conversation): string
    {
        return $this->render($this->body_text, $conversation);
    }

    public function render(?string $text, WhatsAppConversation $conversation): string
    {
        return strtr((string) $text, [
            '{name}' => $conversation->name ?? '',
            '{phone}' => $conversation->phone_number ?? '',
            '{wa_id}' => $conversation->wa_id ?? '',
        ]);
    }
}
