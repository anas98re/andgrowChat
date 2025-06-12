<template>
    <div class="flex justify-start">
        <div class="bg-gray-200 text-gray-800 p-3 rounded-lg shadow-md max-w-[75%]">
            <span class="dot-flashing"></span>
            <span class="ml-2 text-sm">Andgrow Help Assistant is typing...</span>
        </div>
    </div>
</template>

<style scoped>
/* CSS for the typing animation */
.dot-flashing {
    position: relative;
    width: 10px;
    height: 10px;
    border-radius: 5px;
    background-color: #999;
    color: #999;
    animation: dotFlashing 1s infinite alternate;
    animation-delay: 0s;
    display: inline-block;
}

.dot-flashing::before, .dot-flashing::after {
    content: '';
    display: inline-block;
    position: absolute;
    top: 0;
    width: 10px;
    height: 10px;
    border-radius: 5px;
    background-color: #999;
    color: #999;
    animation: dotFlashing 1s infinite alternate;
}

.dot-flashing::before {
    left: -15px;
    animation-delay: .4s;
}

.dot-flashing::after {
    left: 15px;
    animation-delay: .8s;
}

@keyframes dotFlashing {
    0% { background-color: #999; }
    50%, 100% { background-color: #ccc; }
}
</style>
