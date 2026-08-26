import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// S19 N11 — Reverb speaks the Pusher protocol (config/broadcasting.php's
// 'reverb' connection is literally built by createPusherDriver() server-side
// too, see B0's plan notes), so the client uses the 'reverb' broadcaster
// Echo ships specifically for it rather than the generic 'pusher' one.
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
