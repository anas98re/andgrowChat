<template>
    <div :class="[
        'fixed bottom-20 right-4 bg-white rounded-2xl shadow-2xl flex flex-col z-50 font-sans transition-all duration-300 ease-in-out',
        isMaximized ? 'w-[calc(100vw-2rem)] h-[calc(100vh-2rem)] top-4 left-4 bottom-auto right-auto' : 'w-[440px] h-[75vh]'
    ]">
        <!-- Chat Header -->
        <div class="bg-white border-b border-gray-200 p-4 rounded-t-2xl flex justify-between items-center flex-shrink-0">
            <div class="flex items-center space-x-3">
                <div class="w-7 h-7 rounded-md bg-gradient-to-r from-cyan-300 to-pink-300 flex items-center justify-center">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104l-2.28 2.28-1.297 1.297A3.75 3.75 0 003.75 9.75v.522a3.75 3.75 0 001.416 2.896l1.297 1.297 2.28 2.28a3.75 3.75 0 005.304 0l2.28-2.28 1.297-1.297A3.75 3.75 0 0017.25 10.272v-.522a3.75 3.75 0 00-1.416-2.896l-1.297-1.297-2.28-2.28a3.75 3.75 0 00-5.304 0zM9.75 3.104a3.75 3.75 0 015.304 0l2.28 2.28 1.297 1.297A3.75 3.75 0 0119.5 9.75v.522a3.75 3.75 0 01-1.416 2.896l-1.297 1.297-2.28 2.28a3.75 3.75 0 01-5.304 0l-2.28-2.28-1.297-1.297A3.75 3.75 0 014.5 10.272v-.522a3.75 3.75 0 011.416-2.896l1.297-1.297 2.28-2.28z" /></svg>
                </div>
                <h3 class="text-md font-semibold text-gray-500">Andgrow Help Assistant</h3>
            </div>
            <div class="flex items-center space-x-2">
                <button @click="toggleMaximize" class="text-gray-400 hover:text-gray-600 p-1" :title="isMaximized ? 'Restore' : 'Maximize'">
                    <svg v-if="!isMaximized" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>
                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25" /></svg>
                </button>
                <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 p-1" title="Close">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>

        <!-- Messages Area -->
        <div ref="messagesContainer" class="flex-1 p-5 overflow-y-auto space-y-6">
            <div v-for="msg in messages" :key="msg.id" 
                 :class="['flex w-full', msg.sender === 'visitor' ? 'justify-end' : 'justify-start']">

                <div class="flex items-start gap-3" :class="msg.sender === 'visitor' ? 'flex-row-reverse' : 'flex-row'">
                    <!-- Avatar for Agent -->
                    <div v-if="msg.sender === 'agent'" class="flex-shrink-0 w-7 h-7 rounded-full bg-gradient-to-r from-cyan-200 to-pink-200 flex items-center justify-center text-black  text-xs mt-1">
                        AI
                    </div>
                    
                    <!-- Message Content Wrapper -->
                    <div class="flex flex-col" :class="[msg.sender === 'visitor' ? 'items-end' : 'items-start']">
                        <!-- Message Bubble -->
                        <div :class="[
                            'max-w-md px-4 py-3 rounded-2xl',
                            msg.sender === 'visitor'
                                ? 'bg-gradient-to-r from-cyan-200 to-pink-200 text-black '
                                : 'bg-white text-gray-800 rounded-bl-none'
                        ]">
                            <div class="prose prose-sm max-w-none text-sm break-words prose-p:my-2" v-html="msg.body"></div>
                        </div>

                        <!-- Action Icons for Agent -->
                        <div v-if="msg.sender === 'agent' && msg.body && !msg.id.toString().startsWith('temp-')" class="mt-2 flex items-center space-x-3 text-gray-400">
                            <button @click="copyToClipboard(msg.id, msg.body)" class="hover:text-gray-600 transition-colors duration-200" title="Copy">
                                <span v-if="copiedMessageId === msg.id" class="text-xs text-green-500">Copied!</span>
                                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <TypingIndicator v-if="isTyping" />
        </div>

        <!-- Message Input -->
        <div class="p-4 border-t border-gray-200 bg-white rounded-b-2xl">
            <MessageInput @send-message="handleSendMessage" :disabled="isStreaming" />
        </div>
    </div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import MessageInput from './MessageInput.vue';
import TypingIndicator from './TypingIndicator.vue';
import { v4 as uuidv4 } from 'uuid';

const emit = defineEmits(['close']);

const messages = ref([]);
const conversationId = ref(null);
const sessionId = ref(localStorage.getItem('chat_session_id') || uuidv4());
const isTyping = ref(false); 
const isStreaming = ref(false);
const messagesContainer = ref(null);
const channelName = `chat-session.${sessionId.value}`;
const isMaximized = ref(false);
const copiedMessageId = ref(null);
let statusInterval = null;

localStorage.setItem('chat_session_id', sessionId.value);

const toggleMaximize = () => { isMaximized.value = !isMaximized.value; };
const scrollToBottom = () => { nextTick(() => { if (messagesContainer.value) { messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight; } }); };

const copyToClipboard = (messageId, htmlContent) => {
    // ... function remains the same ...
};

const handleSendMessage = async (messageText) => {
    if (!messageText.trim() || isStreaming.value) return;

    clearInterval(statusInterval);
    isStreaming.value = true;
    const visitorMessage = {
        id: 'temp-visitor-' + Date.now(),
        sender: 'visitor',
        body: `<p>${messageText.replace(/\n/g, '<br>')}</p>`,
        created_at: new Date().toISOString()
    };
    messages.value.push(visitorMessage);
    isTyping.value = true; // Show "thinking" dots immediately for ALL messages
    scrollToBottom();
    
    const statusMessages = [
        "I'm searching the knowledge base right now...",
        "I analyze the information to provide you with the best answer...",
        "I check for the availability of the required data and collect it if found...",
        "شكراً على انتظارك، أوشكت على الانتهاء..."
    ];
    let agentMessageId = 'temp-agent-' + Date.now();
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000); 

    try {
        const response = await fetch('/api/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                message: messageText,
                conversation_id: conversationId.value,
                session_id: sessionId.value,
            }),
            signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (!response.ok || !response.body) {
            throw new Error(`Server error: ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let firstChunkReceived = false;

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value, { stream: true });
            const eventStrings = chunk.split('\n\n').filter(s => s);

            for (const eventString of eventStrings) {
                
                // --- NEW: Wait for the 'start-processing' signal from the backend ---
                if (eventString.trim().startsWith('event: start-processing')) {
                    isTyping.value = false;
                    
                    let statusIndex = 0;
                    messages.value.push({
                        id: agentMessageId,
                        sender: 'agent',
                        body: `<p class="text-gray-500 italic">${statusMessages[statusIndex]}</p>`,
                        created_at: new Date().toISOString()
                    });
                    scrollToBottom();
                    
                    statusInterval = setInterval(() => {
                        statusIndex = (statusIndex + 1) % statusMessages.length;
                        const msgIndex = messages.value.findIndex(m => m.id === agentMessageId);
                        if (msgIndex !== -1) {
                            messages.value[msgIndex].body = `<p class="text-gray-500 italic">${statusMessages[statusIndex]}</p>`;
                        } else {
                            clearInterval(statusInterval);
                        }
                    }, 3000);
                } 
                else if (eventString.trim().startsWith('data:')) {
                    clearInterval(statusInterval);
                    const dataStr = eventString.trim().substring(5);
                    try {
                        const data = JSON.parse(dataStr);
                        if (data.text) {
                            isTyping.value = false;
                            
                            let msgIndex = messages.value.findIndex(m => m.id === agentMessageId);
                            
                            if (msgIndex === -1) {
                                messages.value.push({ id: agentMessageId, sender: 'agent', body: '', created_at: new Date().toISOString() });
                                msgIndex = messages.value.length - 1;
                            }
                            
                            const cleanedText = data.text.replace(/【.*?】/gu, '');

                            if (cleanedText) {
                                if (!firstChunkReceived) {
                                    messages.value[msgIndex].body = '';
                                    firstChunkReceived = true;
                                }
                                messages.value[msgIndex].body += cleanedText.replace(/\n/g, '<br>');
                                scrollToBottom();
                            }
                        }
                    } catch(e) {
                        console.warn('Could not parse JSON chunk', e);
                    }
                }
            }
        }
    } catch (error) {
        clearInterval(statusInterval);
        console.error('Error streaming response:', error);
        isTyping.value = false;
        
        let msgIndex = messages.value.findIndex(m => m.id === agentMessageId);
        if (msgIndex !== -1) {
            messages.value[msgIndex].body = "<p class='text-red-500'>An error occurred. Please try again.</p>";
        } else {
             messages.value.push({
                id: 'error-' + Date.now(),
                sender: 'agent',
                body: "<p class='text-red-500'>An error occurred. Please try again.</p>",
                created_at: new Date().toISOString()
            });
        }
    } finally {
        clearInterval(statusInterval);
        isTyping.value = false;
        isStreaming.value = false;
    }
};

watch(messages, scrollToBottom, { deep: true });

onMounted(async () => {
    if (window.Echo) {
        window.Echo.channel(channelName)
            .listen('.message.sent', (event) => {
                if (event.sender === 'agent') {
                    clearInterval(statusInterval);
                    const tempMsgIndex = messages.value.findIndex(m => m.sender === 'agent' && m.id.toString().startsWith('temp-'));
                    if (tempMsgIndex !== -1) {
                        messages.value[tempMsgIndex].id = event.id;
                        messages.value[tempMsgIndex].body = event.body;
                    } else if (!messages.value.some(m => m.id === event.id)) {
                        messages.value.push(event);
                    }
                }
                scrollToBottom();
            });
    }
    try {
        const response = await window.axios.get(`/api/conversation/by-session/${sessionId.value}`);
        if (response.data && response.data.conversation_id) {
            conversationId.value = response.data.conversation_id;
            messages.value = response.data.messages;
        }
    } catch (error) { console.error('Error loading conversation:', error); }
});

onUnmounted(() => { 
    clearInterval(statusInterval);
    if (window.Echo) { window.Echo.leave(channelName); } 
});
</script>