<template>
    <div class="fixed bottom-4 right-4 z-50">
        <button
            @click="toggleChat"
            class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-full shadow-lg transition duration-300 ease-in-out"
        >
            <svg v-if="!isOpen" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="transform scale-95 opacity-0 translate-y-full"
            enter-to-class="transform scale-100 opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-300"
            leave-from-class="transform scale-100 opacity-100 translate-y-0"
            leave-to-class="transform scale-95 opacity-0 translate-y-full"
        >
            <ChatWindow
                v-if="isOpen"
                @close="isOpen = false"
            />
        </Transition>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import ChatWindow from './ChatWindow.vue'; // Import the chat window component

const isOpen = ref(false);

const toggleChat = () => {
    isOpen.value = !isOpen.value;
};
</script>
