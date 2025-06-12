import './bootstrap'; // axios , Echo
import { createApp } from 'vue';

// Import the main components of the front-end
import ChatLauncher from './components/ChatLauncher.vue';

const app = createApp({});

// Register components
app.component('chat-launcher', ChatLauncher);

app.mount('#app'); // There must be a div id="app" in the blade file

// تهيئة Laravel Echo (هذا يتم عادة في resources/js/bootstrap.js)
// إذا لم يكن لديك bootstrap.js أو تريد تكويناً خاصاً:
/*
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb', // هام: استخدم 'reverb' هنا
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST, // أو يمكنك استخدام VITE_REVERB_SERVER_HOST
    wsPort: import.meta.env.VITE_REVERB_PORT, // أو VITE_REVERB_SERVER_PORT
    wssPort: import.meta.env.VITE_REVERB_WSS_PORT, // إذا كنت تستخدم WSS
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    disableStats: true, // اختياري لتقليل حجم المكتبة
    enabledTransports: ['ws', 'wss'], // لضمان استخدام WebSockets
});
*/
