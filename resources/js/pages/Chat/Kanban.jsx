import { useState, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { 
    Search, 
    MoreVertical, 
    MessageSquare, 
    Filter,
    Plus,
    Calendar,
    User,
    Clock,
    DollarSign,
    TrendingUp,
    CheckCircle2,
    AlertCircle
} from 'lucide-react';

const COLUMNS = [
    { id: 'nuevo', title: 'Nuevo Prospecto', color: 'bg-blue-500' },
    { id: 'atencion', title: 'En Atención', color: 'bg-amber-500' },
    { id: 'seguimiento', title: 'Seguimiento', color: 'bg-purple-500' },
    { id: 'cerrado', title: 'Venta / Cerrado', color: 'bg-emerald-500' }
];

export default function Kanban({ conversations }) {
    const [searchQuery, setSearchQuery] = useState('');

    // For demo purposes, we distribute conversations into columns if status is just 'open' or 'closed'
    const categorizedConversations = useMemo(() => {
        const columns = {
            nuevo: [],
            atencion: [],
            seguimiento: [],
            cerrado: []
        };

        conversations.forEach((conv, index) => {
            // Demo distribution logic:
            // If status is closed, it goes to cerrado
            if (conv.status === 'closed') {
                columns.cerrado.push(conv);
            } else {
                // Distributed by index for visual variety in the demo
                const statusMap = ['nuevo', 'atencion', 'seguimiento'];
                const colId = statusMap[index % 3];
                columns[colId].push(conv);
            }
        });

        return columns;
    }, [conversations]);

    const filteredColumns = useMemo(() => {
        if (!searchQuery) return categorizedConversations;
        const q = searchQuery.toLowerCase();
        
        const filtered = {};
        Object.keys(categorizedConversations).forEach(key => {
            filtered[key] = categorizedConversations[key].filter(
                c => (c.name || '').toLowerCase().includes(q) || (c.phone_number || '').includes(q)
            );
        });
        return filtered;
    }, [categorizedConversations, searchQuery]);

    const stats = [
        { label: 'Total Leads', value: conversations.length, icon: User, color: 'text-blue-600' },
        { label: 'Conversiones', value: categorizedConversations.cerrado.length, icon: TrendingUp, color: 'text-emerald-600' },
        { label: 'Valor Estimado', value: `$ ${(conversations.length * 150000).toLocaleString()}`, icon: DollarSign, color: 'text-amber-600' },
        { label: 'Tasa Respuesta', value: '94%', icon: CheckCircle2, color: 'text-purple-600' }
    ];

    return (
        <>
            <Head title="CRM Kanban - Muestra Comercial" />
            
            <div className="flex flex-col h-[calc(100vh-64px)] bg-[#f8fafc] dark:bg-[#0f172a] overflow-hidden">
                {/* Board Header / Stats */}
                <div className="p-6 pb-2">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                        <div>
                            <h1 className="text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                                CRM Comercial <span className="text-[10px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full uppercase tracking-widest font-bold">Muestra Comercial</span>
                            </h1>
                            <p className="text-slate-500 dark:text-slate-400 text-sm">Gestiona tus prospectos de WhatsApp como un embudo de ventas profesional.</p>
                        </div>
                        
                        <div className="flex items-center gap-3">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-slate-400" />
                                <input 
                                    type="text" 
                                    placeholder="Buscar contacto..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="pl-10 pr-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20 transition-all w-64 shadow-sm"
                                />
                            </div>
                            <button className="p-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
                                <Filter className="size-4 text-slate-600 dark:text-slate-300" />
                            </button>
                            <button className="flex items-center gap-2 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold shadow-lg shadow-teal-500/20 transition-all">
                                <Plus className="size-4" /> Nuevo Lead
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        {stats.map((stat, i) => (
                            <div key={i} className="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-100 dark:border-slate-700/50 shadow-sm flex items-center gap-4">
                                <div className={`p-3 rounded-xl bg-slate-50 dark:bg-slate-900 ${stat.color}`}>
                                    <stat.icon className="size-5" />
                                </div>
                                <div>
                                    <p className="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-tight">{stat.label}</p>
                                    <p className="text-lg font-black text-slate-900 dark:text-white">{stat.value}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* The Board */}
                <div className="flex-1 overflow-x-auto p-6 pt-0 flex gap-6 custom-scrollbar">
                    {COLUMNS.map((col) => (
                        <div key={col.id} className="flex-shrink-0 w-80 flex flex-col h-full">
                            <div className="flex items-center justify-between mb-4 px-2">
                                <div className="flex items-center gap-2">
                                    <div className={`size-2 rounded-full ${col.color}`} />
                                    <h2 className="font-black text-sm text-slate-700 dark:text-slate-300 uppercase tracking-wider">{col.title}</h2>
                                    <span className="bg-slate-200 dark:bg-slate-800 px-2 py-0.5 rounded-full text-[10px] font-black text-slate-500">
                                        {filteredColumns[col.id].length}
                                    </span>
                                </div>
                                <button className="p-1 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-md transition-colors">
                                    <MoreVertical className="size-4 text-slate-400" />
                                </button>
                            </div>

                            <div className="flex-1 overflow-y-auto space-y-4 custom-scrollbar pr-2">
                                {filteredColumns[col.id].map((conv) => (
                                    <div 
                                        key={conv.id}
                                        className="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm hover:shadow-md hover:border-teal-500/30 transition-all group cursor-pointer"
                                    >
                                        <div className="flex justify-between items-start mb-3">
                                            <div className="flex items-center gap-3">
                                                <div className="size-10 rounded-xl bg-teal-50 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400 flex items-center justify-center font-black text-sm uppercase">
                                                    {conv.initials}
                                                </div>
                                                <div className="min-w-0">
                                                    <h3 className="text-[13px] font-black text-slate-900 dark:text-white truncate">
                                                        {conv.name || conv.phone_number}
                                                    </h3>
                                                    <div className="flex items-center gap-1 text-[10px] text-slate-400">
                                                        <Clock className="size-3" />
                                                        {new Date(conv.last_message_at).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })}
                                                    </div>
                                                </div>
                                            </div>
                                            <button className="opacity-0 group-hover:opacity-100 transition-opacity">
                                                <MoreVertical className="size-4 text-slate-400" />
                                            </button>
                                        </div>

                                        <p className="text-xs text-slate-500 dark:text-slate-400 line-clamp-2 mb-4 leading-relaxed font-medium">
                                            {conv.last_message || 'Inició una conversación...'}
                                        </p>

                                        <div className="pt-3 border-t border-slate-50 dark:border-slate-700/50 flex items-center justify-between">
                                            <div className="flex -space-x-2">
                                                <div className="size-6 rounded-full border-2 border-white dark:border-slate-800 bg-slate-200 dark:bg-slate-700 flex items-center justify-center text-[8px] font-black">
                                                    AG
                                                </div>
                                                {conv.assigned_agent && (
                                                    <div className="size-6 rounded-full border-2 border-white dark:border-slate-800 bg-teal-600 flex items-center justify-center text-[8px] font-black text-white">
                                                        {conv.assigned_agent.name.substring(0, 2).toUpperCase()}
                                                    </div>
                                                )}
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {conv.unread_count > 0 && (
                                                    <span className="bg-red-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full">
                                                        {conv.unread_count}
                                                    </span>
                                                )}
                                                <MessageSquare className="size-3.5 text-slate-300 dark:text-slate-600" />
                                            </div>
                                        </div>
                                    </div>
                                ))}
                                
                                {filteredColumns[col.id].length === 0 && (
                                    <div className="border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl py-12 flex flex-col items-center justify-center text-slate-400">
                                        <AlertCircle className="size-8 opacity-20 mb-2" />
                                        <p className="text-xs font-bold uppercase tracking-tight opacity-40">Sin actividades</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar {
                    width: 5px;
                    height: 5px;
                }
                .custom-scrollbar::-webkit-scrollbar-track {
                    background: transparent;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(148, 163, 184, 0.2);
                    border-radius: 10px;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                    background: rgba(148, 163, 184, 0.4);
                }
            `}} />
        </>
    );
}

Kanban.layout = page => <AppLayout breadcrumb={['CRM Commercial Board']}>{page}</AppLayout>;
