import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Wifi, WifiOff, KeyRound, Copy, Check } from 'lucide-react';
import axios from 'axios';
import EmbeddedSignupButton from '@/components/EmbeddedSignupButton';
import CoexistenceSyncCard from '@/components/CoexistenceSyncCard';

export default function InstancesIndex({ instances, coexistenceSyncs = [] }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editingInstance, setEditingInstance] = useState(null);
    // El token recién creado. Vive sólo en memoria y sólo hasta cerrar el aviso:
    // no hay dónde volver a verlo, y ese es justo el punto.
    const [tokenNuevo, setTokenNuevo] = useState(null);
    const [generando, setGenerando] = useState(null);

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

    function handleDelete(instance) {
        if (!confirm('¿Eliminar esta instancia?')) return;
        router.delete(route('instances.destroy', instance.id));
    }

    function openEdit(instance) {
        setEditForm({ name: instance.name ?? '', phone_number_id: instance.phone_number_id ?? '', waba_id: instance.waba_id ?? '', display_phone_number: instance.display_phone_number ?? '', access_token: instance.access_token ?? '', active: !!instance.active });
        setEditingInstance(instance);
    }

    return (
        <>
            <Head title="Instancias" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Instancias de WhatsApp</h1>
                        <p className="text-sm text-muted-foreground mt-1">Gestiona tus conexiones con la API de Meta</p>
                    </div>
                    {/* Conectar con Facebook es el camino normal; "Nueva
                        Instancia" queda como respaldo para pegar los datos a
                        mano si la ventana de Meta falla o el entorno no la
                        tiene configurada. */}
                    <div className="flex items-center gap-2">
                        <EmbeddedSignupButton onConnected={() => router.reload({ only: ['instances', 'coexistenceSyncs'] })} />
                        <Button variant="outline" onClick={() => setShowCreate(true)} className="gap-2">
                            <Plus className="size-4" /> Nueva Instancia
                        </Button>
                    </div>
                </div>

                {/* Grid de instancias */}
                {instances.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
                        <Wifi className="size-12 text-muted-foreground/40 mb-4" />
                        <p className="text-lg font-medium text-foreground">No hay instancias configuradas</p>
                        <p className="text-sm text-muted-foreground mt-1">Crea tu primera conexión con WhatsApp Business</p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {instances.map(instance => (
                            <div key={instance.id} className="rounded-xl border bg-card p-5 shadow-xs flex flex-col gap-4">
                                <div className="flex items-start justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className={`flex size-10 items-center justify-center rounded-lg ${
                                            !instance.active ? 'bg-muted'
                                                : instance.health_status === 'unreachable' ? 'bg-red-100 dark:bg-red-900/30'
                                                : 'bg-green-100 dark:bg-green-900/30'
                                        }`}>
                                            {instance.active && instance.health_status !== 'unreachable'
                                                ? <Wifi className="size-5 text-green-600 dark:text-green-400" />
                                                : <WifiOff className={`size-5 ${instance.health_status === 'unreachable' && instance.active ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'}`} />
                                            }
                                        </div>
                                        <div>
                                            <p className="font-semibold text-foreground text-sm">{instance.name ?? 'Sin nombre'}</p>
                                            <p className="text-xs text-muted-foreground">{instance.display_phone_number ?? '—'}</p>
                                        </div>
                                    </div>
                                    {/* "Activa" es una casilla nuestra; la salud es lo que
                                        dice Meta. Mostrar solo la primera fue lo que dejó
                                        cinco empresas en verde durante meses sin recibir
                                        un mensaje. */}
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                        !instance.active ? 'bg-muted text-muted-foreground'
                                            : instance.health_status === 'unreachable' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                            : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                    }`}>
                                        {!instance.active ? 'Inactiva'
                                            : instance.health_status === 'unreachable' ? 'Sin conexión'
                                            : 'Activa'}
                                    </span>
                                </div>
                                {/* Importación de contactos e historial. Sólo
                                    aparece en números que vinieron de la app del
                                    celular; en un registro normal no hay nada que
                                    importar y el componente no pinta nada. */}
                                <CoexistenceSyncCard
                                    instanceId={instance.id}
                                    initial={coexistenceSyncs.find(s => s.instance_id === instance.id) ?? null}
                                />
                                {instance.active && instance.health_status === 'unreachable' && (
                                    <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-700 dark:text-red-400">
                                        <p className="font-medium">Meta no responde por esta cuenta.</p>
                                        <p className="opacity-90 mt-0.5">
                                            {instance.health_error ?? 'El token o el número ya no existen.'}
                                        </p>
                                        <p className="opacity-90 mt-1">
                                            No entran ni salen mensajes. Reconéctala con el botón de arriba.
                                        </p>
                                    </div>
                                )}

                                <div className="rounded-lg bg-muted/50 px-3 py-2 text-xs font-mono space-y-1">
                                    <div><span className="text-muted-foreground">Phone ID:</span> <span className="text-foreground">{instance.phone_number_id}</span></div>
                                    <div><span className="text-muted-foreground">WABA ID:</span> <span className="text-foreground">{instance.waba_id}</span></div>
                                </div>
                                <div className="flex gap-2 pt-1">
                                    <Button variant="outline" size="sm" className="flex-1 gap-1.5" onClick={() => openEdit(instance)}>
                                        <Pencil className="size-3.5" /> Editar
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="gap-1.5"
                                        disabled={generando === instance.id}
                                        title={instance.api_token_created_at
                                            ? 'Generar un token nuevo (el actual dejará de servir)'
                                            : 'Generar el token de la API'}
                                        onClick={() => generarToken(instance)}
                                    >
                                        <KeyRound className="size-3.5" />
                                        {instance.api_token_created_at ? 'Rotar token' : 'Token API'}
                                    </Button>
                                    <Button variant="outline" size="sm" className="gap-1.5 text-destructive hover:bg-destructive/10" onClick={() => handleDelete(instance)}>
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            </div>
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
                        <div className="flex gap-2 pt-2">
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
                        <Field label="Access Token" value={editForm.access_token} onChange={v => setEditForm(f => ({ ...f, access_token: v }))} placeholder="EAAI..." />
                        <label className="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" checked={editForm.active} onChange={e => setEditForm(f => ({ ...f, active: e.target.checked }))} className="rounded border-input size-4 accent-green-600" />
                            <span className="text-sm text-foreground">Instancia Activa</span>
                        </label>
                        <div className="flex gap-2 pt-2">
                            <Button type="submit" className="flex-1">Guardar Cambios</Button>
                            <Button type="button" variant="outline" onClick={() => setEditingInstance(null)}>Cancelar</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

function Modal({ title, description, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded-xl border bg-card shadow-2xl p-6" onClick={e => e.stopPropagation()}>
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground mt-1">{description}</p>}
                </div>
                {children}
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
