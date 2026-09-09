import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Lock, Mail, KeyRound } from 'lucide-react';
import AuthShell, { inputClass, labelClass, errorClass, submitClass } from '@/components/auth-shell';

/**
 * La pantalla a la que lleva el enlace del correo.
 *
 * El correo llega con el token y el email en la URL, así que el campo de correo
 * viene relleno; se deja visible y editable porque el enlace se abre a veces en
 * otro dispositivo y conviene ver a qué cuenta se le está cambiando.
 */
export default function ResetPassword({ token, email }) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        post(route('password.update'));
    }

    return (
        <AuthShell
            title="Nueva contraseña"
            heading="Pon una contraseña nueva"
            description="Mínimo 8 caracteres. Al guardarla se cerrarán las sesiones que quedaran abiertas en otros dispositivos."
        >
            <form className="space-y-6" onSubmit={handleSubmit}>
                <div className="space-y-2">
                    <label htmlFor="email" className={labelClass}>
                        Correo Electrónico
                    </label>
                    <div className="relative group">
                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-muted-foreground group-focus-within:text-success transition-colors duration-300">
                            <Mail size={18} />
                        </div>
                        <input
                            id="email"
                            type="email"
                            autoComplete="email"
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className={inputClass}
                        />
                    </div>
                    {errors.email && <p className={errorClass}>{errors.email}</p>}
                </div>

                <div className="space-y-2">
                    <div className="flex items-center justify-between ml-1">
                        <label htmlFor="password" className={labelClass.replace(' ml-1', '')}>
                            Contraseña nueva
                        </label>
                        <button
                            type="button"
                            onClick={() => setShowPassword(!showPassword)}
                            className="text-[10px] font-black uppercase tracking-tighter text-muted-foreground hover:text-muted-foreground transition-colors"
                        >
                            {showPassword ? 'Ocultar' : 'Mostrar'}
                        </button>
                    </div>
                    <div className="relative group">
                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-muted-foreground group-focus-within:text-success transition-colors duration-300">
                            <Lock size={18} />
                        </div>
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="new-password"
                            autoFocus
                            required
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="••••••••"
                            className={inputClass}
                        />
                    </div>
                    {errors.password && <p className={errorClass}>{errors.password}</p>}
                </div>

                <div className="space-y-2">
                    <label htmlFor="password_confirmation" className={labelClass}>
                        Repite la contraseña
                    </label>
                    <div className="relative group">
                        <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-muted-foreground group-focus-within:text-success transition-colors duration-300">
                            <KeyRound size={18} />
                        </div>
                        <input
                            id="password_confirmation"
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="new-password"
                            required
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            placeholder="••••••••"
                            className={inputClass}
                        />
                    </div>
                    {errors.password_confirmation && <p className={errorClass}>{errors.password_confirmation}</p>}
                </div>

                <Button type="submit" disabled={processing} className={submitClass}>
                    {processing ? (
                        <div className="flex items-center space-x-2">
                            <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                            <span>Guardando...</span>
                        </div>
                    ) : (
                        <span>Guardar contraseña</span>
                    )}
                </Button>
            </form>
        </AuthShell>
    );
}
