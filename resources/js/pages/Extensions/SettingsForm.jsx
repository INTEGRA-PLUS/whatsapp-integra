import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-react';

// El formulario se genera a partir del esquema que declara cada extensión en
// PHP. Es lo que hace que publicar una extensión nueva no toque el frontend:
// mientras sus ajustes usen estos tipos, la pantalla ya sabe pintarlos.

const inputClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60';

export default function SettingsForm({ schema, values, disabled, onSubmit, submitLabel }) {
    const [form, setForm] = useState(values);

    const set = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

    function handleSubmit(e) {
        e.preventDefault();
        onSubmit(form);
    }

    return (
        <form onSubmit={handleSubmit} className="mt-4 space-y-5">
            {schema.map((field) => (
                <Field key={field.key} field={field} value={form[field.key]} disabled={disabled} onChange={(v) => set(field.key, v)} />
            ))}

            <div className="pt-1">
                <Button type="submit" className="gap-2" disabled={disabled}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}

function Field({ field, value, disabled, onChange }) {
    // El booleano lleva la etiqueta al lado de la casilla, no encima: un título
    // suelto sobre un interruptor no dice si marcarlo activa o desactiva.
    if (field.type === 'boolean') {
        return (
            <label className="flex items-start gap-3 cursor-pointer">
                <input
                    type="checkbox"
                    checked={!!value}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.checked)}
                    className="mt-0.5 size-4 rounded border-input accent-primary"
                />
                <span>
                    <span className="text-sm font-medium text-foreground">{field.label}</span>
                    {field.help && <span className="block text-xs text-muted-foreground mt-0.5">{field.help}</span>}
                </span>
            </label>
        );
    }

    return (
        <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">{field.label}</label>
            {field.help && <p className="text-xs text-muted-foreground">{field.help}</p>}
            <Control field={field} value={value} disabled={disabled} onChange={onChange} />
        </div>
    );
}

function Control({ field, value, disabled, onChange }) {
    switch (field.type) {
        case 'number':
            return (
                <input
                    type="number"
                    value={value ?? ''}
                    min={field.min}
                    max={field.max}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))}
                    className={`${inputClass} max-w-[10rem]`}
                />
            );

        case 'textarea':
            return (
                <textarea
                    value={value ?? ''}
                    rows={4}
                    maxLength={field.maxlength}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 resize-y disabled:opacity-60"
                />
            );

        case 'select':
            return (
                <select
                    value={value ?? ''}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
                    className={`${inputClass} max-w-md`}
                >
                    {field.nullable && <option value="">— Ninguna —</option>}
                    {(field.options ?? []).map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            );

        case 'multiselect':
            return (
                <div className="flex flex-wrap gap-1.5">
                    {(field.options ?? []).map((o) => {
                        const seleccionado = (value ?? []).map(String).includes(String(o.value));
                        return (
                            <button
                                key={o.value}
                                type="button"
                                disabled={disabled}
                                onClick={() =>
                                    onChange(
                                        seleccionado
                                            ? (value ?? []).filter((v) => String(v) !== String(o.value))
                                            : [...(value ?? []), o.value]
                                    )
                                }
                                className={
                                    seleccionado
                                        ? 'rounded-full bg-primary px-3 py-1 text-xs font-medium text-primary-foreground'
                                        : 'rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground hover:bg-muted/70'
                                }
                            >
                                {o.label}
                            </button>
                        );
                    })}
                </div>
            );

        case 'rules':
            return <RulesEditor field={field} value={value ?? []} disabled={disabled} onChange={onChange} />;

        default:
            return (
                <input
                    type="text"
                    value={value ?? ''}
                    maxLength={field.maxlength}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value)}
                    className={`${inputClass} max-w-md`}
                />
            );
    }
}

/**
 * El editor de reglas del enrutado por palabra clave.
 *
 * Es el único tipo de campo con componente propio: una regla son cinco decisiones
 * relacionadas entre sí (nombre, palabras, etiqueta, asignación y aviso) y
 * partirlas en cinco campos sueltos del esquema haría imposible saber cuáles van
 * juntas.
 *
 * Las palabras clave se editan como texto separado por comas y no como etiquetas
 * de una en una: quien configura esto llega con la lista ya escrita y la pega.
 */
function RulesEditor({ field, value, disabled, onChange }) {
    const tags = field.optionsBySource?.tags ?? [];
    const agents = field.optionsBySource?.agents ?? [];

    const update = (i, patch) => onChange(value.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    const remove = (i) => onChange(value.filter((_, idx) => idx !== i));
    const add = () =>
        onChange([...value, { label: '', keywords: '', tag_id: null, assign: 'none', notify: false }]);

    return (
        <div className="space-y-3">
            {value.length === 0 && (
                <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                    Todavía no hay reglas. Sin reglas, la extensión no hace nada aunque esté encendida.
                </p>
            )}

            {value.map((rule, i) => (
                <div key={i} className="rounded-xl border bg-muted/20 p-4 space-y-3">
                    <div className="flex items-center gap-2">
                        <div className="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted text-[11px] font-bold text-muted-foreground">
                            {i + 1}
                        </div>
                        <input
                            type="text"
                            value={rule.label ?? ''}
                            placeholder="Nombre de la regla (ej. Intención de compra)"
                            maxLength={60}
                            disabled={disabled}
                            onChange={(e) => update(i, { label: e.target.value })}
                            className={inputClass}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="text-destructive hover:bg-destructive/10 shrink-0"
                            disabled={disabled}
                            onClick={() => remove(i)}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>

                    <div className="space-y-1.5">
                        <label className="text-xs font-medium text-muted-foreground">
                            Palabras clave (separadas por comas)
                        </label>
                        <input
                            type="text"
                            value={Array.isArray(rule.keywords) ? rule.keywords.join(', ') : (rule.keywords ?? '')}
                            placeholder="quiero comprar, cotizar, precio"
                            disabled={disabled}
                            onChange={(e) => update(i, { keywords: e.target.value })}
                            className={inputClass}
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Aplicar etiqueta</label>
                            <select
                                value={rule.tag_id ?? ''}
                                disabled={disabled}
                                onChange={(e) => update(i, { tag_id: e.target.value === '' ? null : e.target.value })}
                                className={inputClass}
                            >
                                <option value="">— Ninguna —</option>
                                {tags.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">Asignar a</label>
                            <select
                                value={rule.assign ?? 'none'}
                                disabled={disabled}
                                onChange={(e) => update(i, { assign: e.target.value })}
                                className={inputClass}
                            >
                                <option value="none">— No asignar —</option>
                                <option value="least_busy">El agente con menos chats abiertos</option>
                                {agents.map((a) => (
                                    <option key={a.value} value={a.value}>
                                        {a.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={!!rule.notify}
                            disabled={disabled}
                            onChange={(e) => update(i, { notify: e.target.checked })}
                            className="size-4 rounded border-input accent-primary"
                        />
                        <span className="text-xs text-muted-foreground">
                            Avisar por la campana cuando esta regla coincida
                        </span>
                    </label>
                </div>
            ))}

            <Button type="button" variant="outline" size="sm" className="gap-2" disabled={disabled} onClick={add}>
                <Plus className="size-4" /> Añadir regla
            </Button>
        </div>
    );
}
