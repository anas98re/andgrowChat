<template>
    <div class="flex w-full items-start gap-3 justify-start">
        <!-- Agent Avatar -->
        <div class="flex-shrink-0 w-7 h-7 rounded-full bg-gradient-to-r from-cyan-200 to-pink-200 flex items-center justify-center text-black text-xs mt-1">
            AI
        </div>
        
        <!-- The actual typing dots -->
        <div class="flex items-center space-x-1 h-7 mt-1">
            <span class="dot-pulse"></span>
            <span class="dot-pulse animation-delay-200"></span>
            <span class="dot-pulse animation-delay-400"></span>
        </div>
    </div>
</template>

<style scoped>
.dot-pulse {
    display: block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: #a0aec0; /* gray-500 */
    animation: pulse 1.4s infinite ease-in-out both;
}

.animation-delay-200 {
    animation-delay: 0.2s;
}

.animation-delay-400 {
    animation-delay: 0.4s;
}

@keyframes pulse {
    0%, 80%, 100% {
        transform: scale(0);
        opacity: 0.5;
    }
    40% {
        transform: scale(1.0);
        opacity: 1;
    }
}
</style>