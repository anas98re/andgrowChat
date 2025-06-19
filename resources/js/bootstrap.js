import _ from 'lodash';
window._ = _;

/**
 * We'll load the axios HTTP library.
 */
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js'; 

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: 'c1e9f7578613f809623e', 
    cluster: 'us2',            
    encrypted: true,        
    forceTLS: true           
});