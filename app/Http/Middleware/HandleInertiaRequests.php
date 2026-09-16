<?php

namespace App\Http\Middleware;

use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'company_id' => $request->user()->company_id,
                    'company_name' => session('company_name') ?? $request->user()->company?->name,
                    'role' => $request->user()->getRoleNames()->first(),
                    'roles' => $request->user()->getRoleNames(),
                    'permissions' => $request->user()->getAllPermissions()->pluck('name'),
                ] : null,
                'isImpersonating' => (bool) session('impersonated_by'),
                'fromMaster' => (bool) session('from_master'),
            ],
            // El aviso de las pantallas de acceso ("te mandamos un enlace",
            // "contraseña cambiada"). Va suelto y no dentro de `flash` porque
            // `status` es el nombre que usa el broker de contraseñas de
            // Laravel. Sin compartirlo, el controlador lo deja en la sesión y
            // la pantalla no muestra nada en absoluto: el formulario sólo se
            // vacía, que desde fuera es idéntico a no haber pulsado.
            // Lo que el menú lateral necesita para poner el distintivo «PRO» en
            // la IA sin esconderla: lo que no se ve no lo pide nadie, así que la
            // entrada sale siempre y lo que cambia es lo que hay dentro.
            //
            // Se pregunta por `PlanDeLaEmpresa` y no por la columna `plan`: el
            // catálogo se está partiendo en un plan de CRM más el complemento de
            // IA aparte, y esta llamada seguirá respondiendo lo mismo. No añade
            // consultas — la relación `company` ya se resuelve dos líneas más
            // arriba para el nombre de la empresa.
            'plan' => [
                'tiene_ia' => fn () => $request->user()?->company
                    ? PlanDeLaEmpresa::de($request->user()->company)->tieneIa()
                    : false,
            ],
            'status' => fn () => session('status'),
            'flash' => [
                'success' => fn () => session('success'),
                'error' => fn () => session('error'),
                // La contraseña temporal que el panel maestro genera al
                // restablecer la de un usuario. Viaja por flash y no como prop
                // de la página para que exista una sola vez: al recargar ya no
                // está, que es lo que se quiere de una credencial.
                'temp_password' => fn () => session('temp_password'),
                'temp_password_for' => fn () => session('temp_password_for'),
            ],
        ]);
    }
}
