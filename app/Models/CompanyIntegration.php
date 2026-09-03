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
     * De esta fila **sólo se usa `enabled`**: es el interruptor de la empresa y
     * nada más. El servidor de Ollama, el modelo y los ajustes son los mismos
     * para toda la plataforma y viven en el flujo de n8n, así que aquí no hay
     * `base_url`, ni token, ni estado de conexión que mantener.
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

    protected $fillable = [
        'company_id',
        'key',
        'status',
        'base_url',
        'access_token',
        'token_expires_at',
        'account',
        'enabled',
        'trigger_type',
        'trigger_command',
        'last_error',
        'connected_at',
        'last_synced_at',
        'sync_status',
    ];

    protected $casts = [
        'account'          => 'array',
        'enabled'          => 'boolean',
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
}
