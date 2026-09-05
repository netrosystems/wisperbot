import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Attach the CSRF token from the meta tag to every axios request automatically.
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

// Keep the global AI-credit meter current after foreground mutations. The meter
// performs the scoped refresh; responses never need to expose billing internals.
window.axios.interceptors.response.use((response) => {
    const method = String(response.config?.method ?? 'get').toLowerCase();
    if (!['get', 'head', 'options'].includes(method)) {
        window.dispatchEvent(new window.CustomEvent('wisperbot:ai-credits-refresh'));
    }

    return response;
});
