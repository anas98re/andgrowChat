<template>
    <div class="fixed bottom-20 right-4 w-96 h-5/6 bg-white rounded-lg shadow-xl flex flex-col z-50">
        <!-- Chat Header -->
        <div class="bg-blue-600 text-white p-4 rounded-t-lg flex justify-between items-center">
            <h3 class="text-lg font-semibold">Andgrow Help Assistant</h3>
            <button @click="$emit('close')" class="text-white hover:text-gray-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Messages Area -->
        <div ref="messagesContainer" class="flex-1 p-4 overflow-y-auto space-y-4">
            <div v-for="msg in messages" :key="msg.id" :class="['flex', msg.sender === 'visitor' ? 'justify-end' : 'justify-start']">
                <div :class="[
                    'max-w-[75%] p-3 rounded-lg shadow-md',
                    msg.sender === 'visitor' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-800'
                ]">
                    <p class="text-sm break-words">{{ msg.body }}</p>
                    <span class="block text-xs mt-1" :class="msg.sender === 'visitor' ? 'text-blue-200' : 'text-gray-500'">
                        {{ new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                    </span>
                </div>
            </div>
            <TypingIndicator v-if="isTyping" />
        </div>

        <!-- Message Input -->
        <div class="p-4 border-t border-gray-200">
            <MessageInput
                @send-message="sendMessage"
                :disabled="isTyping"
            />
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue';
import axios from 'axios';
import { v4 as uuidv4 } from 'uuid';
import MessageInput from './MessageInput.vue';
import TypingIndicator from './TypingIndicator.vue';

const emit = defineEmits(['close']);

const messages = ref([]);
const conversationId = ref(null);
const sessionId = ref(localStorage.getItem('chat_session_id') || uuidv4());
const isTyping = ref(false);
const messagesContainer = ref(null);
const channelName = `chat-session.${sessionId.value}`;

localStorage.setItem('chat_session_id', sessionId.value);

const scrollToBottom = () => {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
};

watch(messages, scrollToBottom, { deep: true });

onMounted(async () => {
    // 1. Listen to the public channel
    if (window.Echo) {
        window.Echo.channel(channelName)
            .listen('.message.sent', (event) => {
                console.log('🎉 BROADCAST EVENT RECEIVED:', event);
                
                if (!messages.value.some(msg => msg.id === event.id)) {
                    messages.value.push(event);
                }
                
                if (event.sender === 'agent') {
                    isTyping.value = false;
                }
            });
    }

    // 2. Load the previous conversation
    try {
        const response = await axios.get(`/api/conversation/by-session/${sessionId.value}`);
        if (response.data && response.data.conversation_id) {
            conversationId.value = response.data.conversation_id;
            messages.value = response.data.messages;
        }
    } catch (error) {
        console.error('No previous conversation found or error loading:', error.response?.data || error.message);
    }
});

onUnmounted(() => {
    if (window.Echo) {
        window.Echo.leave(channelName);
    }
});

const sendMessage = async (messageText) => {
    if (!messageText.trim()) return;

    const visitorMessage = {
        id: 'temp-' + Date.now(),
        sender: 'visitor',
        body: messageText,
        created_at: new Date().toISOString(),
    };
    messages.value.push(visitorMessage);

    isTyping.value = true;
    scrollToBottom();

    try {
        const response = await axios.post('/api/chat', {
            message: messageText,
            conversation_id: conversationId.value,
            session_id: sessionId.value,
        });

        if (response.data.conversation_id) {
            conversationId.value = response.data.conversation_id;
        }

    } catch (error) {
        console.error('Error sending message:', error);
        isTyping.value = false;
        messages.value.push({
            id: 'error-' + Date.now(),
            sender: 'agent',
            body: "I'm sorry, an error occurred while sending your message. Please try again.",
            created_at: new Date().toISOString(),
        });
    }
};
</script>