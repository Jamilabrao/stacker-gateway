import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';
// Axios 1.x: envia o cookie XSRF em requests same-origin com credentials.
window.axios.defaults.withXSRFToken = true;

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
    // FormData: nunca forçar Content-Type (senão some o boundary e o CSRF/body quebram).
    if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
        if (config.headers && typeof config.headers === 'object') {
            delete config.headers['Content-Type'];
            delete config.headers['content-type'];
        }
    }
    return config;
});
