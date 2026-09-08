<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'domain',
        'active',
        'settings',
        'ai_flow_unlocked_at',
        'ai_flow_unlocked_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
        'ai_flow_unlocked_at' => 'datetime',
    ];

    /**
     * ¿Alguien desbloqueó ya el apartado de IA para esta empresa?
     *
     * Mientras sea false el panel sólo muestra la casilla del secreto: ni los
     * interruptores ni los permisos existen todavía para el admin.
     */
    public function aiFlowUnlocked(): bool
    {
        return $this->ai_flow_unlocked_at !== null;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function instances()
    {
        return $this->hasMany(Instance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
