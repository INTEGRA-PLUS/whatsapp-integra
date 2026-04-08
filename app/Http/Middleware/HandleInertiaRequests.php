<?php

namespace App\Http\Middleware;

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
                    'id'         => $request->user()->id,
                    'name'       => $request->user()->name,
                    'email'      => $request->user()->email,
                    'role'       => $request->user()->role,
                    'company_id' => $request->user()->company_id,
                ] : null,
                'isImpersonating' => (bool) session('impersonated_by'),
                'fromMaster'      => (bool) session('from_master'),
            ],
            'flash' => [
                'success' => fn () => session('success'),
                'error'   => fn () => session('error'),
            ],
        ]);
    }
}
