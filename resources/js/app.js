import '../css/app.css';
import './bootstrap'; // هذا السطر يستورد كل شيء من bootstrap.js (بما في ذلك Echo المهيأ)
import { createApp } from 'vue';

// Import the main components of the front-end
import ChatLauncher from './components/ChatLauncher.vue';

const app = createApp({});

// Register components
app.component('chat-launcher', ChatLauncher);

app.mount('#app');