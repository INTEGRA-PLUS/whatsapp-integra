import { useEffect, useRef } from 'react';
import { Zap } from 'lucide-react';

export default function QuickReplyPicker({ matches, activeIndex, onSelect, query }) {
    const listRef = useRef(null);

    useEffect(() => {
        const el = listRef.current?.children?.[activeIndex];
        if (el && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'nearest' });
        }
    }, [activeIndex]);

    if (!matches.length) {
        return (
            <div className="absolute bottom-full left-0 right-0 mb-2 mx-2 rounded-lg border bg-popover text-popover-foreground shadow-lg p-3 text-xs text-muted-foreground">
                Sin respuestas rápidas para «/{query}».
            </div>
        );
    }

    return (
        <div className="absolute bottom-full left-0 right-0 mb-2 mx-2 rounded-lg border bg-popover text-popover-foreground shadow-lg overflow-hidden max-h-64 flex flex-col">
            <div className="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-muted-foreground bg-muted/40 flex items-center gap-1.5">
                <Zap className="size-3" /> Respuestas rápidas
            </div>
            <ul ref={listRef} className="overflow-y-auto">
                {matches.map((reply, idx) => (
                    <li
                        key={reply.id}
                        onMouseDown={e => { e.preventDefault(); onSelect(reply); }}
                        className={`flex items-start gap-3 px-3 py-2 cursor-pointer text-sm ${idx === activeIndex ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50'}`}
                    >
                        <span className="inline-flex shrink-0 items-center rounded-md bg-primary/10 text-primary px-2 py-0.5 text-xs font-mono font-bold">
                            /{reply.shortcut}
                        </span>
                        <span className="flex-1 text-muted-foreground line-clamp-2">{reply.message}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
