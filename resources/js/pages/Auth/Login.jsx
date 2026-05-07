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
        <div className="relative min-h-screen font-sans antialiased text-slate-200 overflow-hidden bg-[#020817]">
            <Head title="Iniciar Sesión" />

            {/* Premium Dark Background */}
            <div className="fixed inset-0 -z-10 h-full w-full">
                <div className="absolute inset-0 bg-[radial-gradient(circle_800px_at_50%_-20%,#0f172a,transparent)]"></div>
                <div className="absolute inset-0 bg-[radial-gradient(circle_600px_at_80%_80%,#062d1d,transparent)] opacity-40"></div>
                <div className="absolute inset-0 bg-[linear-gradient(to_right,#0f172a11_1px,transparent_1px),linear-gradient(to_bottom,#0f172a11_1px,transparent_1px)] bg-[size:4rem_4rem] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_50%,#000_70%,transparent_100%)]"></div>
            </div>

            <div className="flex min-h-screen flex-col items-center justify-center p-6 sm:p-12">
                <div className="w-full max-w-md space-y-8">
                    {/* Header/Logo section */}
                    <div className="flex flex-col items-center text-center">
                        <div className="mb-8 flex items-center justify-center transition-transform duration-500 hover:scale-105">
                            <img src="/logo.png" alt="Integra CRM Logo" className="h-32 w-auto drop-shadow-[0_0_25px_rgba(34,197,94,0.2)]" />
                        </div>
                        <h1 className="text-4xl font-black tracking-tight text-white drop-shadow-md">
                            Integra CRM
                        </h1>
                        <p className="mt-2 text-[10px] font-bold text-green-500 uppercase tracking-[0.4em] opacity-80">
                            Portal de Gestión — Integra Colombia
                        </p>
                    </div>

                    {/* Login Card */}
                    <div className="overflow-hidden rounded-3xl border border-slate-800 bg-[#0f172a]/80 p-[1px] shadow-[0_0_50px_-12px_rgba(0,0,0,0.5)] backdrop-blur-xl transition-all duration-300 hover:border-green-500/30">
                        <div className="rounded-[23px] bg-[#0f172a] p-8 sm:p-10 shadow-inner">
                            <form className="space-y-6" onSubmit={handleSubmit}>
                                {/* Email Field */}
                                <div className="space-y-2">
                                    <label htmlFor="email" className="text-xs font-bold text-slate-400 uppercase tracking-wider ml-1">
                                        Correo Electrónico
                                    </label>
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-slate-500 group-focus-within:text-green-500 transition-colors duration-300">
                                            <Mail size={18} />
                                        </div>
                                        <input
                                            id="email"
                                            type="email"
                                            autoComplete="email"
                                            required
                                            value={data.email}
                                            onChange={e => setData('email', e.target.value)}
                                            placeholder="correo@ejemplo.com"
                                            className="block w-full rounded-xl border border-slate-800 bg-slate-900/50 py-4 pl-12 pr-4 text-base text-white placeholder-slate-600 transition-all duration-300 focus:border-green-500/50 focus:outline-none focus:ring-4 focus:ring-green-500/5 focus:bg-slate-900"
                                        />
                                    </div>
                                    {errors.email && (
                                        <p className="text-xs font-semibold text-red-400 mt-1 ml-1 animate-pulse">
                                            {errors.email}
                                        </p>
                                    )}
                                </div>

                                {/* Password Field */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between ml-1">
                                        <label htmlFor="password" className="text-xs font-bold text-slate-400 uppercase tracking-wider">
                                            Contraseña
                                        </label>
                                        <a href="#" className="text-[10px] font-bold text-green-600 hover:text-green-400 transition-colors uppercase tracking-tighter">
                                            ¿Olvidaste tu contraseña?
                                        </a>
                                    </div>
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-slate-500 group-focus-within:text-green-500 transition-colors duration-300">
                                            <Lock size={18} />
                                        </div>
                                        <input
                                            id="password"
                                            type={showPassword ? 'text' : 'password'}
                                            autoComplete="current-password"
                                            required
                                            value={data.password}
                                            onChange={e => setData('password', e.target.value)}
                                            placeholder="••••••••"
                                            className="block w-full rounded-xl border border-slate-800 bg-slate-900/50 py-4 pl-12 pr-4 text-base text-white placeholder-slate-600 transition-all duration-300 focus:border-green-500/50 focus:outline-none focus:ring-4 focus:ring-green-500/5 focus:bg-slate-900"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword(!showPassword)}
                                            className="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-slate-300 transition-colors"
                                        >
                                            <span className="text-[10px] font-black uppercase select-none tracking-tighter">
                                                {showPassword ? 'Ocultar' : 'Mostrar'}
                                            </span>
                                        </button>
                                    </div>
                                    {errors.password && (
                                        <p className="text-xs font-semibold text-red-400 mt-1 ml-1 animate-pulse">{errors.password}</p>
                                    )}
                                </div>

                                {/* Remember Me */}
                                <div className="flex items-center space-x-2 ml-1">
                                    <div className="relative flex items-center">
                                        <input
                                            id="remember"
                                            type="checkbox"
                                            checked={data.remember}
                                            onChange={e => setData('remember', e.target.checked)}
                                            className="peer h-5 w-5 rounded border-slate-800 bg-slate-900 text-green-600 focus:ring-green-500/20 focus:ring-offset-slate-900 transition-all cursor-pointer opacity-0 absolute z-10"
                                        />
                                        <div className="h-5 w-5 rounded border border-slate-700 bg-slate-900 peer-checked:bg-green-600 peer-checked:border-green-600 transition-all flex items-center justify-center">
                                            <svg className="w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="4">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    </div>
                                    <label htmlFor="remember" className="text-xs font-bold text-slate-500 cursor-pointer select-none uppercase tracking-wide hover:text-slate-400 transition-colors">
                                        Mantener sesión iniciada
                                    </label>
                                </div>

                                {/* Submit Button */}
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full h-14 rounded-xl bg-green-600 text-base font-black text-white uppercase tracking-widest shadow-[0_10px_20px_-10px_rgba(34,197,94,0.5)] hover:bg-green-500 hover:shadow-[0_15px_25px_-10px_rgba(34,197,94,0.6)] active:scale-[0.97] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed group"
                                >
                                    {processing ? (
                                        <div className="flex items-center space-x-2">
                                            <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                                            <span>Validando...</span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center space-x-2">
                                            <LogIn size={20} className="transition-transform duration-300 group-hover:translate-x-1" />
                                            <span>Acceder</span>
                                        </div>
                                    )}
                                </Button>
                            </form>

                            {/* Decorative footer inside card */}
                            <div className="mt-10 pt-6 border-t border-slate-800 flex items-center justify-center space-x-3 text-slate-500 text-[10px] font-black uppercase tracking-[0.2em]">
                                <div className="h-px flex-1 bg-gradient-to-r from-transparent to-slate-800"></div>
                                <div className="flex items-center gap-2">
                                    <div className="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.8)]"></div>
                                    <span>Acceso Encriptado</span>
                                </div>
                                <div className="h-px flex-1 bg-gradient-to-l from-transparent to-slate-800"></div>
                            </div>
                        </div>
                    </div>

                    {/* Footer elements */}
                    <div className="text-center">
                        <p className="text-[10px] font-bold text-slate-600 uppercase tracking-[0.3em]">
                            &copy; {new Date().getFullYear()} Integra Plus &middot; Seguridad Nivel Corporativo
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
