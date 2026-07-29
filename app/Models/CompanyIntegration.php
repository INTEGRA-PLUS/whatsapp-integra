<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyIntegration extends Model
{
    use HasFactory;

    public const KEY_INVOICE_PAYMENTS = 'invoice_payments';
    public const KEY_CONTACTS_SYNC = 'contacts_sync';

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
}
