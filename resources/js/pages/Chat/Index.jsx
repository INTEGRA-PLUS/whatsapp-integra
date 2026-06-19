import { useState, useEffect, useRef, useMemo, useCallback, Fragment, memo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import axios from 'axios';
import { clsx } from 'clsx';
import { 
    Search, 
    Send, 
    Image as ImageIcon, 
    MoreVertical, 
    MoreHorizontal,
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
    ChevronDown,
    Filter,
    Tag as TagIcon,
    PlusCircle,
    X as XIcon,
    UserPlus,
    Zap,
    Square,
    Trash2,
    X,
    StickyNote,
    AtSign
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    DropdownMenuSeparator,
    DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';
import QuickReplyPicker from '@/components/quick-reply-picker';

const QUICK_REPLY_TOKEN = /(?:^|\s)\/([a-zA-Z0-9_-]*)$/;

function detectQuickReplyToken(value, cursor) {
    const before = value.slice(0, cursor);
    const match = before.match(QUICK_REPLY_TOKEN);
    if (!match) return null;
    return { query: match[1], tokenStart: cursor - match[1].length - 1 };
}

const MENTION_TOKEN = /(?:^|\s)@([a-zA-Z0-9_.À-ſ-]*)$/;

function detectMentionToken(value, cursor) {
    const before = value.slice(0, cursor);
    const match = before.match(MENTION_TOKEN);
    if (!match) return null;
    return { query: match[1], tokenStart: cursor - match[1].length - 1 };
}

// ─── StatusIcons Sub-component ───────────────────────────────────────────────

const StatusIcons = memo(({ status }) => {
    if (status === 'sent') return <Check className="size-3 text-muted-foreground/40" />;
    if (status === 'delivered') return <CheckCheck className="size-3 text-muted-foreground/40" />;
    if (status === 'read') return <CheckCheck className="size-3 text-sky-400" />;
    return <Clock className="size-2.5 text-muted-foreground/30" />;
});

// ─── ConversationItem Component ──────────────────────────────────────────────

const ConversationItem = memo(({ 
    conv, 
    isActive, 
    onSelect, 
    onAttachTag, 
    onDetachTag, 
    onNewTag, 
    onAssign,
    tags, 
    companyUsers,
    isAdmin,
    formatTime
}) => {
    return (
        <div
            onClick={() => onSelect(conv)}
            className={clsx(
                "flex items-center gap-3 px-4 py-3 cursor-pointer transition-all border-b border-border/5 group/conv",
                isActive ? 'bg-[#f0f2f5] dark:bg-[#2a3942]' : 'hover:bg-[#f5f6f6] dark:hover:bg-[#202c33]'
            )}
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
                <div className="flex items-center justify-between gap-2 mb-0.5">
                    <div className="flex items-center gap-2 min-w-0 flex-1">
                        <p className="text-sm font-bold text-foreground truncate">
                            {conv.name || conv.phone_number}
                        </p>
                        {conv.assigned_agent && (
                            <span 
                                title={`Asignado a ${conv.assigned_agent.name}`}
                                className="shrink-0 text-[7px] leading-none bg-teal-500/10 text-teal-600 dark:text-teal-400 px-1.5 py-1 rounded-md font-black uppercase tracking-tighter border border-teal-500/10"
                            >
                                {conv.assigned_agent.name.split(' ')[0]}
                            </span>
                        )}
                    </div>
                    
                    <div className="flex items-center gap-1 shrink-0 ml-auto">
                        <span className={`text-[10px] whitespace-nowrap ${conv.unread_count > 0 ? 'text-[#25d366] font-bold' : 'text-muted-foreground/60'}`}>
                            {formatTime(conv.last_message_at)}
                        </span>
                        
                        {/* Admin Assignment Picker */}
                        {isAdmin && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button 
                                        onClick={(e) => e.stopPropagation()}
                                        className={clsx(
                                            "p-1 opacity-0 group-hover/conv:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-all",
                                            conv.assigned_to ? "text-teal-600" : "text-muted-foreground/60 hover:text-teal-600"
                                        )}
                                        title={conv.assigned_agent?.name ? `Asignado a ${conv.assigned_agent.name}` : "Asignar agente"}
                                    >
                                        <UserPlus className="size-3" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent onClick={(e) => e.stopPropagation()} align="end" className="w-56 rounded-lg border-border/10 shadow-xl">
                                    <DropdownMenuLabel className="text-[9px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-1.5">Asignar Responsable</DropdownMenuLabel>
                                    <DropdownMenuSeparator className="bg-border/5" />
                                    <DropdownMenuItem 
                                        onClick={(e) => { e.stopPropagation(); onAssign(conv.id, null); }}
                                        className="flex items-center gap-2 py-2 px-3 cursor-pointer"
                                    >
                                        <XIcon className="size-3 text-muted-foreground" />
                                        <span className="text-[11px] font-bold flex-1">Sin Asignar</span>
                                        {!conv.assigned_to && <Check className="size-3 text-teal-600" />}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator className="bg-border/5" />
                                    <div className="max-h-48 overflow-y-auto">
                                        {companyUsers.map(u => (
                                            <DropdownMenuItem 
                                                key={u.id}
                                                onClick={(e) => { e.stopPropagation(); onAssign(conv.id, u.id); }}
                                                className="flex items-center gap-2.5 py-2 px-3 cursor-pointer group/user"
                                            >
                                                <div className={clsx(
                                                    "size-2.5 rounded-full",
                                                    Number(conv.assigned_to) === Number(u.id) ? "bg-teal-600" : "bg-slate-200 dark:bg-slate-700"
                                                )} />
                                                <span className="text-[11px] font-bold flex-1">{u.name}</span>
                                                {Number(conv.assigned_to) === Number(u.id) && <Check className="size-3 text-teal-600" />}
                                            </DropdownMenuItem>
                                        ))}
                                    </div>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}

                        {/* Tag Picker Button */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button 
                                    onClick={(e) => e.stopPropagation()}
                                    className="p-1 opacity-0 group-hover/conv:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-all text-muted-foreground/60 hover:text-teal-600"
                                >
                                    <TagIcon className="size-3" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent onClick={(e) => e.stopPropagation()} align="end" className="w-48 rounded-lg border-border/10 shadow-xl">
                                <DropdownMenuLabel className="text-[9px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-1.5">Etiquetas</DropdownMenuLabel>
                                <DropdownMenuSeparator className="bg-border/5" />
                                <div className="max-h-48 overflow-y-auto">
                                    {tags.map(tag => {
                                        const hasTag = (conv.tags || []).some(t => t.id === tag.id);
                                        return (
                                            <DropdownMenuItem 
                                                key={tag.id}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    hasTag ? onDetachTag(conv.id, tag.id) : onAttachTag(conv.id, tag.id);
                                                }}
                                                className="flex items-center gap-2.5 py-2 px-3 cursor-pointer group/tag"
                                            >
                                                <div className="size-2.5 rounded-full" style={{ backgroundColor: tag.color }} />
                                                <span className="text-[11px] font-bold flex-1">{tag.name}</span>
                                                {hasTag && <Check className="size-3 text-teal-600" />}
                                            </DropdownMenuItem>
                                        );
                                    })}
                                </div>
                                <DropdownMenuSeparator className="bg-border/5" />
                                <DropdownMenuItem 
                                    onClick={(e) => { 
                                        e.stopPropagation(); 
                                        onNewTag(conv.id);
                                    }}
                                    className="flex items-center gap-2 py-2 px-3 cursor-pointer text-teal-600"
                                >
                                    <PlusCircle className="size-3" />
                                    <span className="text-[11px] font-bold">Nueva Etiqueta</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                
                {(conv.tags || []).length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-1 mb-1">
                        {conv.tags.map(tag => (
                            <span 
                                key={tag.id} 
                                className="text-[8px] font-black px-1.5 py-0.5 rounded-full text-white uppercase tracking-tighter shadow-sm"
                                style={{ backgroundColor: tag.color }}
                            >
                                {tag.name}
                            </span>
                        ))}
                    </div>
                )}

                <div className="flex items-center gap-1 mt-0.5">
                    {isActive && <StatusIcons status="read" />}
                    <div className="flex-1 flex items-center gap-1.5 min-w-0">
                        {conv.assigned_agent && (
                            <span className="text-[9px] font-black text-teal-600/60 uppercase tracking-tighter whitespace-nowrap">
                                @{conv.assigned_agent.name.split(' ')[0]}:
                            </span>
                        )}
                        <p className="text-xs text-muted-foreground truncate leading-relaxed">
                            {conv.last_message || '...'}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
});

// ─── Main Component ──────────────────────────────────────────────────────────

export default function ChatIndex({ instances }) {
    const { auth } = usePage().props;
    
    // Helper to check permissions
    const can = (permission) => auth.user.permissions.includes(permission);
    const isAdmin = can('chat.update');

    const [selectedInstanceId, setSelectedInstanceId] = useState('');
    const [conversations, setConversations] = useState([]);
    const [messages, setMessages] = useState([]);
    const [selectedConversation, setSelectedConversation] = useState(null);
    const [newMessage, setNewMessage] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [sending, setSending] = useState(false);
    const [lastUpdateTimestamp, setLastUpdateTimestamp] = useState(null);
    const [lastUpdate, setLastUpdate] = useState('Nunca');
    const [isPolling, setIsPolling] = useState(false);
    const [selectedImage, setSelectedImage] = useState(null);
    const [filterMyAssignments, setFilterMyAssignments] = useState(false);
    const [tags, setTags] = useState([]);
    const [selectedTagId, setSelectedTagId] = useState('');
    const [selectedAgentId, setSelectedAgentId] = useState('');
    const [agentFilterQuery, setAgentFilterQuery] = useState('');
    const [isCreatingTag, setIsCreatingTag] = useState(false);
    const [newTagName, setNewTagName] = useState('');
    const [newTagColor, setNewTagColor] = useState('#0d9488');
    const [taggingConversationId, setTaggingConversationId] = useState(null);
    const [companyUsers, setCompanyUsers] = useState([]);

    // Recording States
    const [isRecording, setIsRecording] = useState(false);
    const [recordingDuration, setRecordingDuration] = useState(0);
    const mediaRecorderRef = useRef(null);
    const audioChunksRef = useRef([]);
    const recordingIntervalRef = useRef(null);

    const [quickReplies, setQuickReplies] = useState([]);
    const [qrOpen, setQrOpen] = useState(false);
    const [qrQuery, setQrQuery] = useState('');
    const [qrTokenStart, setQrTokenStart] = useState(0);
    const [qrIndex, setQrIndex] = useState(0);

    // Internal notes + @mentions
    const [composerMode, setComposerMode] = useState('reply'); // 'reply' | 'note'
    const [noteMentions, setNoteMentions] = useState([]); // [{id, name}]
    const [mentionOpen, setMentionOpen] = useState(false);
    const [mentionQuery, setMentionQuery] = useState('');
    const [mentionTokenStart, setMentionTokenStart] = useState(0);
    const [mentionIndex, setMentionIndex] = useState(0);

    const messagesContainerRef = useRef(null);
    const pollingIntervalRef = useRef(null);
    const messageInputRef = useRef(null);

    // ── Callbacks for Performance ──────────────────────────────────────────

    const loadTags = useCallback(async () => {
        try {
            const res = await axios.get('/api/tags');
            setTags(res.data);
        } catch (err) {
            console.error('Error cargando etiquetas:', err);
        }
    }, []);

    const loadUsers = useCallback(async () => {
        try {
            const res = await axios.get('/api/chat/users');
            setCompanyUsers(res.data);
        } catch (err) {
            console.error('Error cargando usuarios:', err);
        }
    }, []);

    const loadQuickReplies = useCallback(async () => {
        try {
            const res = await axios.get('/api/quick-replies');
            setQuickReplies(res.data);
        } catch (err) {
            console.error('Error cargando respuestas rápidas:', err);
        }
    }, []);

    useEffect(() => {
        loadTags();
        loadUsers();
        loadQuickReplies();
    }, [loadTags, loadUsers, loadQuickReplies]);

    const qrMatches = useMemo(() => {
        if (!qrOpen) return [];
        const q = qrQuery.toLowerCase();
        const filtered = q
            ? quickReplies.filter(r => r.shortcut.toLowerCase().startsWith(q))
            : quickReplies;
        return filtered.slice(0, 8);
    }, [qrOpen, qrQuery, quickReplies]);

    useEffect(() => {
        if (qrIndex >= qrMatches.length) setQrIndex(0);
    }, [qrMatches.length, qrIndex]);

    const closeQuickReplies = useCallback(() => {
        setQrOpen(false);
        setQrQuery('');
        setQrIndex(0);
    }, []);

    const updateQuickReplyState = useCallback((value, cursor) => {
        const detected = detectQuickReplyToken(value, cursor);
        if (!detected) {
            if (qrOpen) closeQuickReplies();
            return;
        }
        setQrOpen(true);
        setQrQuery(detected.query);
        setQrTokenStart(detected.tokenStart);
        setQrIndex(0);
    }, [qrOpen, closeQuickReplies]);

    const applyQuickReply = useCallback((reply) => {
        const input = messageInputRef.current;
        const cursor = input ? input.selectionStart ?? newMessage.length : newMessage.length;
        const before = newMessage.slice(0, qrTokenStart);
        const after = newMessage.slice(cursor);
        const next = `${before}${reply.message}${after}`;
        setNewMessage(next);
        closeQuickReplies();
        requestAnimationFrame(() => {
            const el = messageInputRef.current;
            if (!el) return;
            el.focus();
            const pos = before.length + reply.message.length;
            try { el.setSelectionRange(pos, pos); } catch (_) {}
        });
    }, [newMessage, qrTokenStart, closeQuickReplies]);

    const mentionMatches = useMemo(() => {
        if (!mentionOpen) return [];
        const q = mentionQuery.toLowerCase();
        const list = q
            ? companyUsers.filter(u => u.name.toLowerCase().includes(q))
            : companyUsers;
        return list.slice(0, 8);
    }, [mentionOpen, mentionQuery, companyUsers]);

    useEffect(() => {
        if (mentionIndex >= mentionMatches.length) setMentionIndex(0);
    }, [mentionMatches.length, mentionIndex]);

    const closeMentions = useCallback(() => {
        setMentionOpen(false);
        setMentionQuery('');
        setMentionIndex(0);
    }, []);

    const applyMention = useCallback((user) => {
        const input = messageInputRef.current;
        const cursor = input ? input.selectionStart ?? newMessage.length : newMessage.length;
        const before = newMessage.slice(0, mentionTokenStart);
        const after = newMessage.slice(cursor);
        const insert = `@${user.name} `;
        const next = `${before}${insert}${after}`;
        setNewMessage(next);
        setNoteMentions(prev => (prev.some(m => m.id === user.id) ? prev : [...prev, { id: user.id, name: user.name }]));
        closeMentions();
        requestAnimationFrame(() => {
            const el = messageInputRef.current;
            if (!el) return;
            el.focus();
            const pos = before.length + insert.length;
            try { el.setSelectionRange(pos, pos); } catch (_) {}
        });
    }, [newMessage, mentionTokenStart, closeMentions]);

    const updateMentionState = useCallback((value, cursor) => {
        const detected = detectMentionToken(value, cursor);
        if (!detected) {
            if (mentionOpen) closeMentions();
            return;
        }
        setMentionOpen(true);
        setMentionQuery(detected.query);
        setMentionTokenStart(detected.tokenStart);
        setMentionIndex(0);
    }, [mentionOpen, closeMentions]);

    const handleComposerChange = useCallback((e) => {
        const value = e.target.value;
        setNewMessage(value);
        const cursor = e.target.selectionStart ?? value.length;
        if (composerMode === 'note') {
            updateMentionState(value, cursor);
            // Drop tracked mentions whose @Name no longer appears in the text.
            setNoteMentions(prev => prev.filter(m => value.includes(`@${m.name}`)));
        } else {
            updateQuickReplyState(value, cursor);
        }
    }, [composerMode, updateQuickReplyState, updateMentionState]);

    const handleComposerKeyDown = useCallback((e) => {
        if (mentionOpen && mentionMatches.length > 0) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setMentionIndex(i => (i + 1) % mentionMatches.length); return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); setMentionIndex(i => (i - 1 + mentionMatches.length) % mentionMatches.length); return; }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                applyMention(mentionMatches[mentionIndex]);
                return;
            }
            if (e.key === 'Escape') { e.preventDefault(); closeMentions(); return; }
        }
        if (qrOpen && qrMatches.length > 0) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setQrIndex(i => (i + 1) % qrMatches.length); return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); setQrIndex(i => (i - 1 + qrMatches.length) % qrMatches.length); return; }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                applyQuickReply(qrMatches[qrIndex]);
                return;
            }
        }
        if (e.key === 'Escape' && qrOpen) {
            e.preventDefault();
            closeQuickReplies();
            return;
        }
        if (e.key === 'Enter' && !qrOpen && !mentionOpen && !e.shiftKey) {
            e.preventDefault();
            if (composerMode === 'note') sendNote(); else sendMessage();
        }
    }, [qrOpen, qrMatches, qrIndex, applyQuickReply, closeQuickReplies, mentionOpen, mentionMatches, mentionIndex, applyMention, closeMentions, composerMode]);

    useEffect(() => {
        const el = messageInputRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    }, [newMessage]);

    const assignConversation = useCallback(async (convId, userId) => {
        try {
            const res = await axios.post(`/api/chat/conversations/${convId}/assign`, { user_id: userId });
            if (res.data.success) {
                const agent = res.data.assigned_agent;
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, assigned_to: userId, assigned_agent: agent } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, assigned_to: userId, assigned_agent: agent } : prev));
            }
        } catch (err) {
            console.error('Error asignando conversación:', err);
        }
    }, []);

    const attachTag = useCallback(async (convId, tagId) => {
        try {
            const res = await axios.post(`/api/tags/conversations/${convId}/attach`, { tag_id: tagId });
            if (res.data.success) {
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, tags: res.data.tags } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, tags: res.data.tags } : prev));
            }
        } catch (err) {
            console.error('Error adjuntando etiqueta:', err);
        }
    }, []);

    const detachTag = useCallback(async (convId, tagId) => {
        try {
            const res = await axios.post(`/api/tags/conversations/${convId}/detach`, { tag_id: tagId });
            if (res.data.success) {
                setConversations(prev => prev.map(c => c.id === convId ? { ...c, tags: res.data.tags } : c));
                setSelectedConversation(prev => (prev?.id === convId ? { ...prev, tags: res.data.tags } : prev));
            }
        } catch (err) {
            console.error('Error quitando etiqueta:', err);
        }
    }, []);

    const createTag = useCallback(async () => {
        if (!newTagName.trim()) return;
        try {
            const res = await axios.post('/api/tags', { name: newTagName, color: newTagColor });
            const createdTag = res.data;
            setTags(prev => [...prev, createdTag]);
            
            if (taggingConversationId) {
                await attachTag(taggingConversationId, createdTag.id);
            }

            setNewTagName('');
            setIsCreatingTag(false);
            setTaggingConversationId(null);
        } catch (err) {
            console.error('Error creando etiqueta:', err);
        }
    }, [newTagName, newTagColor, taggingConversationId, attachTag]);

    const handleNewTag = useCallback((convId) => {
        setTaggingConversationId(convId);
        setIsCreatingTag(true);
    }, []);

    const selectConversation = useCallback(async (conv) => {
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
    }, []);

    const openConversationById = useCallback(async (id) => {
        try {
            const res = await axios.get(`/api/chat/conversations/${id}/messages`);
            if (res.data.conversation) {
                setSelectedConversation(res.data.conversation);
                setMessages(res.data.messages);
                setLastUpdateTimestamp(res.data.timestamp);
            }
        } catch (err) {
            console.error('Error abriendo conversación:', err);
        }
    }, []);

    // Deep-link from the notification bell: /chat?conversation=ID&instance=ID
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const convId = params.get('conversation');
        const inst = params.get('instance');
        if (inst) setSelectedInstanceId(inst);
        if (convId) openConversationById(convId);
        if (convId || inst) {
            window.history.replaceState({}, '', '/chat');
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ── Logic ──────────────────────────────────────────────────────────────

    const hasActiveFilters = Boolean(
        filterMyAssignments || selectedTagId || selectedAgentId || searchQuery.trim()
    );

    const activeFilterCount =
        (filterMyAssignments ? 1 : 0) +
        (selectedTagId ? 1 : 0) +
        (selectedAgentId ? 1 : 0) +
        (searchQuery.trim() ? 1 : 0);

    const resetFilters = useCallback(() => {
        setFilterMyAssignments(false);
        setSelectedTagId('');
        setSelectedAgentId('');
        setAgentFilterQuery('');
        setSearchQuery('');
    }, []);

    const filteredConversations = useMemo(() => {
        let items = conversations;
        if (filterMyAssignments) {
            items = items.filter(c => Number(c.assigned_to) === Number(auth.user.id));
        }
        if (selectedAgentId) {
            if (selectedAgentId === 'unassigned') {
                items = items.filter(c => !c.assigned_to);
            } else {
                items = items.filter(c => Number(c.assigned_to) === Number(selectedAgentId));
            }
        }
        if (selectedTagId) {
            items = items.filter(c => (c.tags || []).some(t => String(t.id) === String(selectedTagId)));
        }
        if (!searchQuery) return items;
        const q = searchQuery.toLowerCase();
        return items.filter(
            c => (c.name || '').toLowerCase().includes(q) || (c.phone_number || '').includes(q) || (c.last_message || '').toLowerCase().includes(q),
        );
    }, [conversations, searchQuery, filterMyAssignments, selectedTagId, selectedAgentId, auth.user.id]);

    const scrollToBottom = useCallback(() => {
        const el = messagesContainerRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, []);

    useEffect(() => {
        if (instances.length > 0 && !selectedInstanceId) {
            const first = instances[0];
            setSelectedInstanceId(String(first.id));
        }
    }, [instances, selectedInstanceId]);

    // ── Search Debounce ───────────────────────────────────────────────────
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(searchQuery), 300);
        return () => clearTimeout(timer);
    }, [searchQuery]);

    useEffect(() => {
        // Reset and load when search changes
        setPage(1);
        setConversations([]);
        setHasMore(true);
    }, [debouncedSearch, selectedInstanceId, selectedTagId, selectedAgentId, filterMyAssignments]);

    const loadConversations = useCallback(async (pageNum = 1) => {
        if (!selectedInstanceId || loadingMore) return;
        
        try {
            setLoadingMore(true);
            const params = { 
                instance_id: selectedInstanceId,
                page: pageNum,
                search: debouncedSearch,
                tag_id: selectedTagId,
                assigned_to: selectedAgentId === 'unassigned' ? 'unassigned' : selectedAgentId,
            };
            
            if (filterMyAssignments) params.assigned_to = auth.user.id;

            const res = await axios.get('/api/chat/conversations', { params });
            
            const newItems = res.data.data;
            setConversations(prev => pageNum === 1 ? newItems : [...prev, ...newItems]);
            setHasMore(res.data.next_page_url !== null);
            setPage(pageNum);
        } catch (err) {
            console.error('Error cargando conversaciones:', err);
        } finally {
            setLoadingMore(false);
        }
    }, [selectedInstanceId, debouncedSearch, selectedTagId, selectedAgentId, filterMyAssignments, auth.user.id]);

    useEffect(() => {
        loadConversations(1);
    }, [debouncedSearch, selectedInstanceId, selectedTagId, selectedAgentId, filterMyAssignments, loadConversations]);

    const sidebarScrollRef = useRef(null);
    const observerTarget = useRef(null);

    useEffect(() => {
        const observer = new IntersectionObserver(
            entries => {
                if (entries[0].isIntersecting && hasMore && !loadingMore) {
                    loadConversations(page + 1);
                }
            },
            { threshold: 0.5 }
        );

        if (observerTarget.current) observer.observe(observerTarget.current);
        return () => observer.disconnect();
    }, [hasMore, loadingMore, page, loadConversations]);

    useEffect(() => {
        startPolling();
        return () => stopPolling();
    }, [selectedInstanceId, lastUpdateTimestamp]);

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

    async function sendNote() {
        if (!newMessage.trim() || sending) return;
        const content = newMessage;
        const mentions = noteMentions
            .filter(m => content.includes(`@${m.name}`))
            .map(m => m.id);
        setNewMessage('');
        setNoteMentions([]);
        setSending(true);
        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/note`,
                { content, mentions },
            );
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
            }
        } catch (err) {
            console.error('Error guardando nota:', err);
            setNewMessage(content);
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

    async function startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mediaRecorder = new MediaRecorder(stream);
            mediaRecorderRef.current = mediaRecorder;
            audioChunksRef.current = [];

            mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    audioChunksRef.current.push(e.data);
                }
            };

            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunksRef.current, { type: 'audio/ogg; codecs=opus' });
                if (audioChunksRef.current.length > 0) {
                    await sendAudioMessage(audioBlob);
                }
                stream.getTracks().forEach(track => track.stop());
            };

            mediaRecorder.start();
            setIsRecording(true);
            setRecordingDuration(0);
            recordingIntervalRef.current = setInterval(() => {
                setRecordingDuration(prev => prev + 1);
            }, 1000);
        } catch (err) {
            console.error('Error al acceder al micrófono:', err);
            alert('No se pudo acceder al micrófono. Por favor verifica los permisos.');
        }
    }

    function stopRecording() {
        if (mediaRecorderRef.current && isRecording) {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
            clearInterval(recordingIntervalRef.current);
        }
    }

    function cancelRecording() {
        if (mediaRecorderRef.current && isRecording) {
            audioChunksRef.current = [];
            mediaRecorderRef.current.stop();
            setIsRecording(false);
            clearInterval(recordingIntervalRef.current);
        }
    }

    async function sendAudioMessage(blob) {
        const formData = new FormData();
        formData.append('audio', blob, 'recording.ogg');
        setSending(true);
        try {
            const res = await axios.post(
                `/api/chat/conversations/${selectedConversation.id}/send-audio`,
                formData,
            );
            if (res.data.success) {
                setMessages(prev => [...prev, res.data.data]);
            }
        } catch (err) {
            console.error('Error enviando audio:', err);
        } finally {
            setSending(false);
        }
    }

    const formatTime = useCallback((ts) => {
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
    }, []);

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

    function formatDuration(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    function handleInstanceChange(e) {
        stopPolling();
        setConversations([]);
        setMessages([]);
        setSelectedConversation(null);
        setSelectedInstanceId(e.target.value);
    }

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

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button className={clsx(
                                    "p-2 rounded-lg transition-colors border border-border/10 flex items-center justify-center",
                                    (filterMyAssignments || selectedTagId || selectedAgentId) ? "bg-teal-600 text-white border-teal-600 shadow-sm" : "bg-background/50 dark:bg-black/20 text-muted-foreground hover:text-foreground"
                                )}>
                                    <MoreHorizontal className="size-5" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-64 rounded-xl border-border/10 shadow-2xl">
                                <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Opciones de Chat</DropdownMenuLabel>
                                <DropdownMenuSeparator className="bg-border/5" />
                                
                                <DropdownMenuItem 
                                    onClick={() => { setFilterMyAssignments(!filterMyAssignments); setSelectedAgentId(''); }}
                                    className="flex items-center gap-3 py-3 px-3 cursor-pointer group"
                                >
                                    <div className={clsx(
                                        "size-8 rounded-lg flex items-center justify-center transition-colors shadow-sm",
                                        filterMyAssignments ? "bg-teal-600 text-white" : "bg-slate-100 dark:bg-slate-800 text-slate-500 group-hover:bg-teal-50 group-hover:text-teal-600"
                                    )}>
                                        <User className="size-4" />
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-xs font-bold leading-none mb-1">Mis Asignaciones</span>
                                        <span className="text-[9px] font-medium text-muted-foreground leading-none">
                                            {filterMyAssignments ? 'Viendo solo mis chats' : 'Ver todos los chats'}
                                        </span>
                                    </div>
                                    {filterMyAssignments && <Check className="size-4 text-teal-600 ml-auto" />}
                                </DropdownMenuItem>

                                {isAdmin && companyUsers.length > 0 && (
                                    <>
                                        <DropdownMenuSeparator className="bg-border/5" />
                                        <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2 flex justify-between items-center">
                                            <span>Filtrar por Agente</span>
                                            {selectedAgentId && <span className="text-[8px] bg-teal-600 text-white px-1 rounded">Activo</span>}
                                        </DropdownMenuLabel>
                                        
                                        {/* Search Input for Agents */}
                                        <div className="px-2 pb-2" onClick={(e) => e.stopPropagation()}>
                                            <div className="relative">
                                                <Search className="absolute left-2 top-1/2 -translate-y-1/2 size-3 text-muted-foreground/40" />
                                                <input 
                                                    type="text"
                                                    placeholder="Buscar agente..."
                                                    value={agentFilterQuery}
                                                    onChange={(e) => setAgentFilterQuery(e.target.value)}
                                                    className="w-full bg-slate-100 dark:bg-slate-800 border-none rounded-lg pl-7 pr-2 py-1.5 text-[11px] focus:ring-1 focus:ring-teal-600/20 outline-none"
                                                />
                                            </div>
                                        </div>

                                        <div className="max-h-60 overflow-y-auto px-1 py-1 custom-scrollbar">
                                            {!agentFilterQuery && (
                                                <DropdownMenuItem 
                                                    onClick={() => { setSelectedAgentId(selectedAgentId === 'unassigned' ? '' : 'unassigned'); setFilterMyAssignments(false); }}
                                                    className="flex items-center gap-3 py-2 px-3 cursor-pointer group"
                                                >
                                                    <div className={clsx(
                                                        "size-7 rounded-lg flex items-center justify-center transition-all",
                                                        selectedAgentId === 'unassigned' ? "bg-amber-500 text-white" : "bg-slate-100 dark:bg-slate-800 text-slate-400 group-hover:bg-amber-50 group-hover:text-amber-600"
                                                    )}>
                                                        <XIcon className="size-3.5" />
                                                    </div>
                                                    <span className="text-xs font-bold flex-1 text-amber-600 dark:text-amber-400">Sin Asignar</span>
                                                    {selectedAgentId === 'unassigned' && <Check className="size-3.5 text-teal-600" />}
                                                </DropdownMenuItem>
                                            )}
                                            
                                            {companyUsers
                                                .filter(u => u.name.toLowerCase().includes(agentFilterQuery.toLowerCase()))
                                                .map(u => (
                                                    <DropdownMenuItem 
                                                        key={u.id}
                                                        onClick={() => { setSelectedAgentId(selectedAgentId === String(u.id) ? '' : String(u.id)); setFilterMyAssignments(false); }}
                                                        className="flex items-center gap-3 py-2 px-3 cursor-pointer group"
                                                    >
                                                        <div className={clsx(
                                                            "size-7 rounded-lg flex items-center justify-center transition-all",
                                                            selectedAgentId === String(u.id) ? "bg-teal-600 text-white" : "bg-slate-100 dark:bg-slate-800 text-slate-400 group-hover:bg-teal-50 group-hover:text-teal-600"
                                                        )}>
                                                            <User className="size-3.5" />
                                                        </div>
                                                        <span className="text-xs font-bold flex-1">{u.name}</span>
                                                        {selectedAgentId === String(u.id) && <Check className="size-3.5 text-teal-600" />}
                                                    </DropdownMenuItem>
                                                ))
                                            }
                                            {companyUsers.filter(u => u.name.toLowerCase().includes(agentFilterQuery.toLowerCase())).length === 0 && (
                                                <div className="py-4 text-center">
                                                    <p className="text-[10px] font-bold text-muted-foreground">No se encontraron agentes</p>
                                                </div>
                                            )}
                                        </div>
                                    </>
                                )}

                                <DropdownMenuSeparator className="bg-border/5" />
                                <DropdownMenuItem 
                                    onClick={() => setIsCreatingTag(true)}
                                    className="flex items-center gap-3 py-3 px-3 cursor-pointer group text-teal-600"
                                >
                                    <div className="size-8 rounded-lg bg-teal-50 dark:bg-teal-900/20 text-teal-600 flex items-center justify-center group-hover:bg-teal-600 group-hover:text-white transition-all shadow-sm">
                                        <PlusCircle className="size-4" />
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-xs font-bold leading-none mb-1">Nueva Etiqueta</span>
                                        <span className="text-[9px] font-medium opacity-60 leading-none">Crear segmentación</span>
                                    </div>
                                </DropdownMenuItem>

                                {tags.length > 0 && (
                                    <>
                                        <DropdownMenuSeparator className="bg-border/5" />
                                        <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Filtrar por Etiqueta</DropdownMenuLabel>
                                        <div className="max-h-48 overflow-y-auto px-1">
                                            {tags.map(tag => (
                                                <DropdownMenuItem 
                                                    key={tag.id}
                                                    onClick={() => setSelectedTagId(selectedTagId === String(tag.id) ? '' : String(tag.id))}
                                                    className="flex items-center gap-3 py-2.5 px-3 cursor-pointer group"
                                                >
                                                    <div className="size-3 rounded-full shadow-sm" style={{ backgroundColor: tag.color }} />
                                                    <span className="text-xs font-bold flex-1">{tag.name}</span>
                                                    {selectedTagId === String(tag.id) && <Check className="size-3.5 text-teal-600" />}
                                                </DropdownMenuItem>
                                            ))}
                                        </div>
                                    </>
                                )}
                                
                                <DropdownMenuSeparator className="bg-border/5" />
                                <DropdownMenuItem
                                    className="flex items-center gap-3 py-3 px-3 cursor-pointer group"
                                    onClick={() => { resetFilters(); loadConversations(); }}
                                >
                                    <div className="size-8 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center group-hover:bg-slate-200 dark:group-hover:bg-slate-700 transition-colors shadow-sm">
                                        <Filter className="size-4" />
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="text-xs font-bold leading-none mb-1">Limpiar Filtros</span>
                                        <span className="text-[9px] font-medium text-muted-foreground leading-none">Restablecer vista</span>
                                    </div>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
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
                                        className="w-full pl-10 pr-10 py-1.5 bg-[#f0f2f5] dark:bg-[#202c33] border-none rounded-lg text-sm transition-all outline-none placeholder:text-muted-foreground/60 text-foreground"
                                    />
                                    {searchQuery && (
                                        <button
                                            onClick={() => setSearchQuery('')}
                                            className="absolute inset-y-0 right-0 pr-3 flex items-center"
                                        >
                                            <XIcon className="size-4 text-muted-foreground/60 hover:text-foreground transition-colors" />
                                        </button>
                                    )}
                                </div>

                                {hasActiveFilters && (
                                    <button
                                        type="button"
                                        onClick={resetFilters}
                                        className="mt-2 w-full flex items-center justify-between gap-2 px-3 py-1.5 rounded-lg bg-teal-50 dark:bg-teal-950/30 border border-teal-100 dark:border-teal-900/40 text-teal-700 dark:text-teal-400 hover:bg-teal-100 dark:hover:bg-teal-900/40 transition-colors text-xs font-semibold"
                                        title="Quitar todos los filtros y volver a la vista base"
                                    >
                                        <span className="flex items-center gap-1.5">
                                            <Filter className="size-3.5" />
                                            {activeFilterCount === 1 ? '1 filtro activo' : `${activeFilterCount} filtros activos`}
                                        </span>
                                        <span className="flex items-center gap-1 text-[10px] uppercase tracking-wider opacity-80">
                                            Limpiar <XIcon className="size-3" />
                                        </span>
                                    </button>
                                )}
                            </div>

                            <div ref={sidebarScrollRef} className="flex-1 overflow-y-auto custom-scrollbar">
                                {filteredConversations.map(conv => (
                                    <div key={conv.id} style={{ contentVisibility: 'auto', containIntrinsicSize: '0 72px' }}>
                                        <ConversationItem 
                                            conv={conv}
                                            isActive={selectedConversation?.id === conv.id}
                                            onSelect={selectConversation}
                                            onAttachTag={attachTag}
                                            onDetachTag={detachTag}
                                            onNewTag={handleNewTag}
                                            onAssign={assignConversation}
                                            tags={tags}
                                            companyUsers={companyUsers}
                                            isAdmin={isAdmin}
                                            formatTime={formatTime}
                                            StatusIcons={StatusIcons}
                                        />
                                    </div>
                                ))}

                                {/* Sentinel for Infinite Scroll */}
                                <div ref={observerTarget} className="h-10 w-full flex items-center justify-center">
                                    {loadingMore && (
                                        <div className="flex items-center gap-2 text-[10px] font-black text-teal-600/40 uppercase tracking-widest">
                                            <div className="size-3 border-2 border-teal-600/20 border-t-teal-600 rounded-full animate-spin" />
                                            Cargando más...
                                        </div>
                                    )}
                                </div>

                                {filteredConversations.length === 0 && !loadingMore && (
                                    <div className="p-8 text-center">
                                        <p className="text-xs text-muted-foreground font-medium italic opacity-60">No se encontraron chats</p>
                                    </div>
                                )}
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
                                                <div className="flex items-center gap-2">
                                                    <p className="text-[10px] text-muted-foreground leading-tight">{selectedConversation.phone_number}</p>
                                                    {selectedConversation.assigned_agent && (
                                                        <span className="text-[9px] bg-teal-600/10 text-teal-600 px-1.5 py-0.5 rounded font-black uppercase tracking-tighter">
                                                            @{selectedConversation.assigned_agent.name}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {/* Admin Assignment Button */}
                                            {isAdmin && (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <button 
                                                            className={clsx(
                                                                "flex items-center gap-2 px-3 py-1.5 rounded-lg border border-border/10 text-[11px] font-black uppercase transition-all",
                                                                selectedConversation.assigned_to ? "bg-teal-600 text-white border-teal-600" : "bg-white dark:bg-black/20 text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5"
                                                            )}
                                                            title="Asignar agente"
                                                        >
                                                            <UserPlus className="size-3.5" />
                                                            <span className="hidden sm:inline">
                                                                {selectedConversation.assigned_agent?.name || 'Asignar'}
                                                            </span>
                                                        </button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-64 rounded-xl border-border/10 shadow-2xl">
                                                        <DropdownMenuLabel className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/50 px-3 py-2">Asignar Agente</DropdownMenuLabel>
                                                        <DropdownMenuSeparator className="bg-border/5" />
                                                        
                                                        {/* Unassign option */}
                                                        <DropdownMenuItem 
                                                            onClick={() => assignConversation(selectedConversation.id, null)}
                                                            className="flex items-center gap-3 py-2.5 px-3 cursor-pointer group"
                                                        >
                                                            <div className="size-8 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center group-hover:bg-red-50 group-hover:text-red-600 transition-colors shadow-sm">
                                                                <XIcon className="size-4" />
                                                            </div>
                                                            <div className="flex flex-col">
                                                                <span className="text-xs font-bold leading-none mb-1">Sin Asignar</span>
                                                                <span className="text-[9px] font-medium text-muted-foreground leading-none">Remover responsable</span>
                                                            </div>
                                                            {!selectedConversation.assigned_to && <Check className="size-4 text-teal-600 ml-auto" />}
                                                        </DropdownMenuItem>
                                                        
                                                        <DropdownMenuSeparator className="bg-border/5" />
                                                        <div className="max-h-60 overflow-y-auto px-1 py-1">
                                                            {companyUsers.map(u => (
                                                                <DropdownMenuItem 
                                                                    key={u.id}
                                                                    onClick={() => assignConversation(selectedConversation.id, u.id)}
                                                                    className="flex items-center gap-3 py-2.5 px-3 cursor-pointer group"
                                                                >
                                                                    <div className={clsx(
                                                                        "size-8 rounded-lg flex items-center justify-center transition-all shadow-sm",
                                                                        Number(selectedConversation.assigned_to) === Number(u.id) ? "bg-teal-600 text-white" : "bg-slate-100 dark:bg-slate-800 text-slate-500 group-hover:bg-teal-50 group-hover:text-teal-600"
                                                                    )}>
                                                                        <User className="size-4" />
                                                                    </div>
                                                                    <div className="flex flex-col">
                                                                        <span className="text-xs font-bold leading-none mb-1">{u.name}</span>
                                                                        <span className="text-[9px] font-medium text-muted-foreground leading-none">{u.email}</span>
                                                                    </div>
                                                                    {Number(selectedConversation.assigned_to) === Number(u.id) && <Check className="size-4 text-teal-600 ml-auto" />}
                                                                </DropdownMenuItem>
                                                            ))}
                                                        </div>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            )}

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
                                                    
                                                    {msg.is_internal ? (
                                                        <div className="flex justify-center my-2 px-2">
                                                            <div className="w-full max-w-[90%] lg:max-w-[75%] bg-amber-50 dark:bg-amber-900/20 border border-amber-300/60 dark:border-amber-700/40 rounded-lg px-3 py-2 shadow-sm">
                                                                <div className="flex items-center gap-1.5 mb-1">
                                                                    <StickyNote className="size-3.5 text-amber-600 dark:text-amber-400" />
                                                                    <span className="text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                                                        Nota interna · {msg.sender?.name || 'Agente'}
                                                                    </span>
                                                                    <span className="ml-auto text-[9px] font-semibold text-amber-700/60 dark:text-amber-300/50 uppercase">{formatMessageTimeOnly(msg.created_at)}</span>
                                                                </div>
                                                                <p className="text-[13px] leading-[18px] whitespace-pre-wrap break-words text-amber-950 dark:text-amber-100">{msg.content}</p>
                                                            </div>
                                                        </div>
                                                    ) : (
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
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </div>

                                    {/* Composer mode strip: Responder / Nota interna */}
                                    {!isRecording && (
                                        <div className="bg-[#f0f2f5] dark:bg-[#202c33] px-3 pt-1.5 flex items-center gap-1 z-10">
                                            <button
                                                onClick={() => { setComposerMode('reply'); closeMentions(); }}
                                                className={clsx(
                                                    "px-3 py-1 rounded-t-md text-[11px] font-bold uppercase tracking-wide transition-colors flex items-center gap-1.5",
                                                    composerMode === 'reply'
                                                        ? "bg-white dark:bg-[#2a3942] text-teal-600 dark:text-teal-400"
                                                        : "text-muted-foreground hover:text-foreground"
                                                )}
                                            >
                                                <MessageSquare className="size-3.5" /> Responder
                                            </button>
                                            <button
                                                onClick={() => { setComposerMode('note'); closeQuickReplies(); }}
                                                className={clsx(
                                                    "px-3 py-1 rounded-t-md text-[11px] font-bold uppercase tracking-wide transition-colors flex items-center gap-1.5",
                                                    composerMode === 'note'
                                                        ? "bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300"
                                                        : "text-muted-foreground hover:text-foreground"
                                                )}
                                            >
                                                <StickyNote className="size-3.5" /> Nota interna
                                            </button>
                                        </div>
                                    )}

                                    {/* Input Area - WhatsApp Web Style */}
                                    <div className={clsx(
                                        "px-3 py-2 flex items-end gap-2 z-10 text-foreground min-h-[62px] transition-colors",
                                        composerMode === 'note' && !isRecording
                                            ? "bg-amber-50 dark:bg-amber-900/20"
                                            : "bg-[#f0f2f5] dark:bg-[#202c33]"
                                    )}>
                                        {!isRecording ? (
                                            <>
                                                <div className="flex items-center pb-0.5">
                                                    {composerMode === 'reply' ? (
                                                        <>
                                                            <button className="p-2 text-muted-foreground hover:text-foreground transition-colors"><Smile className="size-6" /></button>
                                                            <label className="p-2 text-muted-foreground hover:text-foreground cursor-pointer transition-colors">
                                                                <Paperclip className="size-6" />
                                                                <input type="file" onChange={handleFileUpload} accept="image/*" className="hidden" />
                                                            </label>
                                                            <button
                                                                onClick={startRecording}
                                                                className="p-2 text-muted-foreground hover:text-teal-600 transition-colors"
                                                                title="Grabar audio"
                                                            >
                                                                <Mic className="size-6" />
                                                            </button>
                                                        </>
                                                    ) : (
                                                        <button
                                                            disabled
                                                            title="Programar nota (próximamente)"
                                                            className="p-2 text-amber-400/70 cursor-not-allowed"
                                                        >
                                                            <Clock className="size-6" />
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="flex-1 relative">
                                                    {composerMode === 'reply' && qrOpen && (
                                                        <QuickReplyPicker
                                                            matches={qrMatches}
                                                            activeIndex={qrIndex}
                                                            onSelect={applyQuickReply}
                                                            query={qrQuery}
                                                        />
                                                    )}
                                                    {composerMode === 'note' && mentionOpen && mentionMatches.length > 0 && (
                                                        <div className="absolute bottom-full mb-2 left-0 w-72 max-h-56 overflow-y-auto bg-white dark:bg-[#2a3942] border border-border rounded-lg shadow-lg z-50">
                                                            {mentionMatches.map((u, idx) => (
                                                                <button
                                                                    key={u.id}
                                                                    onMouseDown={(e) => { e.preventDefault(); applyMention(u); }}
                                                                    className={clsx(
                                                                        "w-full flex items-center gap-2 px-3 py-2 text-left transition-colors",
                                                                        idx === mentionIndex ? "bg-amber-50 dark:bg-amber-900/20" : "hover:bg-muted"
                                                                    )}
                                                                >
                                                                    <AtSign className="size-3.5 text-amber-600" />
                                                                    <span className="text-sm font-semibold">{u.name}</span>
                                                                    <span className="text-[10px] text-muted-foreground ml-auto truncate">{u.email}</span>
                                                                </button>
                                                            ))}
                                                        </div>
                                                    )}
                                                    <textarea
                                                        ref={messageInputRef}
                                                        rows={1}
                                                        placeholder={composerMode === 'note'
                                                            ? "Nota privada para el equipo (escribe @ para mencionar a un agente) — el cliente no la verá"
                                                            : "Escribe un mensaje aquí (escribe / para respuestas rápidas — Shift+Enter para nueva línea)"}
                                                        value={newMessage}
                                                        onChange={handleComposerChange}
                                                        onKeyDown={handleComposerKeyDown}
                                                        disabled={sending}
                                                        className={clsx(
                                                            "block w-full border-none rounded-lg px-4 py-2 text-[14.5px] leading-snug outline-none placeholder:text-muted-foreground/60 text-foreground resize-none overflow-y-auto whitespace-pre-wrap break-words",
                                                            composerMode === 'note'
                                                                ? "bg-white dark:bg-[#2a3942] ring-1 ring-amber-300/70 dark:ring-amber-700/50"
                                                                : "bg-white dark:bg-[#2a3942]"
                                                        )}
                                                        style={{ maxHeight: '160px' }}
                                                    />
                                                </div>

                                                <button
                                                    onClick={composerMode === 'note' ? sendNote : sendMessage}
                                                    disabled={!newMessage.trim() || sending}
                                                    className={clsx(
                                                        "p-2 transition-colors",
                                                        composerMode === 'note'
                                                            ? "text-amber-600 hover:text-amber-700"
                                                            : "text-[#54656f] dark:text-[#8696a0] hover:text-teal-600"
                                                    )}
                                                >
                                                    <Send className={`size-6 ${sending ? 'animate-pulse' : ''}`} />
                                                </button>
                                            </>
                                        ) : (
                                            <div className="flex-1 flex items-center justify-between bg-white dark:bg-[#2a3942] rounded-lg px-4 py-2 animate-in fade-in slide-in-from-bottom-2 duration-200">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex items-center gap-2">
                                                        <div className="size-2.5 bg-red-500 rounded-full animate-pulse" />
                                                        <span className="text-sm font-bold tabular-nums">{formatDuration(recordingDuration)}</span>
                                                    </div>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    <button 
                                                        onClick={cancelRecording}
                                                        className="p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition-colors"
                                                        title="Cancelar grabación"
                                                    >
                                                        <Trash2 className="size-5" />
                                                    </button>
                                                    <button 
                                                        onClick={stopRecording}
                                                        className="p-2 bg-teal-600 text-white rounded-full hover:bg-teal-700 transition-colors shadow-sm"
                                                        title="Enviar grabación"
                                                    >
                                                        <Send className="size-5" />
                                                    </button>
                                                </div>
                                            </div>
                                        )}
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

                {/* Create Tag Modal */}
                {isCreatingTag && (
                    <div className="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-[#1c272e] rounded-3xl shadow-2xl w-full max-w-sm mx-4 p-6 border border-border/10">
                            <div className="flex items-center justify-between mb-6">
                                <h3 className="text-lg font-black text-foreground">Nueva Etiqueta</h3>
                                <button onClick={() => { setIsCreatingTag(false); setTaggingConversationId(null); }} className="p-2 hover:bg-black/5 dark:hover:bg-white/5 rounded-full">
                                    <XIcon className="size-4 text-muted-foreground" />
                                </button>
                            </div>
                            
                            <div className="space-y-4">
                                <div>
                                    <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-2 block">Nombre</label>
                                    <input 
                                        type="text" 
                                        autoFocus
                                        value={newTagName}
                                        onChange={e => setNewTagName(e.target.value)}
                                        placeholder="Ej: Cliente VIP, Cobro..."
                                        className="w-full bg-[#f0f2f5] dark:bg-[#2a3942] border-none rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-teal-600/20 outline-none"
                                    />
                                </div>
                                
                                <div>
                                    <label className="text-[10px] font-black uppercase tracking-widest text-muted-foreground mb-2 block">Color Distintivo</label>
                                    <div className="flex flex-wrap gap-2">
                                        {['#0d9488', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#d97706', '#059669', '#4b5563'].map(color => (
                                            <button 
                                                key={color}
                                                onClick={() => setNewTagColor(color)}
                                                className={clsx(
                                                    "size-8 rounded-full border-2 transition-all shadow-sm",
                                                    newTagColor === color ? "border-white dark:border-[#1c272e] ring-2 ring-teal-600 scale-110" : "border-transparent opacity-80 hover:opacity-100"
                                                )}
                                                style={{ backgroundColor: color }}
                                            />
                                        ))}
                                    </div>
                                </div>
                                
                                <div className="pt-4 flex gap-3">
                                    <button 
                                        onClick={() => { setIsCreatingTag(false); setTaggingConversationId(null); }}
                                        className="flex-1 py-3 text-sm font-bold text-muted-foreground hover:bg-black/5 dark:hover:bg-white/5 rounded-2xl transition-colors"
                                    >
                                        Cancelar
                                    </button>
                                    <button 
                                        onClick={createTag}
                                        disabled={!newTagName.trim()}
                                        className="flex-1 py-3 bg-teal-600 text-white text-sm font-black rounded-2xl shadow-lg shadow-teal-600/20 disabled:opacity-50 hover:scale-[1.02] active:scale-95 transition-all"
                                    >
                                        Crear Etiqueta
                                    </button>
                                </div>
                            </div>
                        </div>
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
