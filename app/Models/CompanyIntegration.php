<?php

namespace App\Models;

use App\Services\IntegraClient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyIntegration extends Model
{
    use HasFactory;

    public const KEY_INVOICE_PAYMENTS = 'invoice_payments';
    public const KEY_CONTACTS_SYNC = 'contacts_sync';

    /**
     * IA de los menús de WhatsApp.
     *
     * De esta fila se usan `enabled` —el interruptor de la empresa— y
     * `abilities`, que son los permisos de la IA (ver más abajo). El servidor
     * de Ollama y el modelo sí son los mismos para toda la plataforma y viven
     * en el flujo de n8n, así que aquí no hay `base_url`, ni token, ni estado
     * de conexión que mantener.
     *
     * Se guarda en esta tabla y no en una columna de `companies` porque ya
     * existe con la clave única (empresa, integración), que es exactamente la
     * forma de un interruptor por empresa.
     *
     * A diferencia de las otras dos, esta NO habla con Integra: apunta al
     * flujo de n8n, que es quien consulta Integra por su cuenta. Por eso no
     * está en Integra::SOURCES y no puede confundirse con una credencial.
     */
    public const KEY_AI_MENUS = 'ai_menus';

    /**
     * IA de los chats de WhatsApp.
     *
     * Otro proceso, otra fila: la de menús resuelve peticiones concretas contra
     * Integra y necesita permisos; ésta conversa y no toca nada, así que de su
     * fila sólo se usa `enabled`. Tenerlas separadas es lo que deja encender una
     * sin la otra —que es como una empresa puede querer empezar.
     */
    public const KEY_AI_CHAT = 'ai_chat';

    /**
     * Qué puede hacer la IA contra Integra, por empresa.
     *
     * Se guardan en `abilities` —la misma columna donde las filas de Integra
     * guardan sus scopes— porque es exactamente la misma idea: la lista de lo
     * que esta fila autoriza. Vive por empresa y no en el flujo de n8n porque
     * "consultar mi factura" y "crear un radicado a mi nombre" no son la misma
     * decisión, y quien la toma es cada empresa: hasta ahora `radicados` y
     * `pagos` estaban en `true` para toda la plataforma, así que encender el
     * interruptor daba las tres cosas de golpe.
     *
     * El flujo sigue teniendo la última palabra: lo que llega de aquí se cruza
     * con los permisos de la plataforma, y la empresa nunca puede conceder más
     * de lo que la plataforma permite.
     */
    public const AI_READ = 'leer';
    public const AI_TICKETS = 'radicados';
    public const AI_PAYMENTS = 'pagos';

    public const AI_PERMISSIONS = [self::AI_READ, self::AI_TICKETS, self::AI_PAYMENTS];

    /**
     * Con qué nace una empresa: sólo lectura.
     *
     * Consultar una factura no compromete nada; crear un radicado y disparar un
     * cobro sí. Que el admin tenga que concederlos a mano es el punto.
     */
    public const AI_PERMISSIONS_DEFAULT = [self::AI_READ];

    protected $fillable = [
        'company_id',
        'key',
        'status',
        'base_url',
        'access_token',
        'token_expires_at',
        'account',
        'abilities',
        'enabled',
        'trigger_type',
        'trigger_command',
        'emit_electronic_invoice',
        'last_error',
        'connected_at',
        'last_synced_at',
        'sync_status',
    ];

    protected $casts = [
        'account'          => 'array',
        'abilities'        => 'array',
        'enabled'          => 'boolean',
        'emit_electronic_invoice' => 'boolean',
        'access_token'     => 'encrypted',
        'token_expires_at' => 'datetime',
        'connected_at'     => 'datetime',
        'last_synced_at'   => 'datetime',
        'sync_status'      => 'array',
    ];

    // Nunca exponer el token al frontend.
    protected $hidden = [
        'access_token',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Cliente HTTP para hablar con el Integra de esta empresa, o null si no hay
     * credenciales que usar.
     *
     * Construye a partir de lo guardado sin mirar el estado: quien quiera saber
     * si la conexión está sana pregunta antes con isConnected(). Separarlo es lo
     * que deja reintentar una conexión marcada como errónea —que es justo lo que
     * hace el botón "Verificar"— sin tener que rearmar el cliente a mano.
     *
     * Vive aquí porque la fila es la dueña de las credenciales: cada sitio que
     * hacía `new IntegraClient($i->base_url, $i->access_token)` estaba copiando
     * ese conocimiento, y el día que la conexión necesite algo más —una cabecera,
     * un timeout propio— habría que ir a buscarlos uno por uno.
     */
    public function client(): ?IntegraClient
    {
        if (empty($this->base_url) || empty($this->access_token)) {
            return null;
        }

        return new IntegraClient($this->base_url, $this->access_token);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && ! empty($this->access_token);
    }

    public function tokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * ¿El token de esta empresa autoriza emitir la factura a la DIAN?
     *
     * Devuelve null —y no false— cuando no lo sabemos: token pegado a mano, o
     * empresa conectada antes de que empezáramos a guardar los scopes. La
     * diferencia importa: ante "no sabemos" se avisa y se deja intentar, y es
     * Integra quien decide; ante un "no" cierto se bloquea el interruptor,
     * porque encenderlo no haría absolutamente nada.
     */
    public function grantsEmission(): ?bool
    {
        return $this->abilities === null
            ? null
            : in_array(IntegraClient::ABILITY_EMIT, $this->abilities, true);
    }

    /** Prefijo del disparador para el chat: '/' o '@'. */
    public function triggerPrefix(): string
    {
        return $this->trigger_type === 'at' ? '@' : '/';
    }

    /** Token completo del disparador, ej. '/pagos'. */
    public function triggerToken(): ?string
    {
        if (! $this->trigger_command) {
            return null;
        }
        return $this->triggerPrefix() . $this->trigger_command;
    }

    /** ¿Esta empresa tiene la IA encendida? */
    public function aiReady(): bool
    {
        return $this->key === self::KEY_AI_MENUS && (bool) $this->enabled;
    }

    /** ¿Esta empresa tiene encendida la IA de los chats? */
    public static function chatAiEnabled(int $companyId): bool
    {
        return self::where('company_id', $companyId)
            ->where('key', self::KEY_AI_CHAT)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * Los permisos de IA de esta empresa, ya saneados.
     *
     * `null` en la columna significa "fila anterior a los permisos", no "sin
     * permisos": se devuelve el valor por defecto para que una empresa no se
     * quede sin la lectura por una migración a medias.
     *
     * @return list<string>
     */
    public function aiPermissions(): array
    {
        $stored = is_array($this->abilities) ? $this->abilities : null;

        if ($stored === null) {
            return self::AI_PERMISSIONS_DEFAULT;
        }

        return array_values(array_intersect(self::AI_PERMISSIONS, $stored));
    }

    /**
     * Forma en la que viaja al flujo: un booleano por permiso.
     *
     * Un mapa y no una lista porque al otro lado se lee como
     * `permisos.radicados`, y una lista obligaría al flujo a saber buscar
     * dentro de un array —justo el tipo de detalle que se rompe cuando alguien
     * edita n8n sin mirar este archivo.
     *
     * @return array<string, bool>
     */
    public function aiPermissionMap(): array
    {
        $granted = $this->aiPermissions();

        return collect(self::AI_PERMISSIONS)
            ->mapWithKeys(fn (string $p) => [$p => in_array($p, $granted, true)])
            ->all();
    }
}
