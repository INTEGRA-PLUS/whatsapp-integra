import { Head } from '@inertiajs/react';

/**
 * El marco de las pantallas de acceso: fondo, logo y tarjeta.
 *
 * Nace de `pages/Auth/Login.jsx`, que lo llevaba escrito dentro. Las pantallas
 * de recuperar contraseña lo necesitan igual, y tenerlo tres veces copiado
 * garantizaba que se separasen: un degradado retocado en el login y no en las
 * otras se nota justo cuando el cliente está perdido y mirando con lupa.
 *
 * El login sigue con el suyo propio a propósito: es la pantalla por la que
 * entra todo el mundo y no compensa moverla para un refactor de estilo. Si
 * alguien la toca en el futuro, este es el sitio donde encaja.
 */
export default function AuthShell({ title, heading, description, children, footer }) {
    return (
        <div className="relative min-h-screen font-sans antialiased text-slate-200 overflow-hidden bg-[#020817]">
            <Head title={title} />

            <div className="fixed inset-0 -z-10 h-full w-full">
                <div className="absolute inset-0 bg-[radial-gradient(circle_800px_at_50%_-20%,#0f172a,transparent)]"></div>
                <div className="absolute inset-0 bg-[radial-gradient(circle_600px_at_80%_80%,#062d1d,transparent)] opacity-40"></div>
                <div className="absolute inset-0 bg-[linear-gradient(to_right,#0f172a11_1px,transparent_1px),linear-gradient(to_bottom,#0f172a11_1px,transparent_1px)] bg-[size:4rem_4rem] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_50%,#000_70%,transparent_100%)]"></div>
            </div>

            <div className="flex min-h-screen flex-col items-center justify-center p-6 sm:p-12">
                <div className="w-full max-w-md space-y-8">
                    <div className="flex flex-col items-center text-center">
                        <div className="mb-8 overflow-hidden rounded-[28px] ring-1 ring-white/10 shadow-[0_0_60px_-15px_rgba(34,197,94,0.35)]">
                            <img
                                src="/logo.png"
                                alt="Integra CRM — Todo tu WhatsApp, en un solo lugar"
                                className="h-40 w-auto"
                            />
                        </div>
                        <h1 className="sr-only">Integra CRM</h1>
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-slate-800 bg-[#0f172a]/80 p-[1px] shadow-[0_0_50px_-12px_rgba(0,0,0,0.5)] backdrop-blur-xl">
                        <div className="rounded-[23px] bg-[#0f172a] p-8 sm:p-10 shadow-inner">
                            <div className="mb-8">
                                <h2 className="text-xl font-black text-white uppercase tracking-tight">{heading}</h2>
                                {description && (
                                    <p className="mt-3 text-sm leading-relaxed text-slate-400">{description}</p>
                                )}
                            </div>

                            {children}

                            {footer && (
                                <div className="mt-8 pt-6 border-t border-slate-800 text-center">{footer}</div>
                            )}
                        </div>
                    </div>

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

/** Los estilos de campo del login, para que los formularios no se separen. */
export const inputClass =
    'block w-full rounded-xl border border-slate-800 bg-slate-900/50 py-4 pl-12 pr-4 text-base text-white placeholder-slate-600 transition-all duration-300 focus:border-green-500/50 focus:outline-none focus:ring-4 focus:ring-green-500/5 focus:bg-slate-900';

export const labelClass = 'text-xs font-bold text-slate-400 uppercase tracking-wider ml-1';

export const errorClass = 'text-xs font-semibold text-red-400 mt-1 ml-1';

export const submitClass =
    'w-full h-14 rounded-xl bg-green-600 text-base font-black text-white uppercase tracking-widest shadow-[0_10px_20px_-10px_rgba(34,197,94,0.5)] hover:bg-green-500 active:scale-[0.97] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed';
