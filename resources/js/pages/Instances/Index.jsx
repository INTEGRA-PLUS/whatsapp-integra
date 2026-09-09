import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, Wifi, WifiOff, AlertTriangle, PowerOff, Power } from 'lucide-react';
import EmbeddedSignupButton from '@/components/EmbeddedSignupButton';
import CoexistenceSyncCard from '@/components/CoexistenceSyncCard';

export default function InstancesIndex({ instances, coexistenceSyncs = [] }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editingInstance, setEditingInstance] = useState(null);

    // El diálogo de borrado: la instancia en cuestión, el resumen que pide al
    // servidor y el nombre que hay que teclear para confirmar.
    const [deletingInstance, setDeletingInstance] = useState(null);
    const [deleteSummary, setDeleteSummary] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState('');
    const [deleteError, setDeleteError] = useState(null);

    const [createForm, setCreateForm] = useState({ name: '', phone_number_id: '', waba_id: '', display_phone_number: '', access_token: '' });
    const [editForm, setEditForm] = useState({ name: '', phone_number_id: '', waba_id: '', display_phone_number: '', access_token: '', active: false });

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
                                                : instance.health_status === 'unreachable' ? 'bg-destructive/10'
                                                : 'bg-success/15'
                                        }`}>
                                            {instance.active && instance.health_status !== 'unreachable'
                                                ? <Wifi className="size-5 text-success" />
                                                : <WifiOff className={`size-5 ${instance.health_status === 'unreachable' && instance.active ? 'text-destructive' : 'text-muted-foreground'}`} />
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
                                            : instance.health_status === 'unreachable' ? 'bg-destructive/10 text-destructive'
                                            : 'bg-success/15 text-success'
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
                                    <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
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
                                    {instance.active ? (
                                        <Button variant="outline" size="sm" className="gap-1.5" onClick={() => handleDesconectar(instance)} title="Deja de enviar y recibir, sin borrar nada">
                                            <PowerOff className="size-3.5" /> Desconectar
                                        </Button>
                                    ) : (
                                        <Button variant="outline" size="sm" className="gap-1.5" onClick={() => handleReconectar(instance)} title="Volver a activarla">
                                            <Power className="size-3.5" /> Reconectar
                                        </Button>
                                    )}
                                    <Button variant="outline" size="sm" className="gap-1.5 text-destructive hover:bg-destructive/10" onClick={() => openDelete(instance)} title="Eliminar definitivamente">
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

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
                            <input type="checkbox" checked={editForm.active} onChange={e => setEditForm(f => ({ ...f, active: e.target.checked }))} className="rounded border-input size-4 accent-primary" />
                            <span className="text-sm text-foreground">Instancia Activa</span>
                        </label>
                        <div className="flex gap-2 pt-2">
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

                        <div className="flex gap-2 pt-2">
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
