import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Wifi, AlertTriangle, PowerOff, Power, KeyRound, Copy, Check, Gauge, Layers, CheckCircle2, Link2, ArrowRight, X, Settings } from 'lucide-react';
import CabeceraModulo from '@/components/cabecera-modulo';
import axios from 'axios';
import EmbeddedSignupButton from '@/components/EmbeddedSignupButton';
import ConectarInstagramButton from '@/components/ConectarInstagramButton';
import ConectarMessengerButton from '@/components/ConectarMessengerButton';
import { LogoCanal, EtiquetaCanal } from '@/components/logo-canal';
import CoexistenceSyncCard from '@/components/CoexistenceSyncCard';

export default function InstancesIndex({ instances, coexistenceSyncs = [], instagramDisponible = false, messengerDisponible = false }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editingInstance, setEditingInstance] = useState(null);
    // El token recién creado. Vive sólo en memoria y sólo hasta cerrar el aviso:
    // no hay dónde volver a verlo, y ese es justo el punto.
    const [tokenNuevo, setTokenNuevo] = useState(null);
    const [generando, setGenerando] = useState(null);

    // El diálogo de borrado: la instancia en cuestión, el resumen que pide al
    // servidor y el nombre que hay que teclear para confirmar.
    const [deletingInstance, setDeletingInstance] = useState(null);
    const [deleteSummary, setDeleteSummary] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState('');
    const [deleteError, setDeleteError] = useState(null);

    const [createForm, setCreateForm] = useState({ name: '', phone_number_id: '', waba_id: '', display_phone_number: '', access_token: '' });
    const [editForm, setEditForm] = useState({ name: '', phone_number_id: '', waba_id: '', display_phone_number: '', access_token: '', active: false });

    async function generarToken(instance) {
        const rotando = !!instance.api_token_created_at;

        if (rotando && !confirm(
            `«${instance.name}» ya tiene un token. Si generas otro, el anterior deja de funcionar `
            + 'al instante y habrá que actualizar el ERP que lo esté usando. ¿Seguir?'
        )) return;

        setGenerando(instance.id);
        try {
            const res = await axios.post(route('instances.api-token', instance.id));
            setTokenNuevo({ instancia: instance.name, ...res.data });
            router.reload({ only: ['instances'] });
        } catch {
            alert('No se pudo generar el token.');
        } finally {
            setGenerando(null);
        }
    }

    function handleCreate(e) {
        e.preventDefault();
        router.post(route('instances.store'), createForm, {
            onSuccess: () => { setShowCreate(false); setCreateForm({ name: '', phone_number_id: '', waba_id: '', display_phone_number: '', access_token: '' }); },
        });
    }

    function handleEdit(e) {
        e.preventDefault();
        router.put(route('instances.update', editingInstance.id), editForm, {
            onSuccess: () => setEditingInstance(null),
        });
    }

    /**
     * Abrir el diálogo pide al servidor lo que se va a perder. Hasta que llega,
     * el botón de borrar sigue deshabilitado: nadie debería poder confirmar un
     * borrado irreversible antes de ver de qué tamaño es.
     */
    function openDelete(instance) {
        setDeletingInstance(instance);
        setDeleteSummary(null);
        setDeleteConfirm('');
        setDeleteError(null);

        fetch(route('instances.resumen-borrado', instance.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(r => (r.ok ? r.json() : Promise.reject(r)))
            .then(setDeleteSummary)
            .catch(() => setDeleteError('No se pudo consultar qué contiene esta instancia. Recarga la página antes de borrar nada.'));
    }

    function handleDelete(e) {
        e.preventDefault();
        router.delete(route('instances.destroy', deletingInstance.id), {
            data: { confirmacion: deleteConfirm },
            onSuccess: () => setDeletingInstance(null),
            onError: (errors) => setDeleteError(errors.confirmacion ?? 'No se pudo borrar la instancia.'),
        });
    }

    function handleDesconectar(instance) {
        router.post(route('instances.desconectar', instance.id), {}, { preserveScroll: true });
    }

    function handleReconectar(instance) {
        router.post(route('instances.reconectar', instance.id), {}, { preserveScroll: true });
    }

    function openEdit(instance) {
        // El token se deja vacío a propósito: ya no viaja al navegador, y el
        // servidor conserva el que hay si este campo llega vacío.
        setEditForm({ name: instance.name ?? '', phone_number_id: instance.phone_number_id ?? '', waba_id: instance.waba_id ?? '', display_phone_number: instance.display_phone_number ?? '', access_token: '', active: !!instance.active });
        setEditingInstance(instance);
    }

    const resumen = {
        total: instances.length,
        activas: instances.filter(i => estadoDe(i).clave === 'activa').length,
        conProblemas: instances.filter(i => ['sin-conexion', 'no-envia'].includes(estadoDe(i).clave)).length,
        inactivas: instances.filter(i => !i.active).length,
    };

    return (
        <>
            <Head title="Instancias" />
            <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-5 p-4 sm:p-6">
                <CabeceraModulo
                    icono={Settings}
                    titulo="Instancias"
                    descripcion="Tus números de WhatsApp y cuentas de Instagram y Messenger conectados al CRM."
                />

                {instances.length > 0 && (
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Cifra icono={Layers} etiqueta="Conectadas" valor={resumen.total} />
                        <Cifra icono={CheckCircle2} etiqueta="Funcionando" valor={resumen.activas} tono="success" />
                        <Cifra icono={AlertTriangle} etiqueta="Con problemas" valor={resumen.conProblemas} tono={resumen.conProblemas > 0 ? 'warning' : null} />
                        <Cifra icono={PowerOff} etiqueta="Desconectadas" valor={resumen.inactivas} />
                    </div>
                )}

                {/* Conectar con Facebook es el camino normal; "Nueva
                    Instancia" queda como respaldo para pegar los datos a
                    mano si la ventana de Meta falla o el entorno no la
                    tiene configurada. Van en su propio panel y no en la
                    cabecera: en fila junto al título eran seis botones que no
                    partían línea y en el móvil se salían de la pantalla. */}
                <section className="relative overflow-hidden rounded-2xl border bg-card p-4 shadow-xs sm:p-5">
                    <div aria-hidden="true" className="pointer-events-none absolute -right-16 -top-16 size-48 rounded-full bg-primary/15 blur-3xl" />
                    <div className="relative flex flex-col gap-4">
                        <div className="flex items-start gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                <Link2 className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="text-base font-bold text-foreground">Conectar un canal</h2>
                                <p className="text-sm text-muted-foreground">
                                    Inicia sesión con Meta y el número queda listo para enviar y recibir.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-start">
                            <EmbeddedSignupButton onConnected={() => router.reload({ only: ['instances', 'coexistenceSyncs'] })} />
                            <ConectarInstagramButton disponible={instagramDisponible} />
                            <ConectarMessengerButton disponible={messengerDisponible} />
                            <Button variant="ghost" onClick={() => setShowCreate(true)} className="gap-2 text-muted-foreground">
                                <Plus className="size-4" /> Añadir a mano
                            </Button>
                        </div>
                        {/* El tope de 250 mensajes al día es lo que más trae a
                            esta pantalla después de conectar, y el error de Meta
                            —«Spam Rate limit hit»— manda a buscar por el lado
                            equivocado. El enlace va aquí para que aparezca antes
                            de que llamen. */}
                        <Link
                            href="/instances/guia-limites-whatsapp"
                            className="group flex items-center gap-3 rounded-xl border border-warning/30 bg-warning/10 px-3 py-2.5 text-sm transition-colors hover:bg-warning/15"
                        >
                            <Gauge className="size-4 shrink-0 text-warning" />
                            <span className="min-w-0 flex-1 text-foreground">
                                <span className="font-semibold">¿Tus mensajes salen como «Fallido»?</span>{' '}
                                <span className="text-muted-foreground">Así se sube el límite diario de WhatsApp.</span>
                            </span>
                            <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                        </Link>
                    </div>
                </section>

                {instances.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed bg-card/50 px-6 py-16 text-center">
                        <span className="mb-4 flex size-14 items-center justify-center rounded-2xl bg-accent text-accent-foreground">
                            <Wifi className="size-7" />
                        </span>
                        <p className="text-lg font-bold text-foreground">Aún no hay canales conectados</p>
                        <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                            Conecta tu primer número de WhatsApp Business con los botones de arriba.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        {instances.map(instance => (
                            <TarjetaInstancia
                                key={instance.id}
                                instance={instance}
                                sync={coexistenceSyncs.find(s => s.instance_id === instance.id) ?? null}
                                generando={generando === instance.id}
                                onEditar={() => openEdit(instance)}
                                onToken={() => generarToken(instance)}
                                onDesconectar={() => handleDesconectar(instance)}
                                onReconectar={() => handleReconectar(instance)}
                                onEliminar={() => openDelete(instance)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {tokenNuevo && (
                <Modal
                    title="Token de la API"
                    description={`Para «${tokenNuevo.instancia}». Es la única vez que se ve.`}
                    onClose={() => setTokenNuevo(null)}
                >
                    <TokenRecienCreado datos={tokenNuevo} onCerrar={() => setTokenNuevo(null)} />
                </Modal>
            )}

            {showCreate && (
                <Modal title="Nueva Instancia" description="Conecta una nueva cuenta de WhatsApp Business" onClose={() => setShowCreate(false)}>
                    <form onSubmit={handleCreate} className="space-y-4">
                        <Field label="Nombre" value={createForm.name} onChange={v => setCreateForm(f => ({ ...f, name: v }))} required />
                        <Field label="Phone Number ID" value={createForm.phone_number_id} onChange={v => setCreateForm(f => ({ ...f, phone_number_id: v }))} required />
                        <Field label="WABA ID" value={createForm.waba_id} onChange={v => setCreateForm(f => ({ ...f, waba_id: v }))} required />
                        <Field label="Número de Teléfono" value={createForm.display_phone_number} onChange={v => setCreateForm(f => ({ ...f, display_phone_number: v }))} placeholder="+57 318..." />
                        <Field label="Access Token" value={createForm.access_token} onChange={v => setCreateForm(f => ({ ...f, access_token: v }))} placeholder="EAAI..." />
                        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row">
                            <Button type="submit" className="flex-1">Crear Instancia</Button>
                            <Button type="button" variant="outline" onClick={() => setShowCreate(false)}>Cancelar</Button>
                        </div>
                    </form>
                </Modal>
            )}

            {editingInstance && (
                <Modal title="Editar Instancia" description={`Modificar: ${editingInstance.name}`} onClose={() => setEditingInstance(null)}>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <Field label="Nombre" value={editForm.name} onChange={v => setEditForm(f => ({ ...f, name: v }))} required />
                        <Field label="Phone Number ID" value={editForm.phone_number_id} onChange={v => setEditForm(f => ({ ...f, phone_number_id: v }))} required />
                        <Field label="WABA ID" value={editForm.waba_id} onChange={v => setEditForm(f => ({ ...f, waba_id: v }))} required />
                        <Field label="Número de Teléfono" value={editForm.display_phone_number} onChange={v => setEditForm(f => ({ ...f, display_phone_number: v }))} placeholder="+57 318..." />
                        <Field label="Access Token" value={editForm.access_token} onChange={v => setEditForm(f => ({ ...f, access_token: v }))} placeholder="Déjalo vacío para conservar el actual" />
                        <label className="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" checked={editForm.active} onChange={e => setEditForm(f => ({ ...f, active: e.target.checked }))} className="rounded border-input size-4 accent-primary" />
                            <span className="text-sm text-foreground">Instancia Activa</span>
                        </label>
                        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row">
                            <Button type="submit" className="flex-1">Guardar Cambios</Button>
                            <Button type="button" variant="outline" onClick={() => setEditingInstance(null)}>Cancelar</Button>
                        </div>
                    </form>
                </Modal>
            )}

            {deletingInstance && (
                <Modal
                    title="Eliminar instancia"
                    description="Esta acción no se puede deshacer"
                    onClose={() => setDeletingInstance(null)}
                >
                    <form onSubmit={handleDelete} className="space-y-4">
                        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                            <div className="flex gap-2.5">
                                <AlertTriangle className="size-4 mt-0.5 shrink-0 text-destructive" />
                                <div className="space-y-2 text-sm">
                                    <p className="font-medium text-foreground">
                                        Se borrará «{deletingInstance.name}» y, con ella:
                                    </p>
                                    {deleteSummary ? (
                                        <ul className="space-y-1 text-muted-foreground">
                                            <li>· <strong className="text-foreground">{deleteSummary.conversaciones}</strong> conversaciones</li>
                                            <li>· <strong className="text-foreground">{deleteSummary.mensajes}</strong> mensajes</li>
                                            {deleteSummary.campanas > 0 && (
                                                <li>· <strong className="text-foreground">{deleteSummary.campanas}</strong> campañas</li>
                                            )}
                                        </ul>
                                    ) : (
                                        <p className="text-muted-foreground italic">Consultando qué contiene…</p>
                                    )}
                                    {deleteSummary && (
                                        <p className="text-muted-foreground">
                                            Los <strong className="text-foreground">{deleteSummary.contactos_empresa}</strong> contactos
                                            de la empresa <strong className="text-foreground">no</strong> se borran: son de la empresa, no del número.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>

                        {deleteSummary?.historial_importado && (
                            <div className="rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm">
                                <p className="font-medium text-foreground">Este número importó su historial por coexistencia.</p>
                                <p className="text-muted-foreground mt-1">
                                    Meta solo permite una importación por número: el historial no se podrá volver a traer.
                                    Recuperarlo exigiría que el cliente desconecte el número desde su app de WhatsApp Business
                                    y repetir el registro completo.
                                </p>
                            </div>
                        )}

                        <div className="rounded-lg bg-muted/50 p-4 text-sm">
                            <p className="text-muted-foreground">
                                Borrar la instancia <strong className="text-foreground">no desconecta el número en Meta</strong>.
                                Para eso, el cliente debe entrar en su app de WhatsApp Business →
                                Configuración → Cuenta → Plataforma empresarial → Desconectar. Mientras no lo haga,
                                sus mensajes seguirán llegando al servidor y se descartarán.
                            </p>
                        </div>

                        <p className="text-sm text-muted-foreground">
                            Si solo quieres dejar de usar el número, <strong className="text-foreground">desconéctala</strong> en
                            lugar de borrarla: se conserva todo y puedes volver cuando quieras.
                        </p>

                        <Field
                            label={`Escribe «${deletingInstance.name}» para confirmar`}
                            value={deleteConfirm}
                            onChange={setDeleteConfirm}
                            placeholder={deletingInstance.name}
                        />

                        {deleteError && <p className="text-sm font-medium text-destructive">{deleteError}</p>}

                        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row">
                            <Button
                                type="submit"
                                variant="destructive"
                                className="flex-1"
                                disabled={!deleteSummary || deleteConfirm.trim().toLowerCase() !== deletingInstance.name.trim().toLowerCase()}
                            >
                                Eliminar definitivamente
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => { handleDesconectar(deletingInstance); setDeletingInstance(null); }}
                            >
                                Mejor desconectar
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

/**
 * El estado de una línea, de un solo sitio para la pastilla, la franja de color
 * y las cifras de arriba.
 *
 * "Activa" es una casilla nuestra; la salud es lo que dice Meta. Mostrar solo la
 * primera fue lo que dejó cinco empresas en verde durante meses sin recibir un
 * mensaje.
 */
function estadoDe(instance) {
    if (!instance.active) {
        return { clave: 'inactiva', etiqueta: 'Desconectada', pastilla: 'bg-muted text-muted-foreground', punto: 'bg-muted-foreground', franja: 'bg-border' };
    }
    if (instance.health_status === 'unreachable') {
        return { clave: 'sin-conexion', etiqueta: 'Sin conexión', pastilla: 'bg-destructive/10 text-destructive', punto: 'bg-destructive', franja: 'bg-destructive' };
    }
    if (instance.puede_enviar && instance.puede_enviar !== 'AVAILABLE') {
        return { clave: 'no-envia', etiqueta: 'No envía', pastilla: 'bg-warning/15 text-warning', punto: 'bg-warning', franja: 'bg-warning' };
    }
    return { clave: 'activa', etiqueta: 'Activa', pastilla: 'bg-success/15 text-success', punto: 'bg-success animate-pulse', franja: 'bg-primary' };
}

function Cifra({ icono: Icono, etiqueta, valor, tono = null }) {
    const color = { success: 'text-success', warning: 'text-warning' }[tono] ?? 'text-muted-foreground';

    return (
        <div className={`rounded-xl border bg-card p-3 shadow-xs sm:p-4 ${tono === 'warning' ? 'border-warning/40' : ''}`}>
            <div className="flex items-center gap-2">
                <Icono className={`size-4 ${color}`} />
                <p className="truncate text-[10px] font-bold uppercase tracking-wider text-muted-foreground sm:text-[11px] sm:tracking-widest">{etiqueta}</p>
            </div>
            <p className="mt-1.5 text-2xl font-black tracking-tight text-foreground tabular-nums sm:text-3xl">{valor}</p>
        </div>
    );
}

function TarjetaInstancia({ instance, sync, generando, onEditar, onToken, onDesconectar, onReconectar, onEliminar }) {
    const estado = estadoDe(instance);

    return (
        <article className="flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-card shadow-xs transition-shadow hover:shadow-md">
            <div className={`h-1 ${estado.franja}`} />

            <div className="flex flex-1 flex-col gap-4 p-4 sm:p-5">
                {/* El logo de la plataforma en vez del icono de wifi que
                    llevaban todas: con WhatsApp e Instagram en la misma
                    pantalla no se distinguía cuál era cuál sin leer la letra
                    pequeña. */}
                <div className="flex items-start gap-3">
                    <LogoCanal
                        instancia={instance}
                        apagado={estado.clave === 'inactiva' || estado.clave === 'sin-conexion'}
                        className="size-12"
                    />
                    <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-2">
                            <p className="line-clamp-2 break-words text-base font-bold leading-snug text-foreground" title={instance.name ?? undefined}>{instance.name ?? 'Sin nombre'}</p>
                            <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ${estado.pastilla}`}>
                                <span className={`size-1.5 rounded-full ${estado.punto}`} />
                                {estado.etiqueta}
                            </span>
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <EtiquetaCanal instancia={instance} />
                            {/* El número en WhatsApp, y nada en Instagram, donde
                                el nombre de la línea YA es la cuenta. */}
                            {instance.channel !== 'instagram' && instance.display_phone_number && (
                                <span className="text-sm font-medium tabular-nums text-foreground/80">{instance.display_phone_number}</span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Importación de contactos e historial. Sólo aparece en
                    números que vinieron de la app del celular. */}
                <CoexistenceSyncCard instanceId={instance.id} initial={sync} />

                {/* Conectado no es lo mismo que poder enviar. Una cuenta sana a
                    la que se le venció la tarjeta del portafolio responde a todo
                    y no entrega nada: el CRM no puede leer el medio de pago —Meta
                    se lo niega a quien no es BSP— pero sí la consecuencia. */}
                {estado.clave === 'no-envia' && (
                    <Aviso tono="warning" titulo="Meta no está dejando enviar por esta cuenta.">
                        <p>{instance.puede_enviar_motivo ?? 'Meta no dio un motivo.'}</p>
                        <p className="mt-1">
                            La causa más común es el medio de pago del portafolio. Revísalo en el
                            Administrador comercial de Meta.
                        </p>
                    </Aviso>
                )}

                {estado.clave === 'sin-conexion' && (
                    <Aviso tono="destructive" titulo="Meta no responde por esta cuenta.">
                        <p>{instance.health_error ?? 'El token o el número ya no existen.'}</p>
                        <p className="mt-1">No entran ni salen mensajes. Vuelve a conectarla desde «Conectar un canal».</p>
                    </Aviso>
                )}

                {/* Los identificadores técnicos, pequeños y copiables: se
                    necesitan para soporte, pero no son lo que el cliente viene a
                    ver. Una cuenta de Instagram no tiene número ni WABA, y
                    pintar esas etiquetas vacías lo iba a ver el revisor del App
                    Review en el screencast. */}
                <dl className="divide-y divide-border/60 rounded-xl border bg-muted/30 text-xs">
                    {instance.channel === 'instagram' ? (
                        <IdCopiable etiqueta="ID de cuenta" valor={instance.external_account_id} />
                    ) : (
                        <>
                            <IdCopiable etiqueta="Phone ID" valor={instance.phone_number_id} />
                            <IdCopiable etiqueta="WABA ID" valor={instance.waba_id} />
                        </>
                    )}
                    <div className="flex items-center justify-between gap-3 px-3 py-2">
                        <dt className="shrink-0 text-muted-foreground">Token API</dt>
                        <dd className={`truncate font-medium ${instance.api_token_created_at ? 'text-success' : 'text-muted-foreground'}`}>
                            {instance.api_token_created_at
                                ? `Generado el ${new Date(instance.api_token_created_at).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })}`
                                : 'Sin generar'}
                        </dd>
                    </div>
                </dl>
            </div>

            {/* `flex-wrap` y un ancho mínimo por botón: sin envolver, los
                cuatro se salían de la tarjeta —el de eliminar quedaba fuera del
                borde, flotando sobre la tarjeta de al lado— porque el texto no
                parte y «Desconectar» y «Rotar token» no caben en una columna
                estrecha. */}
            <footer className="flex flex-wrap items-center gap-2 border-t bg-muted/20 px-4 py-3 sm:px-5">
                <Button variant="outline" size="sm" className="min-w-[6rem] flex-1 gap-1.5" onClick={onEditar}>
                    <Pencil className="size-3.5" /> Editar
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className="min-w-[7.5rem] flex-1 gap-1.5"
                    disabled={generando}
                    title={instance.api_token_created_at
                        ? 'Generar un token nuevo (el actual dejará de servir)'
                        : 'Generar el token de la API'}
                    onClick={onToken}
                >
                    <KeyRound className="size-3.5" />
                    {instance.api_token_created_at ? 'Rotar token' : 'Token API'}
                </Button>
                {instance.active ? (
                    <Button variant="outline" size="sm" className="min-w-[8rem] flex-1 gap-1.5" onClick={onDesconectar} title="Deja de enviar y recibir, sin borrar nada">
                        <PowerOff className="size-3.5" /> Desconectar
                    </Button>
                ) : (
                    <Button size="sm" className="min-w-[8rem] flex-1 gap-1.5" onClick={onReconectar} title="Volver a activarla">
                        <Power className="size-3.5" /> Reconectar
                    </Button>
                )}
                {/* El de eliminar no se estira ni se encoge: es el único
                    destructivo de la fila y conviene que tenga siempre el mismo
                    tamaño y el mismo sitio. */}
                <Button
                    variant="outline"
                    size="sm"
                    className="shrink-0 px-2.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    onClick={onEliminar}
                    title="Eliminar definitivamente"
                    aria-label="Eliminar definitivamente"
                >
                    <Trash2 className="size-3.5" />
                </Button>
            </footer>
        </article>
    );
}

function Aviso({ tono, titulo, children }) {
    const clases = tono === 'destructive'
        ? 'border-destructive/30 bg-destructive/10 text-destructive'
        : 'border-warning/40 bg-warning/10 text-warning';

    return (
        <div className={`flex gap-2.5 rounded-xl border px-3 py-2.5 text-xs ${clases}`}>
            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0">
                <p className="font-semibold">{titulo}</p>
                <div className="mt-0.5 opacity-90">{children}</div>
            </div>
        </div>
    );
}

function IdCopiable({ etiqueta, valor }) {
    const [copiado, setCopiado] = useState(false);

    async function copiar() {
        try {
            await navigator.clipboard.writeText(valor);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        } catch {
            // Sin permiso de portapapeles queda seleccionarlo a mano.
        }
    }

    return (
        <div className="flex items-center justify-between gap-3 px-3 py-2">
            <dt className="shrink-0 text-muted-foreground">{etiqueta}</dt>
            <dd className="flex min-w-0 items-center gap-1.5">
                <span className="truncate font-mono text-foreground select-all">{valor ?? '—'}</span>
                {valor && (
                    <button
                        type="button"
                        onClick={copiar}
                        title="Copiar"
                        aria-label={`Copiar ${etiqueta}`}
                        className="shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        {copiado ? <Check className="size-3.5 text-success" /> : <Copy className="size-3.5" />}
                    </button>
                )}
            </dd>
        </div>
    );
}

/**
 * En el móvil sale desde abajo y a lo ancho; en pantalla grande, centrado. El
 * alto va topado y con scroll: el de borrar trae cuatro bloques de aviso y en
 * un teléfono dejaba el botón de confirmar fuera de la pantalla, sin forma de
 * llegar a él.
 */
function Modal({ title, description, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm sm:items-center sm:p-4" onClick={onClose}>
            <div
                className="flex max-h-[92dvh] w-full flex-col rounded-t-2xl border bg-card shadow-2xl sm:max-w-md sm:rounded-2xl"
                onClick={e => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
            >
                <div className="flex items-start justify-between gap-3 border-b px-5 py-4 sm:px-6">
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-foreground">{title}</h2>
                        {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="-mr-1 shrink-0 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-5" />
                    </button>
                </div>
                <div className="overflow-y-auto px-5 py-5 sm:px-6">
                    {children}
                </div>
            </div>
        </div>
    );
}

function Field({ label, value, onChange, type = 'text', required = false, placeholder = '' }) {
    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">{label}</label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                required={required}
                placeholder={placeholder}
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
            />
        </div>
    );
}

InstancesIndex.layout = page => <AppLayout breadcrumb={['Instancias']}>{page}</AppLayout>;

/**
 * El token, enseñado una sola vez.
 *
 * Se guarda hasheado, así que ni el servidor puede volver a mostrarlo. Es lo
 * que lo convierte en un secreto de verdad, a diferencia del phone_number_id
 * que se usaba antes y que está escrito en esta misma pantalla.
 */
function TokenRecienCreado({ datos, onCerrar }) {
    const [copiado, setCopiado] = useState(false);

    async function copiar() {
        try {
            await navigator.clipboard.writeText(datos.token);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        } catch {
            // Sin permiso de portapapeles queda seleccionarlo a mano, que por
            // eso el token se muestra en un campo y no en un párrafo.
        }
    }

    return (
        <div className="space-y-4">
            {datos.reemplaza_uno_anterior && (
                <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">
                    El token anterior ya no sirve. Actualiza el ERP que lo estuviera usando o sus
                    peticiones empezarán a fallar.
                </div>
            )}

            <div className="flex gap-2">
                <input
                    readOnly
                    value={datos.token}
                    onFocus={e => e.target.select()}
                    className="flex-1 h-10 rounded-md border border-input bg-muted px-3 font-mono text-xs"
                />
                <Button variant="outline" onClick={copiar} className="gap-1.5 shrink-0">
                    {copiado ? <Check className="size-4" /> : <Copy className="size-4" />}
                    {copiado ? 'Copiado' : 'Copiar'}
                </Button>
            </div>

            <div className="rounded-lg bg-muted/50 px-3 py-2 text-xs space-y-1">
                <p className="text-muted-foreground">Se manda en la cabecera de cada petición:</p>
                <p className="font-mono text-foreground break-all">X-Instance-Token: {datos.token}</p>
            </div>

            <p className="text-sm text-muted-foreground">
                Guárdalo ahora. No se puede volver a ver: si se pierde, hay que generar otro.
            </p>

            <div className="flex justify-end">
                <Button onClick={onCerrar}>Ya lo guardé</Button>
            </div>
        </div>
    );
}
