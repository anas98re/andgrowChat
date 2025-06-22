<template>
    <form @submit.prevent="handleSubmit" 
          class="flex items-center space-x-2 border-gray-200 py-1 px-3">
        
        <!-- Plus Icon Button -->
        

        <!-- Text Input Area that grows -->
        <div class="flex-1 relative">
            <input
                type="text"
                v-model="message"
                :disabled="disabled"
                placeholder="Tell AI what to do next"
                class="w-full pl-1 pr-28 py-2 bg-transparent border-none focus:outline-none focus:ring-0 text-sm text-gray-800 placeholder-gray-400"
                @keydown.enter.prevent="handleSubmit"
            />
            <!-- Middle Icons positioned over the input -->
           
        </div>

        <!-- Send Button on the far right -->
        <button
            type="submit"
            :disabled="!message.trim() || disabled"
            class="w-8 h-8 flex items-center justify-center bg-gray-200 hover:bg-gray-300 text-gray-600 rounded-lg disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed transition-colors duration-200 flex-shrink-0"
            title="Send"
        >
            <!-- Send Icon SVG (Paper plane style) -->
            <svg class="w-5 h-5 -mr-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
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
    if (message.value.trim() && !props.disabled) {
        emit('send-message', message.value);
        message.value = '';
    }
};
</script>