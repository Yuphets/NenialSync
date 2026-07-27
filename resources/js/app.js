import { createApp } from 'vue';
import { createPinia } from 'pinia';
import axios from 'axios';
import App from './App.vue';
import router from './router';

axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content;
axios.defaults.withCredentials = true;

let maintenanceRedirectPending = false;
const maintenanceExemptPath = () =>
    ['/login', '/maintenance', '/face-terminal'].some((path) =>
        window.location.pathname.startsWith(path),
    );

function redirectToMaintenance() {
    if (maintenanceRedirectPending || maintenanceExemptPath()) return;
    maintenanceRedirectPending = true;
    window.location.assign('/maintenance');
}

axios.interceptors.response.use(
    response => response,
    error => {
        if (
            error.response?.status === 503 &&
            error.response?.data?.maintenance &&
            !maintenanceExemptPath()
        ) {
            redirectToMaintenance();
        }

        return Promise.reject(error);
    },
);

async function checkMaintenanceStatus() {
    if (document.hidden || maintenanceExemptPath()) return;

    try {
        const { data } = await axios.get('/api/system/status');
        window.dispatchEvent(new CustomEvent('nenial:maintenance-changed', {
            detail: data,
        }));
        if (data.enabled && !data.admin_access) redirectToMaintenance();
    } catch {
        // The normal API interceptor handles maintenance responses. Network
        // outages remain available to the offline-capable store interface.
    }
}

createApp(App).use(createPinia()).use(router).mount('#app');

window.setInterval(checkMaintenanceStatus, 10000);
window.addEventListener('focus', checkMaintenanceStatus);
document.addEventListener('visibilitychange', checkMaintenanceStatus);
checkMaintenanceStatus();

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').then(registration => registration.update()));
}
