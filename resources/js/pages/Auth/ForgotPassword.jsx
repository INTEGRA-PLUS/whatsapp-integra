import { useForm, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Mail, ArrowLeft, Send, CheckCircle2 } from 'lucide-react';
import AuthShell, { inputClass, labelClass, errorClass, submitClass } from '@/components/auth-shell';

/**
 * Pedir el enlace para poner una contraseña nueva.
 *
 * El mensaje de éxito es deliberadamente ambiguo ("si ese correo tiene una
 * cuenta activa..."): decir "no existe" convertiría esta pantalla en una forma
 * de averiguar qué correos tienen cuenta en la plataforma.
 */
export default function ForgotPassword() {
    const { status } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function handleSubmit(e) {
        e.preventDefault();
        post(route('password.email'));
    }

    return (
        <AuthShell
            title="Recuperar contraseña"
            heading="¿Olvidaste tu contraseña?"
            description="Escribe el correo con el que entras y te mandamos un enlace para poner una nueva."
            footer={
                <Link
                    href={route('login')}
                    className="inline-flex items-center gap-2 text-[10px] font-black text-slate-500 hover:text-green-400 transition-colors uppercase tracking-[0.2em]"
                >
                    <ArrowLeft size={14} />
                    Volver a iniciar sesión
                </Link>
            }
        >
            {status ? (
                <div className="rounded-xl border border-green-500/20 bg-green-500/5 p-5">
                    <div className="flex gap-3">
                        <CheckCircle2 size={20} className="mt-0.5 shrink-0 text-green-500" />
                        <p className="text-sm leading-relaxed text-slate-300">{status}</p>
                    </div>
                </div>
            ) : (
                <form className="space-y-6" onSubmit={handleSubmit}>
                    <div className="space-y-2">
                        <label htmlFor="email" className={labelClass}>
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
                                autoFocus
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="correo@ejemplo.com"
                                className={inputClass}
                            />
                        </div>
                        {errors.email && <p className={errorClass}>{errors.email}</p>}
                    </div>

                    <Button type="submit" disabled={processing} className={submitClass}>
                        {processing ? (
                            <div className="flex items-center space-x-2">
                                <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                                <span>Enviando...</span>
                            </div>
                        ) : (
                            <div className="flex items-center space-x-2">
                                <Send size={18} />
                                <span>Enviar enlace</span>
                            </div>
                        )}
                    </Button>
                </form>
            )}
        </AuthShell>
    );
}
