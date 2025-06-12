import _ from 'lodash';
window._ = _;

import * as Popper from '@popperjs/core';
window.Popper = Popper;

import 'bootstrap';

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time features.
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname, // استخدم VITE_REVERB_HOST
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8081, // **استخدم المنفذ الجديد هنا!**
    wssPort: import.meta.env.VITE_REVERB_WSS_PORT ?? 8081, // **استخدم المنفذ الجديد هنا!**
    forceTLS: (import.meta.env.VITE_APP_ENV ?? 'local') === 'production', // اجبار TLS في الإنتاج
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
});
