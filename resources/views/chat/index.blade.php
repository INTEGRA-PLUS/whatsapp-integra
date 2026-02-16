@extends('layouts.app')

@section('title', 'Chat WhatsApp')

@push('styles')
<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
.scrollbar-thin::-webkit-scrollbar {
    width: 6px;
}
.scrollbar-thin::-webkit-scrollbar-track {
    background: #f1f1f1;
}
.scrollbar-thin::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}
</style>
@endpush

@section('content')
<div id="whatsapp-chat-app" class="h-screen flex flex-col">
    <!-- Header -->
    <div class="bg-green-600 text-white px-6 py-3 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            <div>
                <h1 class="text-xl font-bold">Chat WhatsApp</h1>
                <p class="text-xs text-green-100">Meta Business API</p>
            </div>
        </div>
        
        <div class="flex items-center gap-4">
            <div>
                <select v-model="selectedInstanceId" @change="changeInstance" class="text-gray-900 px-3 py-1 rounded text-sm">
                    <option value="">Seleccionar instancia...</option>
                    @foreach($instances as $inst)
                    <option value="{{ $inst->id }}">{{ $inst->name ?? $inst->uuid }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-xs">@{{ lastUpdate }}</span>
                <div :class="['w-2 h-2 rounded-full', isPolling ? 'bg-yellow-300 animate-pulse' : 'bg-gray-300']"></div>
            </div>
        </div>
    </div>

    <div v-if="!selectedInstanceId" class="flex-1 flex items-center justify-center">
        <div class="text-center text-gray-500">
            <svg class="mx-auto h-20 w-20 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
            <p class="text-lg font-medium">Selecciona una instancia para comenzar</p>
        </div>
    </div>

    <div v-else class="flex-1 flex overflow-hidden">
        <!-- Lista de conversaciones -->
        <div class="w-1/3 bg-white border-r flex flex-col">
            <div class="px-4 py-3 bg-gray-50 border-b">
                <input 
                    v-model="searchQuery" 
                    type="text" 
                    placeholder="Buscar..." 
                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                >
            </div>

            <div class="flex-1 overflow-y-auto scrollbar-thin">
                <div 
                    v-for="conv in filteredConversations" 
                    :key="conv.id"
                    @click="selectConversation(conv)"
                    :class="['p-4 border-b cursor-pointer hover:bg-gray-50 transition', {
                        'bg-green-50 border-l-4 border-green-500': selectedConversation && selectedConversation.id === conv.id
                    }]"
                >
                    <div class="flex items-start">
                        <div class="flex-shrink-0 h-12 w-12 rounded-full bg-green-500 flex items-center justify-center text-white font-bold text-sm">
                            @{{ conv.initials }}
                        </div>
                        <div class="ml-3 flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1">
                                <p class="text-sm font-semibold text-gray-900 truncate">@{{ conv.name }}</p>
                                <span class="text-xs text-gray-500">@{{ formatTime(conv.last_message_at) }}</span>
                            </div>
                            <p class="text-sm text-gray-600 truncate">@{{ conv.last_message }}</p>
                        </div>
                        <div v-if="conv.unread_count > 0" class="ml-2 bg-green-500 text-white rounded-full h-6 w-6 flex items-center justify-center text-xs font-bold">
                            @{{ conv.unread_count }}
                        </div>
                    </div>
                </div>

                <div v-if="conversations.length === 0" class="p-8 text-center text-gray-500">
                    <p>No hay conversaciones</p>
                </div>
            </div>
        </div>

        <!-- Área de chat -->
        <div class="flex-1 flex flex-col">
            <div v-if="!selectedConversation" class="flex-1 flex items-center justify-center bg-gray-100">
                <div class="text-center text-gray-500">
                    <p class="text-lg">Selecciona una conversación</p>
                </div>
            </div>

            <template v-else>
                <div class="bg-white px-6 py-4 border-b">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="h-10 w-10 rounded-full bg-green-500 flex items-center justify-center text-white font-bold">
                                @{{ selectedConversation.initials }}
                            </div>
                            <div class="ml-3">
                                <h3 class="text-lg font-semibold">@{{ selectedConversation.name }}</h3>
                                <p class="text-sm text-gray-600">@{{ selectedConversation.phone_number }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div ref="messagesContainer" class="flex-1 overflow-y-auto p-6 space-y-4 bg-gray-100 scrollbar-thin">
                    <div 
                        v-for="msg in messages" 
                        :key="msg.id"
                        :class="['flex', msg.direction === 'outbound' ? 'justify-end' : 'justify-start']"
                    >
                        <div :class="['max-w-md rounded-lg px-4 py-2 shadow-md', 
                            msg.direction === 'outbound' ? 'bg-green-500 text-white' : 'bg-white text-gray-900'
                        ]">
                            <p v-if="msg.type === 'text'" class="break-words">@{{ msg.content }}</p>
                            
                            <div v-else-if="msg.type === 'image'" class="relative">
                                <img 
                                    :src="msg.media_url" 
                                    class="rounded-lg mb-2 max-h-48 object-cover cursor-pointer hover:opacity-90 transition shadow-sm"
                                    @click="openImageModal(msg.media_url)"
                                >
                                <p v-if="msg.content" class="text-sm">@{{ msg.content }}</p>
                            </div>

                            <div class="text-xs mt-1 flex items-center justify-end gap-1">
                                <span>@{{ formatTime(msg.created_at) }}</span>
                                <span v-if="msg.direction === 'outbound'">
                                    <span v-if="msg.status === 'sent'">✓</span>
                                    <span v-else-if="msg.status === 'delivered'">✓✓</span>
                                    <span v-else-if="msg.status === 'read'" class="text-blue-300">✓✓</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white px-6 py-4 border-t">
                    <div class="flex items-center gap-3">
                        <label class="cursor-pointer text-gray-600 hover:text-green-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <input type="file" @change="handleFileUpload" accept="image/*" class="hidden">
                        </label>

                        <input 
                            v-model="newMessage" 
                            @keyup.enter="sendMessage"
                            type="text" 
                            placeholder="Escribe un mensaje..." 
                            class="flex-1 px-4 py-2 border rounded-full focus:outline-none focus:ring-2 focus:ring-green-500"
                            :disabled="sending"
                        >

                        <button 
                            @click="sendMessage"
                            :disabled="!newMessage.trim() || sending"
                            class="px-6 py-2 bg-green-500 text-white rounded-full hover:bg-green-600 disabled:opacity-50"
                        >
                            Enviar
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
    <!-- Image Modal -->
    <div v-if="selectedImage" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90 p-4" @click="closeImageModal">
        <div class="relative max-w-4xl w-full max-h-screen flex flex-col items-center justify-center">
            <button class="absolute top-4 right-4 text-white hover:text-gray-300 z-50" @click="closeImageModal">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            <img :src="selectedImage" class="max-w-full max-h-[90vh] object-contain rounded-lg shadow-2xl" @click.stop>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script>
axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').content;

const { createApp } = Vue;

createApp({
    data() {
        return {
            selectedInstanceId: '',
            conversations: [],
            messages: [],
            selectedConversation: null,
            newMessage: '',
            searchQuery: '',
            sending: false,
            pollingInterval: null,
            pollingFrequency: 10000,
            lastUpdateTimestamp: null,
            lastUpdate: 'Nunca',
            isPolling: false,
            selectedImage: null // For modal
        }
    },
    
    computed: {
        filteredConversations() {
            if (!this.searchQuery) return this.conversations;
            const query = this.searchQuery.toLowerCase();
            return this.conversations.filter(c => 
                c.name.toLowerCase().includes(query) ||
                c.phone_number.includes(query)
            );
        }
    },
    
    mounted() {
        // Fix: PHP variable injection in JS
        const instances = @json($instances);
        const firstInstance = instances.length > 0 ? instances[0] : null;
        if (firstInstance) {
            this.selectedInstanceId = firstInstance.id;
            this.loadConversations();
            this.startPolling();
        }
    },
    
    methods: {
        openImageModal(url) {
            this.selectedImage = url;
            document.body.style.overflow = 'hidden';
        },
        
        closeImageModal() {
            this.selectedImage = null;
            document.body.style.overflow = '';
        },
        
        changeInstance() {
            this.stopPolling();
            this.conversations = [];
            this.messages = [];
            this.selectedConversation = null;
            if (this.selectedInstanceId) {
                this.loadConversations();
                this.startPolling();
            }
        },
        
        async loadConversations() {
            try {
                const response = await axios.get('/api/chat/conversations', {
                    params: { instance_id: this.selectedInstanceId }
                });
                this.conversations = response.data.data;
            } catch (error) {
                console.error('Error:', error);
            }
        },
        
        async selectConversation(conversation) {
            this.selectedConversation = conversation;
            try {
                const response = await axios.get(`/api/chat/conversations/${conversation.id}/messages`);
                this.messages = response.data.messages;
                this.lastUpdateTimestamp = response.data.timestamp;
                conversation.unread_count = 0;
                this.$nextTick(() => this.scrollToBottom());
            } catch (error) {
                console.error('Error:', error);
            }
        },
        
        async sendMessage() {
            if (!this.newMessage.trim()) return;
            
            const message = this.newMessage;
            this.newMessage = '';
            this.sending = true;
            
            try {
                const response = await axios.post(`/api/chat/conversations/${this.selectedConversation.id}/send`, {
                    message: message
                });
                
                if (response.data.success) {
                    this.messages.push(response.data.data);
                    this.$nextTick(() => this.scrollToBottom());
                }
            } catch (error) {
                console.error('Error:', error);
                this.newMessage = message;
            } finally {
                this.sending = false;
            }
        },
        
        async handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('image', file);
            this.sending = true;
            
            try {
                const response = await axios.post(
                    `/api/chat/conversations/${this.selectedConversation.id}/send-image`,
                    formData
                );
                
                if (response.data.success) {
                    this.messages.push(response.data.data);
                    this.$nextTick(() => this.scrollToBottom());
                }
            } catch (error) {
                console.error('Error:', error);
            } finally {
                this.sending = false;
                event.target.value = '';
            }
        },
        
        startPolling() {
            this.pollingInterval = setInterval(() => {
                this.checkForUpdates();
            }, this.pollingFrequency);
        },
        
        stopPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        },
        
        async checkForUpdates() {
            if (!this.lastUpdateTimestamp) {
                this.lastUpdateTimestamp = new Date().toISOString();
                return;
            }
            
            try {
                this.isPolling = true;
                const params = {
                    instance_id: this.selectedInstanceId,
                    since: this.lastUpdateTimestamp
                };
                
                if (this.selectedConversation) {
                    params.conversation_id = this.selectedConversation.id;
                }
                
                const response = await axios.get('/api/chat/updates', { params });
                
                this.lastUpdateTimestamp = response.data.timestamp;
                this.lastUpdate = new Date().toLocaleTimeString('es-CO');
                
                if (response.data.conversations.length > 0) {
                    this.mergeConversations(response.data.conversations);
                }
                
                if (response.data.new_messages.length > 0) {
                    response.data.new_messages.forEach(msg => {
                        if (!this.messages.find(m => m.id === msg.id)) {
                            this.messages.push(msg);
                        }
                    });
                    this.$nextTick(() => this.scrollToBottom());
                }
                
                if (response.data.updated_statuses.length > 0) {
                    response.data.updated_statuses.forEach(statusUpdate => {
                        const message = this.messages.find(m => m.id === statusUpdate.id);
                        if (message) {
                            message.status = statusUpdate.status;
                        }
                    });
                }
            } catch (error) {
                console.error('Error polling:', error);
            } finally {
                this.isPolling = false;
            }
        },
        
        mergeConversations(updatedConversations) {
            updatedConversations.forEach(updated => {
                const index = this.conversations.findIndex(c => c.id === updated.id);
                if (index !== -1) {
                    this.conversations[index] = updated;
                } else {
                    this.conversations.unshift(updated);
                }
            });
            this.conversations.sort((a, b) => 
                new Date(b.last_message_at) - new Date(a.last_message_at)
            );
        },
        
        scrollToBottom() {
            const container = this.$refs.messagesContainer;
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        },
        
        formatTime(timestamp) {
            if (!timestamp) return '';
            const date = new Date(timestamp);
            const now = new Date();
            const diffInHours = (now - date) / (1000 * 60 * 60);
            
            if (diffInHours < 24) {
                return date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
            } else if (diffInHours < 48) {
                return 'Ayer';
            } else {
                return date.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit' });
            }
        }
    },
    
    beforeUnmount() {
        this.stopPolling();
    }
}).mount('#whatsapp-chat-app');
</script>
@endpush
