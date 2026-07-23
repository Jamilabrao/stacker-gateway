import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// CSRF: meta → X-CSRF-TOKEN. Não enviar o valor plain da meta em X-XSRF-TOKEN
// (Laravel espera o cookie XSRF-TOKEN criptografado, decodificado pelo axios/cookie).
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : null;
}
window.axios.interceptors.request.use((config) => {
    const token = getCsrfToken();
    if (token) {
        config.headers['X-CSRF-TOKEN'] = token;
        if (config.headers['X-XSRF-TOKEN'] === token) {
            delete config.headers['X-XSRF-TOKEN'];
        }
    }
    return config;
});
