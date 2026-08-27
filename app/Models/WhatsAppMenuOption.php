<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del menú y lo que ocurre al tocarla.
 */
class WhatsAppMenuOption extends Model
{
    use HasFactory;

    /** Acciones que no necesitan salir del sistema. */
    public const CORE_ACTION_TYPES = ['reply_text', 'submenu', 'handoff'];

    /** La opción existe y sale en el menú, pero al elegirla no pasa nada. */
    public const ACTION_NONE = 'none';

    /**
     * Acciones que consultan Integra 2.0.
     *
     * `reply` es el texto que acompaña a la respuesta (o la sustituye cuando la
     * integración no está conectada); el admin puede sobrescribirlo.
     */
    public const INTEGRA_ACTIONS = [
        'consultar_factura' => [
            'label' => 'Consultar factura',
            'reply' => null,
        ],
        'pagar_en_linea' => [
            'label' => 'Pagar en línea',
            'reply' => null,
        ],
        'reportar_falla' => [
            'label' => 'Reportar falla (crea radicado)',
            'reply' => null,
        ],
        'estado_servicio' => [
            'label' => 'Estado de mi servicio',
            'reply' => null,
        ],
    ];

    /**
     * Acciones declaradas sin integración detrás.
     *
     * Se declaran ya para que el menú se pueda armar completo —el cliente ve la
     * opción y el admin no tiene que inventarse un texto plano que luego habrá
     * que desmontar— y para que el día que llegue la integración baste con
     * implementar el caso: el tipo, la opción configurada y los menús ya
     * enviados siguen siendo los mismos.
     *
     * Cambiar la clave del WiFi sigue aquí porque la API de Integra no expone
     * hoy ninguna ruta que toque el Mikrotik/OLT del cliente.
     */
    public const PENDING_ACTIONS = [
        'cambiar_clave' => [
            'label' => 'Cambiar clave WiFi',
            'reply' => 'Por ahora no puedo cambiar la clave desde aquí. Estamos habilitando esta opción muy pronto.',
        ],
    ];

    public const ACTION_TYPES = [
        'reply_text', 'submenu', 'handoff',
        'consultar_factura', 'pagar_en_linea', 'reportar_falla', 'estado_servicio',
        'cambiar_clave',
        self::ACTION_NONE,
    ];

    /** Tipos que guardan el texto de la opción como mensaje al cliente. */
    public const TEXT_CARRYING_TYPES = ['reply_text', 'handoff'];

    /** Cómo se elige el asesor en un handoff. */
    public const ASSIGN_FIXED = 'fixed';
    public const ASSIGN_LEAST_BUSY = 'least_busy';
    public const ASSIGN_INBOX = 'inbox';
    public const ASSIGN_STRATEGIES = [self::ASSIGN_FIXED, self::ASSIGN_LEAST_BUSY, self::ASSIGN_INBOX];

    protected $table = 'whatsapp_menu_options';

    protected $fillable = [
        'menu_id',
        'position',
        'title',
        'description',
        'action_type',
        'reply_text',
        'target_menu_id',
        'assign_to_user_id',
        'config',
    ];

    protected $casts = [
        'position' => 'integer',
        'config' => 'array',
    ];

    public function menu()
    {
        return $this->belongsTo(WhatsAppMenu::class, 'menu_id');
    }

    public function targetMenu()
    {
        return $this->belongsTo(WhatsAppMenu::class, 'target_menu_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assign_to_user_id');
    }

    /**
     * Identificador que viaja a Meta y vuelve en el webhook cuando el cliente
     * toca la opción.
     *
     * Lleva el id del menú además del de la opción para poder detectar el toque
     * tardío: el cliente que abre un menú de la semana pasada y pulsa ahí sigue
     * identificando la opción, pero sabemos que no corresponde al menú vigente.
     */
    public function payloadId(): string
    {
        return "wamenu:{$this->menu_id}:{$this->id}";
    }

    /**
     * Descompone el id recibido. Devuelve null si no es de un menú nuestro:
     * por el mismo campo llegan los botones de plantillas y los de permiso de
     * llamada, que no tienen nada que ver con este módulo.
     *
     * @return array{menu_id: int, option_id: int}|null
     */
    public static function parsePayloadId(?string $payloadId): ?array
    {
        if (!$payloadId || !preg_match('/^wamenu:(\d+):(\d+)$/', $payloadId, $m)) {
            return null;
        }

        return ['menu_id' => (int) $m[1], 'option_id' => (int) $m[2]];
    }

    /** ¿Esta opción consulta Integra? */
    public function usesIntegra(): bool
    {
        return array_key_exists($this->action_type, self::INTEGRA_ACTIONS);
    }

    /** ¿Esta opción está declarada pero sin integración detrás? */
    public function isPending(): bool
    {
        return array_key_exists($this->action_type, self::PENDING_ACTIONS);
    }

    /**
     * Lo que recibe el cliente al elegir una opción pendiente: el texto que
     * escribió el admin si lo hay, y si no el aviso por defecto del tipo.
     */
    public function pendingReply(): string
    {
        $configured = trim((string) $this->reply_text);

        if ($configured !== '') {
            return $configured;
        }

        return self::PENDING_ACTIONS[$this->action_type]['reply'] ?? '';
    }

    /** Un ajuste concreto de la acción (ver migración `config`). */
    public function setting(string $key, $default = null)
    {
        $value = data_get($this->config ?? [], $key, null);

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Cómo repartir el chat en un handoff.
     *
     * Se deduce del asesor elegido cuando no hay estrategia guardada para no
     * romper las opciones creadas antes de que existiera este ajuste: las que
     * tenían asesor siguen yendo a ese asesor, y las que no, a la bandeja.
     */
    public function assignStrategy(): string
    {
        $strategy = $this->setting('assign_strategy');

        if (in_array($strategy, self::ASSIGN_STRATEGIES, true)) {
            return $strategy;
        }

        return $this->assign_to_user_id ? self::ASSIGN_FIXED : self::ASSIGN_INBOX;
    }

    /** ¿El tipo guarda un texto para el cliente? */
    public static function carriesText(string $actionType): bool
    {
        return in_array($actionType, self::TEXT_CARRYING_TYPES, true)
            || array_key_exists($actionType, self::INTEGRA_ACTIONS)
            || array_key_exists($actionType, self::PENDING_ACTIONS);
    }

    /**
     * Catálogo para el formulario. Se arma aquí y no en el front para que al
     * añadir un tipo nuevo no haya que tocar dos listas que se desincronizan.
     *
     * @return list<array{value: string, label: string, group: string, reply: string|null}>
     */
    public static function catalog(): array
    {
        $catalog = [
            ['value' => 'reply_text', 'label' => 'Responder con un mensaje', 'group' => 'core', 'reply' => null],
            ['value' => 'submenu', 'label' => 'Abrir otro menú', 'group' => 'core', 'reply' => null],
            ['value' => 'handoff', 'label' => 'Pasar a un asesor', 'group' => 'core', 'reply' => null],
        ];

        foreach (self::INTEGRA_ACTIONS as $value => $meta) {
            $catalog[] = [
                'value' => $value,
                'label' => $meta['label'],
                'group' => 'integra',
                'reply' => $meta['reply'],
            ];
        }

        foreach (self::PENDING_ACTIONS as $value => $meta) {
            $catalog[] = [
                'value' => $value,
                'label' => $meta['label'],
                'group' => 'pending',
                'reply' => $meta['reply'],
            ];
        }

        $catalog[] = [
            'value' => self::ACTION_NONE,
            'label' => 'Sin acción / por definir',
            'group' => 'none',
            'reply' => null,
        ];

        return $catalog;
    }
}
