<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Notifiable, HasRoles;

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

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
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
            if ($user->company_id && \App\Models\User::where('company_id', $user->company_id)->count() === 1) {
                setPermissionsTeamId($user->company_id);
                
                $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
                    'name' => 'admin',
                    'company_id' => $user->company_id,
                    'guard_name' => 'web'
                ]);

                // Asegurar que el rol admin tenga todos los permisos disponibles
                $allPermissions = \Spatie\Permission\Models\Permission::all();
                $adminRole->syncPermissions($allPermissions);

                $user->assignRole($adminRole);
            }
        });
    }
}
