import { useState, useEffect, useRef, useMemo, useCallback, Fragment } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import axios from 'axios';
import { 
    Search, 
    Send, 
    Image as ImageIcon, 
    MoreVertical, 
    Phone, 
    Info, 
    Check, 
    CheckCheck,
    Paperclip,
    Mic,
    Smile,
    ArrowLeft,
    Clock,
    User,
    MessageSquare,
    ChevronDown
} from 'lucide-react';

export default function ChatIndex({ instances }) {
    const [selectedInstanceId, setSelectedInstanceId] = useState('');
    const [conversations, setConversations] = useState([]);
    const [messages, setMessages] = useState([]);
    const [selectedConversation, setSelectedConversation] = useState(null);
    const [newMessage, setNewMessage] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [sending, setSending] = useState(false);
    const [lastUpdateTimestamp, setLastUpdateTimestamp] = useState(null);
    const [lastUpdate, setLastUpdate] = useState('Nunca');
    const [isPolling, setIsPolling] = useState(false);
    const [selectedImage, setSelectedImage] = useState(null);

    const messagesContainerRef = useRef(null);
    const pollingIntervalRef = useRef(null);

    const filteredConversations = useMemo(() => {
        if (!searchQuery) return conversations;
        const q = searchQuery.toLowerCase();
        return conversations.filter(
            c => (c.name || '').toLowerCase().includes(q) || (c.phone_number || '').includes(q),
        );
    }, [conversations, searchQuery]);

    const hasPaymentIssue = useMemo(() => {
        if (!messages.length) return false;
        const last = messages[messages.length - 1];
        return (
            last.direction === 'outbound' &&
            last.status === 'failed' &&
            last.error_message?.toLowerCase().includes('business eligibility payment')
        );
    }, [messages]);

    const scrollToBottom = useCallback(() => {
        const el = messagesContainerRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, []);

    useEffect(() => {
        if (instances.length > 0) {
            const first = instances[0];
            setSelectedInstanceId(String(first.id));
        }
    }, [instances]);

    useEffect(() => {
        if (!selectedInstanceId) return;
        loadConversations();
        startPolling();
        return () => stopPolling();
    }, [selectedInstanceId]);

    useEffect(() => {
        scrollToBottom();
    }, [messages, scrollToBottom]);

    async function loadConversations() {
        try {
            const res = await axios.get('/api/chat/conversations', {
                params: { instance_id: selectedInstanceId },
            });
            setConversations(res.data.data);
        } catch (err) {
            console.error('Error cargando conversaciones:', err);
        }
    }

    function startPolling() {
        stopPolling();
        pollingIntervalRef.current = setInterval(checkForUpdates, 10000);
    }

    function stopPolling() {
        if (pollingIntervalRef.current) {
            clearInterval(pollingIntervalRef.current);
            pollingIntervalRef.current = null;
        }
    }

    async function checkForUpdates() {
        if (!lastUpdateTimestamp) {
            setLastUpdateTimestamp(new Date().toISOString());
            return;
        }
        try {
            setIsPolling(true);
            const params = { instance_id: selectedInstanceId, since: lastUpdateTimestamp };
            if (selectedConversation) params.conversation_id = selectedConversation.id;

            const res = await axios.get('/api/chat/updates', { params });
            setLastUpdateTimestamp(res.data.timestamp);
            setLastUpdate(new Date().toLocaleTimeString('es-CO'));

            if (res.data.conversations?.length > 0) {
                mergeConversations(res.data.conversations);
            }
            if (res.data.new_messages?.length > 0) {
                setMessages(prev => {
                    const ids = new Set(prev.map(m => m.id));
                    const incoming = res.data.new_messages.filter(m => !ids.has(m.id));
                    if (incoming.length === 0) return prev;
                    return [...prev, ...incoming];
                });
            }
            if (res.data.updated_statuses?.length > 0) {
                setMessages(prev =>
                    prev.map(msg => {
                        const update = res.data.updated_statuses.find(u => u.id === msg.id);
                        return update ? { ...msg, ...update } : msg;
                    }),
                );
            }
        } catch (err) {
            console.error('Error en polling:', err);
        } finally {
            setIsPolling(false);
        }
    }

    function mergeConversations(updated) {
        setConversations(prev => {
            const map = new Map(prev.map(c => [c.id, c]));
            updated.forEach(u => map.set(u.id, u));
            return Array.from(map.values()).sort(
                (a, b) => new Date(b.last_message_at) - new Date(a.last_message_at),
            );
        });
    }

    async function selectConversation(conv) {
        setSelectedConversation(conv);
        try {
            const res = await axios.get(`/api/chat/conversations/${conv.id}/messages`);
            setMessages(res.data.messages);
            setLastUpdateTimestamp(res.data.timestamp);
            setConversations(prev =>
                prev.map(c => (c.id === conv.id ? { ...c, unread_count: 0 } : c)),
            );
        } catch (err) {
            console.error('Error cargando mensajes:', err);
        }
    }

    async function sendMessage() {
        if (!newMessage.trim() || sending) return;
        const msg = newMessage;
        setNewMessage('');
        setSending(true);
        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/send`,
                { message: msg },
            );
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
            }
        } catch (err) {
            console.error('Error enviando:', err);
            setNewMessage(msg);
        } finally {
            setSending(false);
        }
    }

    async function handleFileUpload(e) {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('image', file);
        setSending(true);
        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/send-image`,
                formData,
            );
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
            }
        } catch (err) {
            console.error('Error enviando imagen:', err);
        } finally {
            setSending(false);
            e.target.value = '';
        }
    }

    function formatTime(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        const now = new Date();
        const diffMs = now - date;
        const diffHours = diffMs / (1000 * 60 * 60);
        
        if (diffHours < 24 && date.getDate() === now.getDate()) {
            return date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
        }
        if (diffHours < 48) return 'Ayer';
        return date.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit' });
    }

    function formatMessageTimeOnly(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        return date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
    }

    function formatFriendlyDate(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);

        if (date.toDateString() === today.toDateString()) return 'HOY';
        if (date.toDateString() === yesterday.toDateString()) return 'AYER';
        
        return date.toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' }).toUpperCase();
    }

    function handleInstanceChange(e) {
        stopPolling();
        setConversations([]);
        setMessages([]);
        setSelectedConversation(null);
        setSelectedInstanceId(e.target.value);
    }

    const StatusIcons = ({ status }) => {
        if (status === 'sent') return <Check className="size-3 text-muted-foreground/40" />;
        if (status === 'delivered') return <CheckCheck className="size-3 text-muted-foreground/40" />;
        if (status === 'read') return <CheckCheck className="size-3 text-sky-400" />;
        return <Clock className="size-2.5 text-muted-foreground/30" />;
    };

    return (
        <>
            <Head title="Chat WhatsApp Business" />
            <div className="h-[calc(100vh-64px)] flex flex-col overflow-hidden bg-[#f0f2f5] dark:bg-[#0b141a]">
                {/* Clean Header */}
                <div className="bg-[#f0f2f5] dark:bg-[#202c33] border-b border-border/10 px-4 py-2 flex justify-between items-center z-20 shadow-sm">
                    <div className="flex items-center gap-3">
                        <div className="size-10 rounded-full bg-teal-600 flex items-center justify-center text-white shadow-sm">
                            <MessageSquare className="size-5" />
                        </div>
                        <div>
                            <h2 className="text-sm font-bold text-foreground">Canales de WhatsApp Business</h2>
                            <p className="text-[10px] text-teal-600 dark:text-teal-400 font-black uppercase tracking-widest">Servicio Multi-agente</p>
                        </div>
                    </div>
                    
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2 bg-background/50 dark:bg-black/20 px-3 py-1.5 rounded-lg border border-border/10">
                            <span className="text-[11px] font-bold text-muted-foreground uppercase opacity-60">Instancia</span>
                            <select
                                value={selectedInstanceId}
                                onChange={handleInstanceChange}
                                className="bg-transparent text-xs font-black focus:outline-none cursor-pointer uppercase tracking-tight"
                            >
                                <option value="" className="bg-background">Elegir...</option>
                                {instances.map(inst => (
                                    <option key={inst.id} value={inst.id} className="bg-background">
                                        {inst.name || 'SIN NOMBRE'}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                {!selectedInstanceId ? (
                    <div className="flex-1 flex items-center justify-center">
                        <div className="text-center max-w-sm">
                            <div className="mx-auto size-20 rounded-full bg-muted/20 flex items-center justify-center mb-6 text-muted-foreground/20">
                                <MessageSquare className="size-10" />
                            </div>
                            <h2 className="text-lg font-bold text-foreground">Conecta una instancia</h2>
                            <p className="mt-2 text-sm text-muted-foreground">Selecciona un canal de WhatsApp para comenzar a gestionar tus chats.</p>
                        </div>
                    </div>
                ) : (
                    <div className="flex-1 flex overflow-hidden">
                        {/* Sidebar - WhatsApp Web Style */}
                        <div className="w-full sm:w-80 lg:w-96 bg-white dark:bg-[#111b21] flex flex-col border-r border-border/10">
                            <div className="p-3">
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <Search className="size-4 text-muted-foreground/60" />
                                    </div>
                                    <input
                                        type="text"
                                        placeholder="Busca o empieza un chat nuevo"
                                        value={searchQuery}
                                        onChange={e => setSearchQuery(e.target.value)}
                                        className="w-full pl-10 pr-4 py-1.5 bg-[#f0f2f5] dark:bg-[#202c33] border-none rounded-lg text-sm transition-all outline-none placeholder:text-muted-foreground/60 text-foreground"
                                    />
                                </div>
                            </div>
                            
                            <div className="flex-1 overflow-y-auto custom-scrollbar">
                                {filteredConversations.map(conv => {
                                    const isActive = selectedConversation?.id === conv.id;
                                    return (
                                        <div
                                            key={conv.id}
                                            onClick={() => selectConversation(conv)}
                                            className={`flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors border-b border-border/5 ${
                                                isActive ? 'bg-[#f0f2f5] dark:bg-[#2a3942]' : 'hover:bg-[#f5f6f6] dark:hover:bg-[#202c33]'
                                            }`}
                                        >
                                            <div className="relative flex-shrink-0">
                                                <div className="size-12 rounded-full bg-[#dfe5e7] dark:bg-[#4f5659] flex items-center justify-center text-white font-bold text-lg overflow-hidden uppercase">
                                                    {conv.initials}
                                                </div>
                                                {conv.unread_count > 0 && (
                                                    <div className="absolute top-0 -right-1 bg-[#25d366] text-white rounded-full min-w-[20px] h-5 px-1 flex items-center justify-center text-[10px] font-bold border-2 border-white dark:border-[#111b21]">
                                                        {conv.unread_count}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex-1 min-w-0 border-b border-border/5 pb-0.5">
                                                <div className="flex justify-between items-baseline">
                                                    <p className="text-sm font-medium text-foreground truncate">
                                                        {conv.name || conv.phone_number}
                                                    </p>
                                                    <span className={`text-[10px] ${conv.unread_count > 0 ? 'text-[#25d366] font-bold' : 'text-muted-foreground'}`}>{formatTime(conv.last_message_at)}</span>
                                                </div>
                                                <div className="flex items-center gap-1 mt-0.5">
                                                    {isActive && <StatusIcons status="read" />}
                                                    <p className="text-xs text-muted-foreground truncate leading-relaxed">
                                                        {conv.last_message || '...'}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Chat Area - WhatsApp Web Theme */}
                        <div className="flex-1 flex flex-col bg-[#e5ddd5] dark:bg-[#0b141a] relative">
                            {/* Theme Pattern Overlay */}
                            <div className="absolute inset-0 opacity-[0.06] dark:opacity-[0.4] pointer-events-none bg-[url('https://p6.zdusercontent.com/attachment/1000679/W3605hFvY6TzF5rVv5vVv5?token=')] bg-repeat" />

                            {!selectedConversation ? (
                                <div className="flex-1 flex items-center justify-center relative z-10">
                                    <div className="text-center max-w-md p-10 bg-white/40 dark:bg-black/10 backdrop-blur-md rounded-[3rem] border border-white/20">
                                        <div className="mx-auto size-24 rounded-full bg-teal-600/10 flex items-center justify-center mb-8">
                                            <MessageSquare className="size-12 text-teal-600/40" />
                                        </div>
                                        <h3 className="text-2xl font-black text-foreground mb-3">Integra Plus para WhatsApp</h3>
                                        <p className="text-sm text-muted-foreground leading-relaxed">Envía y recibe mensajes sin necesidad de mantener tu teléfono conectado. <br/>Centraliza toda tu operación en un solo lugar.</p>
                                        <div className="mt-10 pt-8 border-t border-border/10 text-[10px] font-black text-muted-foreground/40 uppercase tracking-[0.3em]">Cifrado de extremo a extremo</div>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    {/* Chat Header */}
                                    <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-4 py-2 flex items-center justify-between z-10 shadow-sm">
                                        <div className="flex items-center gap-3">
                                            <div className="size-10 rounded-full bg-[#dfe5e7] dark:bg-[#4f5659] flex items-center justify-center text-white font-bold text-lg overflow-hidden uppercase">
                                                {selectedConversation.initials}
                                            </div>
                                            <div className="min-w-0">
                                                <h3 className="text-sm font-bold text-foreground leading-tight truncate">{selectedConversation.name}</h3>
                                                <p className="text-[10px] text-muted-foreground leading-tight">{selectedConversation.phone_number}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <button className="p-2 text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-colors"><Search className="size-5" /></button>
                                            <button className="p-2 text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-colors"><MoreVertical className="size-5" /></button>
                                        </div>
                                    </div>

                                    {/* Messages Area */}
                                    <div
                                        ref={messagesContainerRef}
                                        className="flex-1 overflow-y-auto p-4 sm:p-8 space-y-2 custom-scrollbar relative z-10 flex flex-col"
                                    >
                                        {messages.map((msg, i) => {
                                            const isOut = msg.direction === 'outbound';
                                            
                                            // Handle Date Separator
                                            const prevMsg = i > 0 ? messages[i-1] : null;
                                            const currDate = new Date(msg.created_at).toDateString();
                                            const prevDate = prevMsg ? new Date(prevMsg.created_at).toDateString() : null;
                                            const showDateSeparator = currDate !== prevDate;
                                            
                                            return (
                                                <Fragment key={msg.id}>
                                                    {showDateSeparator && (
                                                        <div className="flex justify-center my-4 sticky top-2 z-30">
                                                            <div className="bg-white/80 dark:bg-[#202c33]/80 backdrop-blur-sm px-3 py-1.5 rounded-lg text-[10.5px] font-bold text-muted-foreground/80 dark:text-white/40 shadow-sm uppercase tracking-widest pointer-events-none">
                                                                {formatFriendlyDate(msg.created_at)}
                                                            </div>
                                                        </div>
                                                    )}
                                                    
                                                    <div
                                                        className={`flex mb-2 sm:mb-3 ${isOut ? 'justify-end pr-4' : 'justify-start pl-4'}`}
                                                    >
                                                        <div 
                                                            className={`relative px-3 py-1.5 shadow-sm min-w-[110px] max-w-[85%] lg:max-w-[70%] group ${
                                                                isOut 
                                                                    ? 'bg-[#dcf8c6] dark:bg-[#005c4b] text-[#111b21] dark:text-[#e9edef] rounded-lg rounded-tr-none' 
                                                                    : 'bg-white dark:bg-[#202c33] text-[#111b21] dark:text-[#e9edef] rounded-lg rounded-tl-none'
                                                            }`}
                                                            title={new Date(msg.created_at).toLocaleString('es-CO')}
                                                        >
                                                            {/* Triangle/Tail tip */}
                                                            {isOut ? (
                                                                <div className="absolute top-0 -right-2 w-0 h-0 border-t-[10px] border-t-transparent border-l-[12px] border-l-[#dcf8c6] dark:border-l-[#005c4b]" />
                                                            ) : (
                                                                <div className="absolute top-0 -left-2 w-0 h-0 border-t-[10px] border-t-transparent border-r-[12px] border-r-white dark:border-r-[#202c33]" />
                                                            )}

                                                            <div className="flex flex-col relative">
                                                                {msg.type === 'text' && (
                                                                    <p className="text-[13.5px] leading-[19px] whitespace-pre-wrap break-words pr-20 pb-1 font-medium">{msg.content}</p>
                                                                )}
                                                                
                                                                {msg.type === 'image' && (
                                                                    <div className="p-1 pb-1">
                                                                        <div className="relative group overflow-hidden rounded-md bg-black/5 mb-2">
                                                                            <img
                                                                                src={msg.media_url}
                                                                                className="max-h-[300px] w-full object-cover cursor-pointer hover:opacity-90 transition-opacity"
                                                                                onClick={(e) => { 
                                                                                    e.stopPropagation();
                                                                                    setSelectedImage(msg.media_url); 
                                                                                }}
                                                                                alt="media"
                                                                            />
                                                                        </div>
                                                                        {msg.content && <p className="text-[13.5px] leading-[19px] mb-6 pr-10">{msg.content}</p>}
                                                                    </div>
                                                                )}
                                                                
                                                                {msg.type === 'audio' && (
                                                                    <div className="min-w-[220px] py-1 pr-14">
                                                                        <audio controls src={msg.media_url} className="w-full h-8 opacity-90 scale-90 origin-left" />
                                                                    </div>
                                                                )}

                                                                {(msg.type === 'template' || msg.type === 'document') && (
                                                                    <div className={`flex flex-col gap-2 p-3 rounded-lg my-1 w-full ${isOut ? 'bg-black/5' : 'bg-[#f0f2f5] dark:bg-[#111b21]'}`}>
                                                                        <div className="flex items-center gap-3">
                                                                            <div className="size-10 rounded bg-[#4f5659] text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                                                                <Paperclip className="size-5" />
                                                                            </div>
                                                                            <div className="min-w-0">
                                                                                <p className="text-[10px] font-black opacity-40 uppercase tracking-[0.2em]">{msg.type === 'template' ? 'Plantilla WhatsApp' : 'Documento Adjunto'}</p>
                                                                            </div>
                                                                        </div>
                                                                        <div className="mt-1 pb-6">
                                                                            <p className="text-[13.5px] leading-relaxed font-medium whitespace-pre-wrap break-words opacity-90">{msg.content || 'Sin descripción'}</p>
                                                                        </div>
                                                                    </div>
                                                                )}

                                                                {/* Internal timestamp inside bubble - ALWAYS HH:mm */}
                                                                <div className="absolute bottom-[0px] right-[0px] flex items-center gap-1.5 p-1">
                                                                    <span className="text-[9px] font-bold text-muted-foreground/60 dark:text-white/30 whitespace-nowrap uppercase tracking-tighter">{formatMessageTimeOnly(msg.created_at)}</span>
                                                                    {isOut && <StatusIcons status={msg.status} />}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </Fragment>
                                            );
                                        })}
                                    </div>

                                    {/* Input Area - WhatsApp Web Style */}
                                    <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-3 py-2 flex items-center gap-2 z-10 text-foreground">
                                        <div className="flex items-center">
                                            <button className="p-2 text-muted-foreground hover:text-foreground transition-colors"><Smile className="size-6" /></button>
                                            <label className="p-2 text-muted-foreground hover:text-foreground cursor-pointer transition-colors">
                                                <Paperclip className="size-6" />
                                                <input type="file" onChange={handleFileUpload} accept="image/*" className="hidden" />
                                            </label>
                                        </div>
                                        
                                        <div className="flex-1">
                                            <input
                                                type="text"
                                                placeholder="Escribe un mensaje aquí"
                                                value={newMessage}
                                                onChange={e => setNewMessage(e.target.value)}
                                                onKeyUp={e => e.key === 'Enter' && sendMessage()}
                                                disabled={sending}
                                                className="w-full bg-white dark:bg-[#2a3942] border-none rounded-lg px-4 py-2 text-[14.5px] outline-none placeholder:text-muted-foreground/60 text-foreground"
                                            />
                                        </div>
                                        
                                        <button
                                            onClick={sendMessage}
                                            disabled={!newMessage.trim() || sending}
                                            className="p-2 text-[#54656f] dark:text-[#8696a0] hover:text-teal-600 transition-colors"
                                        >
                                            <Send className={`size-6 ${sending ? 'animate-pulse' : ''}`} />
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                )}

                {/* Lightbox / Image Zoom */}
                {selectedImage && (
                    <div
                        className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-black/95 animate-in fade-in duration-300"
                        onClick={() => setSelectedImage(null)}
                    >
                        <div className="absolute top-0 w-full p-4 flex justify-between items-center text-white bg-gradient-to-b from-black/50 to-transparent">
                            <div className="flex items-center gap-3">
                                <button className="p-2 hover:bg-white/10 rounded-full transition-colors"><ArrowLeft className="size-6" /></button>
                                <span className="text-sm font-medium">Visualización de archivo</span>
                            </div>
                            <button className="p-2 hover:bg-white/10 rounded-full transition-colors"><MoreVertical className="size-6" /></button>
                        </div>
                        <img
                            src={selectedImage}
                            className="max-w-[90%] max-h-[80vh] object-contain shadow-2xl animate-in zoom-in-105 duration-300"
                            onClick={e => e.stopPropagation()}
                            alt="full view"
                        />
                    </div>
                )}
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar {
                    width: 6px;
                }
                .custom-scrollbar::-webkit-scrollbar-track {
                    background: transparent;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(134, 150, 160, 0.2);
                    border-radius: 10px;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                    background: rgba(134, 150, 160, 0.4);
                }
            `}} />
        </>
    );
}

ChatIndex.layout = page => <AppLayout breadcrumb={['Chat']}>{page}</AppLayout>;
