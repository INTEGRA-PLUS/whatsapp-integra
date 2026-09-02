import { useMemo } from 'react';
import { CheckCheck, ExternalLink, Phone, Reply } from 'lucide-react';

export function TabButton({ active, onClick, icon: Icon, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`relative inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition-colors ${
                active ? 'text-primary' : 'text-muted-foreground hover:text-foreground'
            }`}
        >
            {Icon && <Icon className="size-4" />}
            {children}
            {active && <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-t" />}
        </button>
    );
}

const BUTTON_ICONS = {
    URL: ExternalLink,
    PHONE_NUMBER: Phone,
    QUICK_REPLY: Reply,
};

// Replace {{1}}, {{2}} (positional) or {{nombre}} (named) in `text` using a
// {token: value} map. Falls back to the literal "{{token}}" if no example is provided.
export function substituteVars(text, examples = {}) {
    if (!text) return '';
    return text.replace(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g, (_, k) => {
        const v = examples[k] ?? examples[parseInt(k, 10)];
        return v && String(v).length > 0 ? v : `{{${k}}}`;
    });
}

// Replace {{1}}, {{2}} using a positional array (index 0 = {{1}}).
function substituteByPosition(text, examples = []) {
    if (!text) return '';
    return text.replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n) => {
        const v = examples[parseInt(n, 10) - 1];
        return v && String(v).length > 0 ? v : `{{${n}}}`;
    });
}

function parseInlineFormatting(line) {
    const nodes = [];
    const regex = /(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~|```[^`\n]+```)/g;
    let lastIdx = 0;
    let m;
    let key = 0;
    while ((m = regex.exec(line)) !== null) {
        if (m.index > lastIdx) nodes.push(line.slice(lastIdx, m.index));
        const token = m[0];
        if (token.startsWith('```') && token.endsWith('```')) {
            nodes.push(<code key={key++} className="bg-black/10 dark:bg-white/10 rounded px-1 font-mono text-[0.85em]">{token.slice(3, -3)}</code>);
        } else if (token.startsWith('*') && token.endsWith('*')) {
            nodes.push(<strong key={key++}>{token.slice(1, -1)}</strong>);
        } else if (token.startsWith('_') && token.endsWith('_')) {
            nodes.push(<em key={key++}>{token.slice(1, -1)}</em>);
        } else if (token.startsWith('~') && token.endsWith('~')) {
            nodes.push(<s key={key++}>{token.slice(1, -1)}</s>);
        }
        lastIdx = m.index + token.length;
    }
    if (lastIdx < line.length) nodes.push(line.slice(lastIdx));
    return nodes.length ? nodes : line;
}

export function renderWhatsAppText(text) {
    if (!text) return null;
    const lines = text.split('\n');
    return lines.map((line, i) => (
        <span key={i}>
            {parseInlineFormatting(line)}
            {i < lines.length - 1 && <br />}
        </span>
    ));
}

// Build a preview model from the create-form state.
export function formStateToModel(comps, headerExamples = {}, bodyExamples = {}) {
    const headerType = comps.header?.type ?? 'NONE';
    let header = null;
    if (headerType === 'TEXT' && comps.header.text) {
        header = { text: substituteVars(comps.header.text, headerExamples) };
    } else if (['IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'].includes(headerType)) {
        header = { text: '', mediaFormat: headerType };
    }
    return {
        header,
        body: { text: substituteVars(comps.body?.text ?? '', bodyExamples) },
        footer: comps.footer?.enabled && comps.footer.text
            ? { text: comps.footer.text }
            : null,
        buttons: comps.buttons ?? [],
    };
}

// Build a preview model from a Meta API template response.
export function templateToModel(template) {
    const out = { header: null, body: { text: '' }, footer: null, buttons: [] };
    for (const c of template?.components ?? []) {
        if (c.type === 'HEADER' && (c.format === 'TEXT' || !c.format)) {
            const examples = c.example?.header_text ?? [];
            out.header = { text: substituteByPosition(c.text ?? '', examples) };
        } else if (c.type === 'HEADER') {
            // Non-text headers (IMAGE/VIDEO/DOCUMENT) — show placeholder hint
            out.header = { text: '', mediaFormat: c.format };
        } else if (c.type === 'BODY') {
            const examples = c.example?.body_text?.[0] ?? [];
            out.body = { text: substituteByPosition(c.text ?? '', examples) };
        } else if (c.type === 'FOOTER') {
            out.footer = { text: c.text ?? '' };
        } else if (c.type === 'BUTTONS') {
            out.buttons = (c.buttons ?? []).map(b => ({
                type: b.type ?? 'QUICK_REPLY',
                text: b.text ?? '',
                url: b.url ?? '',
                phone_number: b.phone_number ?? '',
            }));
        }
    }
    return out;
}

export function WhatsAppPreview({ model, verifiedName, empty }) {
    const time = useMemo(() => {
        const d = new Date();
        return d.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
    }, []);

    const hasHeader = model?.header && (model.header.text || model.header.mediaFormat);
    const hasFooter = model?.footer && model.footer.text;
    const hasBody = model?.body?.text;
    const buttons = model?.buttons ?? [];

    if (!hasBody && !hasHeader && !hasFooter && buttons.length === 0) {
        return (
            <div className="rounded-xl border border-dashed py-12 text-center text-sm text-muted-foreground">
                {empty ?? 'No hay contenido para previsualizar.'}
            </div>
        );
    }

    return (
        <div className="rounded-2xl overflow-hidden border bg-card shadow-inner">
            {/* WhatsApp top bar */}
            <div className="bg-[#075E54] dark:bg-[#202c33] text-white px-4 py-3 flex items-center gap-3">
                <div className="size-9 rounded-full bg-white/20 flex items-center justify-center text-xs font-semibold">
                    {(verifiedName ?? 'WA').slice(0, 2).toUpperCase()}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold truncate">
                        {verifiedName ?? 'Tu negocio'}
                    </div>
                    <div className="text-[10px] opacity-80">En línea</div>
                </div>
            </div>

            {/* Chat background */}
            <div className="px-4 py-6 bg-[#e5ddd5] dark:bg-[#0b141a] min-h-[280px]">
                <div className="max-w-[85%] space-y-1">
                    <div className="relative rounded-lg rounded-tl-none px-3 py-2 bg-white dark:bg-[#202c33] text-[#111b21] dark:text-[#e9edef] shadow-sm">
                        <span
                            className="absolute -left-[7px] top-0 size-3 bg-white dark:bg-[#202c33]"
                            style={{ clipPath: 'polygon(100% 0, 0 0, 100% 100%)' }}
                        />

                        {/* Con el archivo ya elegido se enseña el archivo, no la
                            palabra "[IMAGE]": ver la foto real antes de mandarla a
                            mil personas es justo lo que evita el susto. */}
                        {hasHeader && model.header.mediaFormat && model.header.mediaUrl && (
                            model.header.mediaFormat === 'IMAGE' ? (
                                <img
                                    src={model.header.mediaUrl}
                                    alt=""
                                    className="mb-2 rounded-md w-full max-h-56 object-cover"
                                />
                            ) : model.header.mediaFormat === 'VIDEO' ? (
                                <video src={model.header.mediaUrl} className="mb-2 rounded-md w-full max-h-56" controls />
                            ) : (
                                <div className="mb-2 rounded-md bg-black/5 dark:bg-white/5 px-3 py-3 text-[12px] flex items-center gap-2">
                                    <span className="text-lg">📄</span>
                                    <span className="truncate">{model.header.filename || 'Documento adjunto'}</span>
                                </div>
                            )
                        )}

                        {hasHeader && model.header.mediaFormat && !model.header.mediaUrl && (
                            <div className="mb-2 rounded-md bg-black/5 dark:bg-white/5 py-6 text-center text-[11px] text-muted-foreground">
                                [{model.header.mediaFormat}]
                            </div>
                        )}

                        {hasHeader && model.header.text && (
                            <div className="font-semibold text-[15px] leading-snug mb-1">
                                {renderWhatsAppText(model.header.text)}
                            </div>
                        )}

                        <div className="text-[14.2px] leading-relaxed whitespace-pre-wrap break-words">
                            {renderWhatsAppText(model.body.text)}
                        </div>

                        {hasFooter && (
                            <div className="text-[12.5px] mt-1.5 text-[#667781] dark:text-[#8696a0]">
                                {model.footer.text}
                            </div>
                        )}

                        <div className="flex items-center justify-end gap-1 mt-1 text-[11px] text-[#667781] dark:text-[#8696a0]">
                            <span>{time}</span>
                            <CheckCheck className="size-3.5 text-[#53bdeb]" />
                        </div>
                    </div>

                    {buttons.length > 0 && (
                        <div className="rounded-lg overflow-hidden bg-white dark:bg-[#202c33] shadow-sm mt-1">
                            {buttons.map((btn, i) => {
                                const Icon = BUTTON_ICONS[btn.type] ?? Reply;
                                return (
                                    <div
                                        key={i}
                                        className={`flex items-center justify-center gap-2 py-2 text-[14px] font-medium text-[#00a5f4] dark:text-[#53bdeb] ${
                                            i > 0 ? 'border-t border-[#e9edef] dark:border-[#2a3942]' : ''
                                        }`}
                                    >
                                        <Icon className="size-4" />
                                        {btn.text || <span className="italic opacity-60">Botón {i + 1}</span>}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            <div className="px-4 py-2 bg-muted/30 border-t text-[10px] text-muted-foreground text-center">
                Vista previa aproximada · La apariencia final depende del dispositivo del cliente.
            </div>
        </div>
    );
}
