@extends('layouts.app')

@section('title', 'Iniciar Sesión | Integra CRM — Portal de Gestión')

@section('content')
<div class="relative min-h-screen font-sans antialiased text-gray-900 overflow-hidden">
    {{-- Background elements --}}
    <div class="fixed inset-0 -z-10 h-full w-full bg-white bg-[linear-gradient(to_right,#f0f0f0_1px,transparent_1px),linear-gradient(to_bottom,#f0f0f0_1px,transparent_1px)] bg-[size:6rem_4rem]">
        <div class="absolute inset-0 bg-[radial-gradient(circle_500px_at_50%_200px,#C9EBBC,transparent)]"></div>
    </div>

    <div class="flex min-h-screen flex-col items-center justify-center p-6 sm:p-12">
        <div class="w-full max-w-md space-y-8">
            {{-- Header/Logo section --}}
            <div class="flex flex-col items-center text-center">
                {{-- Integra CRM Logo SVG --}}
                <div class="mb-6" style="width:120px;height:120px;">
                    <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" width="120" height="120" role="img" aria-label="Integra CRM Logo">
                        <!-- Dark navy background circle -->
                        <circle cx="100" cy="100" r="100" fill="#0d1b2e"/>
                        <!-- Outer circuit ring -->
                        <circle cx="100" cy="100" r="82" fill="none" stroke="#1a6b2a" stroke-width="1.2" stroke-dasharray="10 4" opacity="0.6"/>
                        <!-- Middle circuit ring -->
                        <circle cx="100" cy="100" r="68" fill="none" stroke="#22c55e" stroke-width="1.5" stroke-dasharray="8 6" opacity="0.5"/>
                        <!-- Inner circuit ring -->
                        <circle cx="100" cy="100" r="54" fill="none" stroke="#4ade80" stroke-width="1.8" opacity="0.4"/>
                        <!-- Circuit dots -->
                        <circle cx="100" cy="18" r="3.5" fill="#22c55e"/>
                        <circle cx="161" cy="50" r="3" fill="#22c55e"/>
                        <circle cx="175" cy="115" r="3" fill="#16a34a" opacity="0.8"/>
                        <circle cx="39" cy="50" r="3" fill="#22c55e"/>
                        <circle cx="25" cy="115" r="3" fill="#16a34a" opacity="0.8"/>
                        <circle cx="100" cy="182" r="3" fill="#16a34a" opacity="0.7"/>
                        <!-- Power button icon - neon green glow -->
                        <filter id="glow">
                            <feGaussianBlur stdDeviation="3" result="coloredBlur"/>
                            <feMerge><feMergeNode in="coloredBlur"/><feMergeNode in="SourceGraphic"/></feMerge>
                        </filter>
                        <!-- Power arc -->
                        <path d="M 73 68 A 35 35 0 1 0 127 68" fill="none" stroke="#4ade80" stroke-width="6" stroke-linecap="round" filter="url(#glow)"/>
                        <!-- Power vertical line -->
                        <line x1="100" y1="48" x2="100" y2="76" stroke="#4ade80" stroke-width="6" stroke-linecap="round" filter="url(#glow)"/>
                        <!-- WhatsApp badge bottom-right -->
                        <circle cx="148" cy="142" r="18" fill="#25d366"/>
                        <path d="M148 128 c-7.7 0-14 6.3-14 14 0 2.5 0.7 4.9 1.9 6.9l-2 7.3 7.5-2c 2 1.1 4.2 1.7 6.6 1.7 7.7 0 14-6.3 14-14s-6.3-14-14-14z" fill="#25d366"/>
                        <path d="M155.5 148.5 c-0.3 0.9-1.8 1.7-2.5 1.8-0.6 0.1-1.5 0.2-4.5-0.9-3.8-1.4-6.2-5.2-6.4-5.5-0.2-0.2-1.5-2-1.5-3.8 0-1.8 0.9-2.7 1.3-3.1 0.3-0.3 0.7-0.4 1-0.4h0.7c0.3 0 0.6 0 0.9 0.7l1.1 2.8c0.1 0.3 0.1 0.6-0.1 0.9l-0.6 0.8c-0.2 0.2-0.3 0.4-0.1 0.8 0.4 0.7 1.5 2.3 3.2 3.6 2.2 1.6 4 2.1 4.6 2.3 0.5 0.2 0.8 0.1 1.1-0.2l0.8-0.9c0.3-0.3 0.6-0.4 0.9-0.2l2.8 1.3c0.3 0.1 0.6 0.3 0.6 0.7l0 0.4z" fill="white"/>
                        <!-- INTEGRA text -->
                        <text x="100" y="172" text-anchor="middle" font-family="Arial Black, Arial, sans-serif" font-weight="900" font-size="26" fill="white" letter-spacing="2">INTEGRA</text>
                        <!-- CRM text with green -->
                        <text x="100" y="190" text-anchor="middle" font-family="Arial Black, Arial, sans-serif" font-weight="900" font-size="16" fill="#22c55e" letter-spacing="4">CRM</text>
                        <!-- Decorative lines beside CRM -->
                        <line x1="58" y1="183" x2="73" y2="183" stroke="#22c55e" stroke-width="1.5"/>
                        <line x1="127" y1="183" x2="142" y2="183" stroke="#22c55e" stroke-width="1.5"/>
                    </svg>
                </div>
                <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 drop-shadow-sm">
                    Integra CRM
                </h1>
                <p class="mt-2 text-sm font-semibold text-green-600 uppercase tracking-widest">
                    Portal de Gestión para Clientes de Integra Colombia
                </p>
                <p class="mt-3 text-base text-gray-500">
                    Ingresa tus credenciales para acceder al sistema.
                </p>
            </div>

            {{-- Login Card --}}
            <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white/80 p-1 shadow-2xl backdrop-blur-sm transition-all duration-300">
                <div class="rounded-xl bg-white p-8 sm:p-10">
                    <form class="space-y-6" action="{{ route('login') }}" method="POST">
                        @csrf
                        
                        {{-- Email Field --}}
                        <div class="space-y-2">
                            <label for="email" class="text-sm font-semibold text-gray-700">
                                Correo Electrónico
                            </label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-gray-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-mail"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                </div>
                                <input id="email" name="email" type="email" autocomplete="email" required 
                                    value="{{ old('email') }}"
                                    placeholder="correo@ejemplo.com"
                                    class="block w-full rounded-xl border-2 border-gray-100 bg-white py-4 pl-12 pr-4 text-lg text-gray-900 placeholder-gray-400 transition-all duration-200 focus:border-green-500 focus:outline-none focus:ring-4 focus:ring-green-500/10">
                            </div>
                            @error('email')
                                <p class="text-sm font-medium text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Password Field --}}
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label for="password" class="text-sm font-semibold text-gray-700">
                                    Contraseña
                                </label>
                                @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-xs font-medium text-green-600 hover:text-green-700 transition-colors">
                                    ¿Olvidaste tu contraseña?
                                </a>
                                @endif
                            </div>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-gray-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-lock"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </div>
                                <input id="password" name="password" type="password" autocomplete="current-password" required 
                                    placeholder="••••••••"
                                    class="block w-full rounded-xl border-2 border-gray-100 bg-white py-4 pl-12 pr-4 text-lg text-gray-900 placeholder-gray-400 transition-all duration-200 focus:border-green-500 focus:outline-none focus:ring-4 focus:ring-green-500/10">
                            </div>
                            @error('password')
                                <p class="text-sm font-medium text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- General errors --}}
                        @if($errors->any() && !$errors->has('email') && !$errors->has('password'))
                        <div class="rounded-lg bg-red-50 p-4 border border-red-100">
                            <div class="text-sm text-red-700 font-medium">
                                {{ $errors->first() }}
                            </div>
                        </div>
                        @endif

                        {{-- Remember Me --}}
                        <div class="flex items-center space-x-2">
                            <input id="remember" name="remember" type="checkbox" 
                                class="h-5 w-5 rounded-md border-gray-300 text-green-600 focus:ring-green-500/20 transition-all cursor-pointer">
                            <label for="remember" class="text-sm font-medium text-gray-600 cursor-pointer select-none">
                                Mantener sesión iniciada
                            </label>
                        </div>

                        {{-- Submit Button --}}
                        <div>
                            <button type="submit" 
                                class="w-full h-14 rounded-xl bg-green-600 text-lg font-bold text-white shadow-lg shadow-green-600/20 hover:bg-green-700 hover:shadow-green-600/30 active:scale-[0.98] transition-all duration-200 flex items-center justify-center space-x-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-log-in"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>
                                <span>Iniciar Sesión</span>
                            </button>
                        </div>
                    </form>

                    {{-- Decorative footer inside card --}}
                    <div class="mt-8 pt-6 border-t border-gray-100 flex items-center justify-center space-x-2 text-gray-500 text-sm font-medium">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-green-500 lucide lucide-check-circle-2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>
                        <span>Plataforma Segura y Encriptada</span>
                    </div>
                </div>
            </div>

            {{-- Footer elements --}}
            <div class="text-center text-sm text-gray-500">
                &copy; {{ date('Y') }} Integra Plus. Todos los derechos reservados.
            </div>
        </div>
    </div>
</div>
@endsection
