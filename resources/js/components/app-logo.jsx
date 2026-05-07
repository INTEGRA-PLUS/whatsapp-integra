export default function AppLogo() {
    return (
        <div className="flex items-center gap-3">
            <div className="flex aspect-square size-10 items-center justify-center rounded-lg bg-[#0d1b2e] shadow-lg overflow-hidden p-1">
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" className="size-full">
                    <circle cx="100" cy="100" r="82" fill="none" stroke="#1a6b2a" strokeWidth="1" strokeDasharray="10 4" opacity="0.6"/>
                    <circle cx="100" cy="100" r="68" fill="none" stroke="#22c55e" strokeWidth="1.2" strokeDasharray="8 6" opacity="0.5"/>
                    <circle cx="100" cy="100" r="54" fill="none" stroke="#4ade80" strokeWidth="1.5" opacity="0.4"/>
                    <circle cx="100" cy="18" r="3.5" fill="#22c55e"/>
                    <circle cx="161" cy="50" r="3" fill="#22c55e"/>
                    <circle cx="39" cy="50" r="3" fill="#22c55e"/>
                    <filter id="glowReact">
                        <feGaussianBlur stdDeviation="3" result="coloredBlur"/>
                        <feMerge><feMergeNode in="coloredBlur"/><feMergeNode in="SourceGraphic"/></feMerge>
                    </filter>
                    <path d="M 73 68 A 35 35 0 1 0 127 68" fill="none" stroke="#4ade80" strokeWidth="6" strokeLinecap="round" filter="url(#glowReact)"/>
                    <line x1="100" y1="48" x2="100" y2="76" stroke="#4ade80" strokeWidth="6" strokeLinecap="round" filter="url(#glowReact)"/>
                </svg>
            </div>
            <div className="grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-bold text-gray-900">Integra CRM</span>
                <span className="truncate text-[10px] uppercase tracking-tighter text-gray-400 font-semibold">Integra Colombia</span>
            </div>
        </div>
    );
}
