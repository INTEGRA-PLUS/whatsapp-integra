<?php

namespace App\Models;

use App\Notifications\RestablecerContrasenaNotification;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles, Notifiable;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'role',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * El correo de restablecimiento, en español y con la marca del producto,
     * en vez del que trae Laravel de fábrica.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RestablecerContrasenaNotification($token));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'preferencias' => 'array',
        ];
    }

    /**
     * Las etapas del tablero que este usuario ha escondido.
     *
     * Es una preferencia suya, no de la empresa: con doce columnas, quien está
     * en soporte no quiere ver las de facturación y quien factura quiere justo
     * las otras.
     */
    public function etapasOcultas(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            $this->preferencias['etapas_ocultas'] ?? []
        )));
    }

    public function guardarEtapasOcultas(array $ids): void
    {
        // `array_merge` y no asignación directa: `preferencias` es un cajón
        // compartido, y escribirlo entero borraría lo que guarden otras
        // pantallas. Mismo problema que ya dio `instances.meta`.
        $this->preferencias = array_merge($this->preferencias ?? [], [
            'etapas_ocultas' => array_values(array_unique(array_map('intval', $ids))),
        ]);

        $this->save();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isAdmin()
    {
        return $this->hasRole('admin');
    }

    public function isAgent()
    {
        return $this->hasRole(['admin', 'agent']);
    }

    public function isMaster()
    {
        return $this->hasRole('master');
    }

    protected static function booted()
    {
        static::created(function ($user) {
            // Si es el primer usuario de la compañía (excluyendo la master si fuera necesario)
            // Le asignamos el rol admin automáticamente
            if ($user->company_id && User::where('company_id', $user->company_id)->count() === 1) {
                setPermissionsTeamId($user->company_id);

                $adminRole = Role::firstOrCreate([
                    'name' => 'admin',
                    'company_id' => $user->company_id,
                    'guard_name' => 'web',
                ]);

                // Asegurar que el rol admin tenga todos los permisos disponibles
                $allPermissions = Permission::all();
                $adminRole->syncPermissions($allPermissions);

                $user->assignRole($adminRole);
            }
        });
    }
}
