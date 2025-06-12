<template>
    <form @submit.prevent="handleSubmit" class="flex items-center space-x-2">
        <input
            type="text"
            v-model="message"
            :disabled="disabled"
            placeholder="Type your message..."
            class="flex-1 p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
        <button
            type="submit"
            :disabled="!message.trim() || disabled"
            class="bg-blue-600 hover:bg-blue-700 text-white p-2 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition duration-150"
        >
            Send
        </button>
    </form>
</template>

<script setup>
import { ref } from 'vue';

const message = ref('');
const emit = defineEmits(['send-message']);
const props = defineProps({
    disabled: {
        type: Boolean,
        default: false
    }
});

const handleSubmit = () => {
    if (message.value.trim()) {
        emit('send-message', message.value);
        message.value = '';
    }
};
</script>
