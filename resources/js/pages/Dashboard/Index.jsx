import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import ActividadQuincena from '@/components/dashboard/ActividadQuincena';
import Flujograma from '@/components/dashboard/Flujograma';
import PuestaEnMarcha from '@/components/dashboard/PuestaEnMarcha';
import {
    AlertTriangle, ArrowRight, Contact, Inbox, Layers, MessageSquare,
    Megaphone, Send, UserX, Wifi, WifiOff, Clock,
} from 'lucide-react';

/**
 * La portada del producto.
 *
 * Sustituye a una redirección al chat. Un cliente recién conectado abría la
 * aplicación y veía una lista de conversaciones vacía, sin una sola pista de
 * qué le faltaba por configurar ni de cómo se supone que se trabaja aquí; la
 * llamada a soporte era casi siempre la misma pregunta.
 *
 * Se adapta a los permisos de quien mira porque las dos personas que entran
 * aquí no vienen a lo mismo: el dueño quiere saber qué le falta y si su equipo
 * está respondiendo; el agente quiere saber qué tiene encima y entrar al chat.
 * Enseñarle al agente seis pasos de configuración que no puede tocar sólo le
 * hace pensar que la herramienta está a medias.
 */
export default function DashboardIndex({ metricas, actividad, canales, puestaEnMarcha }) {
    const { auth } = usePage().props;
    const permisos = auth?.user?.permissions ?? [];
    const puede = p => permisos.includes(p);

    // Configurar es cosa de quien puede tocar las instancias. Es el permiso que
    // separa al dueño del agente mejor que ningún otro: quien conecta el
    // WhatsApp es quien monta el resto.
    const configura = puede('instances.view');

    const sinCanal = canales.every(c => !c.activa);
    const caidos = canales.filter(c => c.activa && c.salud && c.salud !== 'ok');

    return (
        <>
            <Head title="Inicio" />

            <div className="p-4 lg:p-6 space-y-5 max-w-[1600px] mx-auto">
                <header>
                    <h1 className="text-2xl font-black tracking-tight text-foreground">
                        Hola, {auth?.user?.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {auth?.user?.company_name}
                    </p>
                </header>

                {/* Los dos avisos que no pueden esperar a que alguien baje la
                    pantalla: sin canal no funciona nada, y un canal caído deja
                    de recibir sin decírselo a nadie. */}
                {sinCanal && configura && (
                    <Aviso
                        tono="warning"
                        icono={WifiOff}
                        titulo="Tu WhatsApp todavía no está conectado"
                        detalle="Hasta que conectes un número, no entra ni sale ningún mensaje."
                        accion="Conectar ahora"
                        href={route('instances.index')}
                    />
                )}

                {caidos.length > 0 && (
                    <Aviso
                        tono="destructive"
                        icono={AlertTriangle}
                        titulo={caidos.length === 1
                            ? `El número ${caidos[0].numero} no está respondiendo`
                            : `${caidos.length} de tus números no están respondiendo`}
                        detalle="Mientras esté así, los mensajes de tus clientes no llegan al CRM."
                        accion={configura ? 'Revisar' : null}
                        href={configura ? route('instances.index') : null}
                    />
                )}

                {puede('chat.view') && (
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <Metrica
                            icono={Inbox}
                            etiqueta="Conversaciones abiertas"
                            valor={metricas.abiertas}
                            href={route('chat.index')}
                        />
                        <Metrica
                            icono={UserX}
                            etiqueta="Sin asignar"
                            valor={metricas.sinAsignar}
                            // Sin asignar es el único número de la fila que pide
                            // hacer algo: son clientes escribiéndole a nadie.
                            alerta={metricas.sinAsignar > 0}
                            href={route('chat.index')}
                        />
                        <Metrica icono={MessageSquare} etiqueta="Recibidos hoy" valor={metricas.recibidosHoy} />
                        <Metrica icono={Send} etiqueta="Enviados hoy" valor={metricas.enviadosHoy} />
                    </div>
                )}

                <div className="grid grid-cols-1 xl:grid-cols-3 gap-5">
                    <div className="xl:col-span-2 space-y-5">
                        {puede('chat.view') && <ActividadQuincena datos={actividad} />}

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            {puede('chat.view') && (
                                <>
                                    <Secundaria icono={Clock} etiqueta="Estancadas más de 7 días" valor={metricas.estancadas} />
                                    <Secundaria icono={MessageSquare} etiqueta="Con mensajes sin leer" valor={metricas.sinLeer} />
                                </>
                            )}
                            {puede('contacts.view') && (
                                <Secundaria icono={Contact} etiqueta="Contactos" valor={metricas.contactos} />
                            )}
                        </div>

                        {/* Números y atajos van en esta columna, no junto a la
                            puesta en marcha: allí dejaban la izquierda mucho más
                            corta que la derecha y la pantalla se quedaba con un
                            hueco vacío del alto de media gráfica. */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            {canales.length > 0 && configura && <Canales canales={canales} />}
                            <AccesosRapidos puede={puede} />
                        </div>
                    </div>

                    <div className="space-y-5">
                        {configura && (
                            <PuestaEnMarcha
                                datos={puestaEnMarcha}
                                rutas={Object.fromEntries(
                                    (puestaEnMarcha?.pasos ?? []).map(p => [p.clave, route(p.ruta)]),
                                )}
                            />
                        )}
                    </div>
                </div>

                <Flujograma puestaEnMarcha={configura ? puestaEnMarcha : null} />
            </div>
        </>
    );
}

function Aviso({ tono, icono: Icono, titulo, detalle, accion, href }) {
    const tonos = {
        warning: 'border-warning/40 bg-warning/10 text-warning',
        destructive: 'border-destructive/40 bg-destructive/10 text-destructive',
    };

    return (
        <div className={`flex flex-wrap items-center gap-3 rounded-xl border p-4 ${tonos[tono]}`}>
            <Icono className="size-5 shrink-0" />
            <div className="min-w-0 flex-1">
                <p className="text-sm font-bold text-foreground">{titulo}</p>
                <p className="text-xs text-muted-foreground">{detalle}</p>
            </div>
            {accion && href && (
                <Link
                    href={href}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-bold text-primary-foreground hover:opacity-90 transition-opacity"
                >
                    {accion}
                    <ArrowRight className="size-3.5" />
                </Link>
            )}
        </div>
    );
}

function Metrica({ icono: Icono, etiqueta, valor, alerta = false, href }) {
    const cuerpo = (
        <div className={[
            'h-full rounded-xl border p-4 transition-colors',
            alerta ? 'border-warning/40 bg-warning/5' : 'border-border bg-card',
            href ? 'hover:border-primary/40' : '',
        ].join(' ')}>
            <div className="flex items-center gap-2 mb-2">
                <Icono className={`size-4 ${alerta ? 'text-warning' : 'text-muted-foreground'}`} />
                <p className="text-[11px] font-bold uppercase tracking-widest text-muted-foreground leading-tight">
                    {etiqueta}
                </p>
            </div>
            <p className="text-3xl font-black tracking-tight text-foreground tabular-nums">{valor}</p>
        </div>
    );

    return href ? <Link href={href} className="block h-full">{cuerpo}</Link> : cuerpo;
}

function Secundaria({ icono: Icono, etiqueta, valor }) {
    return (
        <div className="rounded-xl border border-border bg-card p-3 flex items-center gap-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icono className="size-4" />
            </span>
            <div className="min-w-0">
                <p className="text-lg font-black tracking-tight text-foreground tabular-nums leading-none">{valor}</p>
                <p className="text-[11px] text-muted-foreground leading-tight mt-1">{etiqueta}</p>
            </div>
        </div>
    );
}

function Canales({ canales }) {
    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <h2 className="text-sm font-black tracking-tight text-foreground mb-3">Tus números</h2>
            <ul className="space-y-2">
                {canales.map(canal => {
                    const bien = canal.activa && (!canal.salud || canal.salud === 'ok');

                    return (
                        <li key={canal.id} className="flex items-center gap-2.5">
                            {/* Icono distinto y texto de estado, no sólo un punto de
                                color: «conectado» y «caído» tienen que poder leerse. */}
                            {bien
                                ? <Wifi className="size-4 shrink-0 text-success" />
                                : <WifiOff className="size-4 shrink-0 text-destructive" />}
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-bold text-foreground truncate">{canal.nombre}</p>
                                <p className="text-[11px] text-muted-foreground font-mono">{canal.numero}</p>
                            </div>
                            <span className={[
                                'text-[10px] font-bold uppercase tracking-widest shrink-0',
                                bien ? 'text-success' : 'text-destructive',
                            ].join(' ')}>
                                {canal.activa ? (bien ? 'Conectado' : 'Caído') : 'Apagado'}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function AccesosRapidos({ puede }) {
    const accesos = [
        { titulo: 'Ir al chat', icono: MessageSquare, ruta: 'chat.index', permiso: 'chat.view' },
        { titulo: 'Abrir el tablero', icono: Layers, ruta: 'chat.kanban', permiso: 'crm.view' },
        { titulo: 'Ver contactos', icono: Contact, ruta: 'contacts.index', permiso: 'contacts.view' },
        { titulo: 'Crear campaña', icono: Megaphone, ruta: 'campaigns.index', permiso: 'campaigns.view' },
    ].filter(a => puede(a.permiso));

    if (accesos.length === 0) {
        return null;
    }

    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <h2 className="text-sm font-black tracking-tight text-foreground mb-3">Atajos</h2>
            <div className="grid grid-cols-2 gap-2">
                {accesos.map(acceso => (
                    <Link
                        key={acceso.ruta}
                        href={route(acceso.ruta)}
                        className="flex items-center gap-2 rounded-lg border border-border p-2.5 text-xs font-bold text-foreground hover:border-primary/50 hover:bg-primary/5 transition-colors"
                    >
                        <acceso.icono className="size-4 shrink-0 text-muted-foreground" />
                        <span className="truncate">{acceso.titulo}</span>
                    </Link>
                ))}
            </div>
        </section>
    );
}

DashboardIndex.layout = page => <AppLayout breadcrumb={['Inicio']}>{page}</AppLayout>;
