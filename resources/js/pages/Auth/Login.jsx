import { useForm, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AppLogo from '@/components/app-logo';
import { Mail, Lock, LogIn, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [showPassword, setShowPassword] = useState(false);

    function handleSubmit(e) {
        e.preventDefault();
        post(route('login'));
    }

    return (
        <div className="relative min-h-screen font-sans antialiased text-foreground">
            <Head title="Iniciar Sesión | WhatsApp Manager" />

            {/* Background elements */}
            <div className="fixed inset-0 -z-10 h-full w-full bg-white bg-[linear-gradient(to_right,#f0f0f0_1px,transparent_1px),linear-gradient(to_bottom,#f0f0f0_1px,transparent_1px)] bg-[size:6rem_4rem]">
                <div className="absolute inset-0 bg-[radial-gradient(circle_500px_at_50%_200px,#C9EBBC,transparent)]"></div>
            </div>

            <div className="flex min-h-screen flex-col items-center justify-center p-6 sm:p-12">
                <div className="w-full max-w-md space-y-8">
                    {/* Header/Logo section */}
                    <div className="flex flex-col items-center text-center">
                        <div className="mb-6 flex -translate-x-1 items-center justify-center">
                            <AppLogo />
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight text-gray-900 drop-shadow-sm">
                            ¡Bienvenido de nuevo!
                        </h1>
                        <p className="mt-3 text-lg text-gray-600">
                            Por favor ingresa tus credenciales para continuar gestionando tus chats.
                        </p>
                    </div>

                    {/* Login Card */}
                    <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white/80 p-1 shadow-2xl backdrop-blur-sm transition-all duration-300">
                        <div className="rounded-xl bg-white p-8 sm:p-10">
                            <form className="space-y-6" onSubmit={handleSubmit}>
                                {/* Email Field */}
                                <div className="space-y-2">
                                    <label htmlFor="email" className="text-sm font-semibold text-gray-700">
                                        Correo Electrónico
                                    </label>
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-gray-400 group-focus-within:text-green-600 transition-colors duration-200">
                                            <Mail size={20} />
                                        </div>
                                        <input
                                            id="email"
                                            type="email"
                                            autoComplete="email"
                                            required
                                            value={data.email}
                                            onChange={e => setData('email', e.target.value)}
                                            placeholder="correo@ejemplo.com"
                                            className="block w-full rounded-xl border-2 border-gray-100 bg-white py-4 pl-12 pr-4 text-lg text-gray-900 placeholder-gray-400 transition-all duration-200 focus:border-green-500 focus:outline-none focus:ring-4 focus:ring-green-500/10"
                                        />
                                    </div>
                                    {errors.email && (
                                        <p className="animate-in fade-in slide-in-from-top-1 text-sm font-medium text-red-500">
                                            {errors.email}
                                        </p>
                                    )}
                                </div>

                                {/* Password Field */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <label htmlFor="password" className="text-sm font-semibold text-gray-700">
                                            Contraseña
                                        </label>
                                        <a href="#" className="hidden text-xs font-medium text-green-600 hover:text-green-700 transition-colors">
                                            ¿Olvidaste tu contraseña?
                                        </a>
                                    </div>
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-gray-400 group-focus-within:text-green-600 transition-colors duration-200">
                                            <Lock size={20} />
                                        </div>
                                        <input
                                            id="password"
                                            type={showPassword ? 'text' : 'password'}
                                            autoComplete="current-password"
                                            required
                                            value={data.password}
                                            onChange={e => setData('password', e.target.value)}
                                            placeholder="••••••••"
                                            className="block w-full rounded-xl border-2 border-gray-100 bg-white py-4 pl-12 pr-4 text-lg text-gray-900 placeholder-gray-400 transition-all duration-200 focus:border-green-500 focus:outline-none focus:ring-4 focus:ring-green-500/10"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword(!showPassword)}
                                            className="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400 hover:text-gray-600 transition-colors"
                                        >
                                            <span className="text-xs font-semibold select-none">
                                                {showPassword ? 'Ocultar' : 'Mostrar'}
                                            </span>
                                        </button>
                                    </div>
                                    {errors.password && (
                                        <p className="text-sm font-medium text-red-500">{errors.password}</p>
                                    )}
                                </div>

                                {/* Remember Me */}
                                <div className="flex items-center space-x-2">
                                    <input
                                        id="remember"
                                        type="checkbox"
                                        checked={data.remember}
                                        onChange={e => setData('remember', e.target.checked)}
                                        className="h-5 w-5 rounded-md border-gray-300 text-green-600 focus:ring-green-500/20 transition-all cursor-pointer"
                                    />
                                    <label htmlFor="remember" className="text-sm font-medium text-gray-600 cursor-pointer select-none">
                                        Mantener sesión iniciada
                                    </label>
                                </div>

                                {/* Submit Button */}
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full h-14 rounded-xl bg-green-600 text-lg font-bold text-white shadow-lg shadow-green-600/20 hover:bg-green-700 hover:shadow-green-600/30 active:scale-[0.98] transition-all duration-200"
                                >
                                    {processing ? (
                                        <div className="flex items-center space-x-2">
                                            <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                                            <span>Iniciando...</span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center space-x-2">
                                            <LogIn size={20} />
                                            <span>Iniciar Sesión</span>
                                        </div>
                                    )}
                                </Button>
                            </form>

                            {/* Decorative footer inside card */}
                            <div className="mt-8 pt-6 border-t border-gray-100 flex items-center justify-center space-x-2 text-gray-500 text-sm font-medium">
                                <CheckCircle2 size={16} className="text-green-500" />
                                <span>Plataforma Segura y Encriptada</span>
                            </div>
                        </div>
                    </div>

                    {/* Footer elements */}
                    <div className="text-center text-sm text-gray-500">
                        &copy; {new Date().getFullYear()} Integra Plus. Todos los derechos reservados.
                    </div>
                </div>
            </div>
        </div>
    );
}
