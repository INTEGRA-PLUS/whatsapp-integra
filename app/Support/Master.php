<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quiénes son master, sin pasar por `hasRole()`.
 *
 * `User::isMaster()` pregunta a Spatie, y Spatie con equipos contesta según el
 * `setPermissionsTeamId()` que haya puesto en ese momento. Eso hace que la
 * pregunta «¿quiénes son master?» dé respuestas distintas según desde dónde se
 * haga:
 *
 * - **Desde una petición**, el equipo puesto es el de la empresa del usuario que
 *   entró, así que un master de otra empresa devuelve `false`.
 * - **Desde un comando**, no hay ningún equipo puesto, así que devuelve `false`
 *   para todos.
 *
 * Lo segundo no es teoría: `planes:avisar` lleva desde que existe diciendo «No
 * hay ningún master activo a quien avisar» con un master activo en la base. El
 * 18-sep-2026 había 8 avisos pendientes y 0 notificaciones enviadas en toda la
 * historia de la tabla.
 *
 * Un master lo es en todas las empresas —para eso está `Gate::before`— así que
 * la pregunta no depende de ningún equipo y se responde por la tabla.
 *
 * @see \App\Providers\AppServiceProvider
 */
class Master
{
    /** @return Collection<int, User> */
    public static function activos(): Collection
    {
        $ids = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'master')
            ->where('model_has_roles.model_type', User::class)
            ->pluck('model_has_roles.model_id');

        return User::whereIn('id', $ids)->where('active', true)->get();
    }
}
