<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Instance extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'uuid',
        'name',
        'phone_number_id',
        'waba_id',
        'external_account_id',
        'display_phone_number',
        'type',
        'channel',
        'status',
        'active',
        'health_status',
        'health_checked_at',
        'health_error',
        'meta',
        'access_token',
        'token_expires_at',
    ];

    /**
     * El hash del token de API no sale nunca de aquí.
     *
     * No sirve para autenticarse —es un hash— pero enseñarlo invita a probar, y
     * no hay ningún sitio de la aplicación que lo necesite.
     */
    /**
     * Lo que nunca sale del servidor al serializar.
     *
     * `access_token` es el token de Meta de la línea: con él se envían mensajes
     * como el cliente y se lee su WABA entera. Estaba fuera de esta lista, y
     * como el chat manda las instancias completas a Inertia, **viajaba dentro
     * del HTML de `/chat`**: cualquier agente con acceso al chat —o cualquiera
     * mirando el código fuente de la página— podía leerlo. Comprobado el
     * 11-sep-2026 renderizando la pantalla.
     *
     * Con esto sigue disponible en PHP (`$instance->access_token`); lo único
     * que cambia es que deja de serializarse.
     */
    protected $hidden = [
        'api_token',
        'access_token',
    ];

    protected $casts = [
        'active' => 'boolean',
        'health_checked_at' => 'datetime',
        'api_token_created_at' => 'datetime',
        'api_token_last_used_at' => 'datetime',
        'api_last_seen_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'meta' => 'array',
    ];

    /** El prefijo hace reconocible el token si aparece en un log o en un pegado. */
    public const API_TOKEN_PREFIJO = 'wai_';

    /**
     * Por dónde escribe el cliente final.
     *
     * No confundir con `type`, que dice con qué proveedor hablamos (`meta`,
     * `vibio`). Messenger e Instagram también son Meta: son preguntas distintas.
     */
    public const CANAL_WHATSAPP = 'whatsapp';

    public const CANAL_MESSENGER = 'messenger';

    public const CANAL_INSTAGRAM = 'instagram';

    public const CANALES = [
        self::CANAL_WHATSAPP,
        self::CANAL_MESSENGER,
        self::CANAL_INSTAGRAM,
    ];

    /** Cómo se llama el canal en pantalla. */
    public const NOMBRES_DE_CANAL = [
        self::CANAL_WHATSAPP => 'WhatsApp',
        self::CANAL_MESSENGER => 'Messenger',
        self::CANAL_INSTAGRAM => 'Instagram',
    ];

    public function esWhatsApp(): bool
    {
        // Sin canal es WhatsApp: las líneas creadas antes de que existiera la
        // columna lo son, y una instancia recién construida en memoria todavía
        // no tiene el valor por defecto de la base.
        return ($this->channel ?? self::CANAL_WHATSAPP) === self::CANAL_WHATSAPP;
    }

    public function esMessenger(): bool
    {
        return $this->channel === self::CANAL_MESSENGER;
    }

    public function esInstagram(): bool
    {
        return $this->channel === self::CANAL_INSTAGRAM;
    }

    public function nombreDelCanal(): string
    {
        return self::NOMBRES_DE_CANAL[$this->channel ?? self::CANAL_WHATSAPP] ?? 'WhatsApp';
    }

    public function scopeCanal($query, string $canal)
    {
        return $query->where('channel', $canal);
    }

    /**
     * Deja guardada la cuenta profesional de Instagram que acaba de conectarse.
     *
     * El nombre de usuario va dentro de `meta` y no en columna propia porque es
     * descriptivo: cambia cuando el cliente lo cambia en Instagram y sólo sirve
     * para pintarlo. Lo que identifica la línea es `external_account_id`.
     *
     * Se pasa por aquí y no asignando `meta` a pelo: ese campo es un cajón
     * compartido con la configuración de llamadas y las plantillas de reserva, y
     * sobrescribirlo entero borra lo de los demás.
     */
    public function guardarCuentaDeInstagram(string $usuario, ?string $foto = null): void
    {
        $meta = $this->meta ?? [];
        $meta['instagram'] = array_merge($meta['instagram'] ?? [], array_filter([
            'username' => $usuario,
            'profile_picture_url' => $foto,
        ], fn ($valor) => $valor !== null));

        $this->meta = $meta;
    }

    public function usuarioDeInstagram(): ?string
    {
        return $this->meta['instagram']['username'] ?? null;
    }

    /**
     * Si al token le quedan menos días que los indicados.
     *
     * Los de Instagram duran 60 días y se renuevan por API sin molestar al
     * cliente, pero sólo mientras sigan vivos: uno caducado obliga a rehacer el
     * inicio de sesión con el cliente delante. Por eso se renuevan con margen y
     * no el último día.
     */
    public function tokenPorCaducar(int $dias = 10): bool
    {
        if ($this->token_expires_at === null) {
            // Sin fecha no caduca, que es el caso de los tokens de usuario del
            // sistema de WhatsApp.
            return false;
        }

        return $this->token_expires_at->lessThan(now()->addDays($dias));
    }

    /**
     * Crea un token nuevo y guarda sólo su hash.
     *
     * Devuelve el token en claro **una sola vez**: es la única ocasión en que
     * existe fuera del cliente. Si se pierde, se genera otro; no hay forma de
     * recuperarlo, y eso es lo que lo hace un secreto de verdad.
     *
     * Generarlo de nuevo invalida el anterior en el acto, así que rotarlo es
     * también la forma de cortarle el acceso a una integración.
     */
    public function generarApiToken(): string
    {
        $token = self::API_TOKEN_PREFIJO.Str::random(40);

        $this->forceFill([
            'api_token' => self::hashApiToken($token),
            'api_token_created_at' => now(),
            'api_token_last_used_at' => null,
        ])->save();

        return $token;
    }

    /**
     * SHA-256 y no bcrypt: hay que encontrar la instancia *por* el token en cada
     * petición, y con bcrypt habría que recorrer la tabla comparando una a una.
     * El token son 40 caracteres aleatorios, no una contraseña que alguien
     * pueda adivinar, así que el coste de bcrypt no compra nada aquí.
     */
    public static function hashApiToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function tieneApiToken(): bool
    {
        return $this->api_token !== null;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations()
    {
        return $this->hasMany(WhatsAppConversation::class);
    }

    /**
     * La importación de contactos e historial, si este número vino de la app
     * del celular. En un registro normal no existe.
     */
    public function coexistenceSync()
    {
        return $this->hasOne(CoexistenceSync::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeMeta($query)
    {
        return $query->where('type', 'meta');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * ¿Esta línea puede hablar con Meta?
     *
     * El token cuenta tanto como los identificadores. Sin él no hay llamada
     * posible, así que dar la línea por configurada sólo servía para que el
     * fallo apareciera más tarde y peor: un envío aceptado, encolado, y muerto
     * en el worker con un 401, en vez de dicho al crear la instancia.
     */
    public function isMetaConfigured()
    {
        // Cada canal se configura con cosas distintas: WhatsApp con un número y
        // una WABA, Instagram con una cuenta profesional. Messenger todavía no
        // está construido, y decir que no está listo es la respuesta correcta
        // —y la segura: ningún camino de salida intentará hablar con Meta por un
        // canal que aún no sabe hacerlo.
        if ($this->esInstagram()) {
            return ! empty($this->external_account_id) && ! empty($this->access_token);
        }

        if (! $this->esWhatsApp()) {
            return false;
        }

        return ! empty($this->phone_number_id)
            && ! empty($this->waba_id)
            && ! empty($this->access_token);
    }

    public function calls()
    {
        return $this->hasMany(WhatsAppCall::class);
    }

    /**
     * Sub-arreglo de configuración de llamadas dentro de `meta`.
     */
    public function callingSettings(): array
    {
        return $this->meta['calling'] ?? [];
    }

    /**
     * ¿La función de llamadas está habilitada en el número (vía Meta)?
     * Necesario para entrantes y salientes.
     */
    public function callingEnabled(): bool
    {
        return (bool) ($this->callingSettings()['enabled'] ?? false);
    }

    /**
     * ¿El negocio activó las llamadas SALIENTES? Va aparte por el costo por
     * minuto: las entrantes son gratis, las salientes se cobran.
     */
    public function outboundCallsEnabled(): bool
    {
        return (bool) ($this->callingSettings()['outbound_enabled'] ?? false);
    }

    /**
     * Actualiza una o varias claves de la configuración de llamadas en `meta`,
     * conservando el resto del JSON.
     */
    public function setCallingSettings(array $values): void
    {
        $meta = $this->meta ?? [];
        $meta['calling'] = array_merge($meta['calling'] ?? [], $values);
        $this->meta = $meta;
    }

    /**
     * Sub-arreglo de configuración de la plantilla de reinicio de conversación
     * (para reabrir chats fuera de la ventana de 24h) dentro de `meta`.
     */
    public function resumeTemplateSettings(): array
    {
        return $this->meta['resume_template'] ?? [];
    }

    public function resumeTemplateName(): ?string
    {
        return $this->resumeTemplateSettings()['name'] ?? null;
    }

    public function resumeTemplateLanguage(): ?string
    {
        return $this->resumeTemplateSettings()['language'] ?? null;
    }

    /**
     * Configura (o limpia, pasando $name null) la plantilla de reinicio de
     * conversación de esta instancia, conservando el resto del JSON `meta`.
     */
    public function setResumeTemplate(?string $name, ?string $language): void
    {
        $meta = $this->meta ?? [];
        if ($name) {
            $meta['resume_template'] = ['name' => $name, 'language' => $language];
        } else {
            unset($meta['resume_template']);
        }
        $this->meta = $meta;
    }

    /**
     * Sub-arreglo de la plantilla de respaldo para avisos automáticos fuera de
     * la ventana de 24h, dentro de `meta`. Guarda tanto la elección de la
     * empresa (nombre, idioma, mapeo de variables) como el último estado
     * conocido en Meta (APPROVED/PENDING/REJECTED) para no consultar el Graph
     * en cada envío. Ver WhatsAppFallbackTemplateService.
     */
    public function fallbackTemplateSettings(): array
    {
        return $this->meta['fallback_template'] ?? [];
    }

    public function fallbackTemplateName(): ?string
    {
        return $this->fallbackTemplateSettings()['name'] ?? null;
    }

    public function fallbackTemplateLanguage(): ?string
    {
        return $this->fallbackTemplateSettings()['language'] ?? null;
    }

    /**
     * ¿La empresa apagó a propósito el respaldo automático? Sin esto, la única
     * forma de desactivarlo sería borrar la plantilla de Meta, y el servicio la
     * volvería a crear en el siguiente aviso fuera de ventana.
     */
    public function fallbackTemplateDisabled(): bool
    {
        return (bool) ($this->fallbackTemplateSettings()['disabled'] ?? false);
    }

    /**
     * Mezcla valores en la configuración de la plantilla de respaldo
     * conservando el resto del JSON `meta` y las claves que no se tocan.
     */
    public function mergeFallbackTemplate(array $values): void
    {
        $meta = $this->meta ?? [];
        $meta['fallback_template'] = array_merge($meta['fallback_template'] ?? [], $values);
        $this->meta = $meta;
    }

    /**
     * Borra por completo la configuración de la plantilla de respaldo: la
     * siguiente consulta vuelve a partir del catálogo por defecto.
     */
    public function clearFallbackTemplate(): void
    {
        $meta = $this->meta ?? [];
        unset($meta['fallback_template']);
        $this->meta = $meta;
    }

    /**
     * Nombre comercial con el que firmar los avisos automáticos: lo que el
     * cliente final debe leer, no el nombre interno de la instancia. Se prefiere
     * el nombre verificado que Meta muestra en el perfil del número.
     */
    public function businessDisplayName(): string
    {
        $candidates = [
            $this->meta['verified_name'] ?? null,
            $this->company?->name,
            $this->name,
            $this->display_phone_number,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'nuestro equipo';
    }
}
