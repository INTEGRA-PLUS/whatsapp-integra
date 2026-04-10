<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionsTeamId
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            // Usar el company_id de la sesión (para impersonate) o el del usuario por defecto
            $companyId = session('company_id', auth()->user()->company_id);
            setPermissionsTeamId($companyId);
        }

        return $next($request);
    }
}
