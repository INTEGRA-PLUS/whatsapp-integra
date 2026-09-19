import { useEffect, useState } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { FileText, Loader2, X, AlertCircle } from 'lucide-react';

/**
 * Cuál de sus facturas se le manda.
 *
 * ## Por qué pregunta en vez de mandar la última y ya
 *
 * Porque «mándame la factura» casi siempre es la última, pero no siempre: el
 * cliente que reclama un cobro pide la del mes pasado. Preguntar cuesta un clic
 * —la última viene elegida— y mandar la equivocada cuesta una conversación.
 *
 * ## De dónde salen
 *
 * De las que Integra ya le envió por este chat: son las únicas de las que
 * tenemos el PDF. Una factura emitida y nunca mandada no aparece aquí, y por eso
 * la lista vacía no dice «error» sino qué pasó.
 *
 * Al enviar no se manda un documento suelto: se manda la plantilla `facturacion`
 * con el PDF en el encabezado y el saldo de HOY en el cuerpo. El importe se
 * vuelve a leer del ERP al enviar, porque una factura se abona y decirle al
 * cliente el total viejo es pedirle de más.
 */
export default function SelectorDeFacturas({ conversationId, onCerrar, onEnviar }) {
    const [facturas, setFacturas] = useState(null);
    const [elegida, setElegida] = useState(null);
    const [error, setError] = useState(null);
    const [enviando, setEnviando] = useState(false);

    useEffect(() => {
        let vivo = true;

        axios.get(`/api/chat/conversations/${conversationId}/facturas`)
            .then(({ data }) => {
                if (!vivo) return;
                setFacturas(data.facturas ?? []);
                // La última ya viene elegida: es la que se quiere casi siempre.
                setElegida(data.facturas?.[0]?.factura_id ?? null);
            })
            .catch(err => vivo && setError(err?.response?.data?.message ?? 'No se pudieron consultar las facturas.'));

        return () => { vivo = false; };
    }, [conversationId]);

    async function enviar() {
        if (!elegida) return;

        setEnviando(true);
        setError(null);

        try {
            const { data } = await axios.post(
                `/api/chat/conversations/${conversationId}/facturas/${elegida}/preparar`
            );
            await onEnviar(data);
            onCerrar();
        } catch (err) {
            setError(err?.response?.data?.message ?? 'No se pudo preparar el envío.');
        } finally {
            setEnviando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onCerrar}>
            <div className="w-full max-w-md rounded-xl border bg-card shadow-2xl" onClick={e => e.stopPropagation()}>
                <div className="flex items-start justify-between gap-3 border-b px-5 py-4">
                    <div>
                        <h2 className="flex items-center gap-2 font-semibold text-foreground">
                            <FileText className="size-4 text-success" /> Enviar una factura
                        </h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Va con el PDF adjunto y el saldo que deba hoy.
                        </p>
                    </div>
                    <button onClick={onCerrar} className="rounded-md p-1 text-muted-foreground hover:bg-muted">
                        <X className="size-4" />
                    </button>
                </div>

                <div className="max-h-80 overflow-y-auto px-5 py-4">
                    {facturas === null ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" /> Buscando sus facturas…
                        </p>
                    ) : facturas.length === 0 ? (
                        <div className="rounded-lg border border-warning/30 bg-warning/5 p-3 text-sm text-muted-foreground">
                            <p className="flex items-center gap-1.5 font-medium text-warning">
                                <AlertCircle className="size-4" /> No hay facturas que reenviar
                            </p>
                            <p className="mt-1.5">
                                Aquí sólo salen las que Integra ya le envió por este chat, porque son las
                                únicas de las que tenemos el documento. Si nunca se le mandó ninguna,
                                tiene que salir primero desde Integra.
                            </p>
                        </div>
                    ) : (
                        <ul className="space-y-1.5">
                            {facturas.map((f, i) => (
                                <li key={f.factura_id}>
                                    <button
                                        type="button"
                                        onClick={() => setElegida(f.factura_id)}
                                        className={`w-full rounded-lg border p-3 text-left transition ${
                                            elegida === f.factura_id
                                                ? 'border-primary bg-primary/5 ring-1 ring-primary/30'
                                                : 'border-input hover:bg-muted/40'
                                        }`}
                                    >
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="truncate text-sm font-medium text-foreground">{f.archivo}</span>
                                            {i === 0 && (
                                                <span className="shrink-0 rounded bg-success/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-success">
                                                    La última
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            Enviada el {new Date(f.enviada_el).toLocaleDateString('es-CO', {
                                                day: 'numeric', month: 'long', year: 'numeric',
                                            })}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {error && <p className="mt-3 text-xs font-medium text-destructive">{error}</p>}
                </div>

                <div className="flex gap-2 border-t px-5 py-4">
                    <Button className="flex-1 gap-2" disabled={!elegida || enviando} onClick={enviar}>
                        {enviando ? <Loader2 className="size-4 animate-spin" /> : <FileText className="size-4" />}
                        {enviando ? 'Enviando…' : 'Enviar la factura'}
                    </Button>
                    <Button variant="outline" onClick={onCerrar} disabled={enviando}>Cancelar</Button>
                </div>
            </div>
        </div>
    );
}
