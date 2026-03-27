@extends('layouts.app')

@section('title', 'Iniciar Sesión | WhatsApp Manager')

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
                <img class="h-16 w-auto mb-6" src="{{ asset('logo.png') }}" alt="Logo">
                <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 drop-shadow-sm">
                    ¡Bienvenido de nuevo!
                </h1>
                <p class="mt-3 text-lg text-gray-600">
                    Por favor ingresa tus credenciales para continuar gestionando tus chats.
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
