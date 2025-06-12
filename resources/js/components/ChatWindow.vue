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
                        {{ new Date(msg.created_at).toLocaleTimeString() }}
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
import { v4 as uuidv4 } from 'uuid'; // لتوليد session_id
import MessageInput from './MessageInput.vue';
import TypingIndicator from './TypingIndicator.vue';

const emit = defineEmits(['close']); // تعريف الأحداث التي يمكن أن يصدرها المكون

const messages = ref([]);
const conversationId = ref(null);
const sessionId = ref(localStorage.getItem('chat_session_id') || uuidv4()); // استخدام session_id من localStorage
const isTyping = ref(false); // مؤشر الكتابة
const messagesContainer = ref(null); // مرجع لعنصر الـ div الذي يحتوي على الرسائل

// حفظ session_id في localStorage
localStorage.setItem('chat_session_id', sessionId.value);

// وظيفة التمرير للأسفل في منطقة الرسائل
const scrollToBottom = () => {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
};

// مراقبة تغييرات الرسائل للتمرير تلقائياً
watch(messages, scrollToBottom, { deep: true });

// تحميل الرسائل السابقة عند فتح نافذة الدردشة
onMounted(async () => {
    // التحقق من وجود محادثة سابقة باستخدام session_id
    try {
        const response = await axios.get(`/api/conversation/by-session/${sessionId.value}`); // سنحتاج لتغيير مسار API هذا
        if (response.data.conversation_id) {
            conversationId.value = response.data.conversation_id;
            messages.value = response.data.messages;
        }
    } catch (error) {
        console.error('Error loading previous conversation:', error);
        // إذا لم توجد محادثة، سنقوم بإنشاء واحدة عند إرسال أول رسالة
    }

    // الاستماع إلى قناة المحادثة
    if (window.Echo) {
        window.Echo.private(`conversation.${conversationId.value || sessionId.value}`) // استخدم sessionId إذا لم يكن هناك conversationId
            .listen('.message.sent', (e) => {
                console.log('Received message:', e.message);
                messages.value.push(e.message);
                isTyping.value = false; // توقف مؤشر الكتابة عند وصول الرد
            })
            .error((error) => {
                console.error('Echo Error:', error);
            });
    } else {
        console.warn('Laravel Echo is not initialized. Real-time features will not work.');
    }
    scrollToBottom();
});

onUnmounted(() => {
    // مغادرة قناة المحادثة عند إغلاق نافذة الدردشة
    if (window.Echo && conversationId.value) {
        window.Echo.leave(`conversation.${conversationId.value}`);
    }
});

const sendMessage = async (messageText) => {
    if (!messageText.trim()) return;

    isTyping.value = true; // إظهار مؤشر الكتابة

    const newMessage = {
        id: Date.now(), // ID مؤقت للواجهة الأمامية
        conversation_id: conversationId.value,
        sender: 'visitor',
        body: messageText,
        created_at: new Date().toISOString(),
    };
    messages.value.push(newMessage);
    scrollToBottom();

    try {
        const response = await axios.post('/api/chat', {
            message: messageText,
            conversation_id: conversationId.value,
            session_id: sessionId.value, // إرسال session_id مع كل طلب
        });
        if (response.data.conversation_id && !conversationId.value) {
            conversationId.value = response.data.conversation_id;
            // إعادة تهيئة قناة Echo مع الـ conversation_id الجديد
            if (window.Echo) {
                window.Echo.leave(`conversation.${sessionId.value}`); // ترك القناة القديمة (إذا كانت تستخدم sessionId)
                window.Echo.private(`conversation.${conversationId.value}`)
                    .listen('.message.sent', (e) => {
                        console.log('Received message:', e.message);
                        messages.value.push(e.message);
                        isTyping.value = false;
                    })
                    .error((error) => {
                        console.error('Echo Error after conversation ID update:', error);
                    });
            }
        }
    } catch (error) {
        console.error('Error sending message:', error);
        isTyping.value = false; // إخفاء المؤشر حتى في حالة الخطأ
        // إضافة رسالة خطأ للمستخدم
        messages.value.push({
            id: Date.now() + 1,
            sender: 'agent',
            body: "I'm sorry, I couldn't send your message. Please try again.",
            created_at: new Date().toISOString(),
        });
        scrollToBottom();
    }
};
</script>

<style scoped>
/* يمكنك إضافة بعض Tailwind utilities هنا أو في ملف CSS الرئيسي */
</style>
