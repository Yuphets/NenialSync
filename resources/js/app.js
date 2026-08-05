import { createApp } from 'vue';
import { createPinia } from 'pinia';
import axios from 'axios';
import App from './App.vue';
import router from './router';

axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content;
axios.defaults.withCredentials = true;

const themeStorageKey = 'nenial.theme.v1';

function applyTheme(theme) {
    const normalized = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.dataset.theme = normalized;
    document.querySelector('meta[name="theme-color"]')?.setAttribute(
        'content',
        normalized === 'dark' ? '#0d1712' : '#167247',
    );
}

function applySavedTheme() {
    let savedTheme = 'light';
    try {
        savedTheme = localStorage.getItem(themeStorageKey) === 'dark' ? 'dark' : 'light';
    } catch {
        // The default light theme remains available when storage is blocked.
    }
    applyTheme(savedTheme);
}

applySavedTheme();

window.addEventListener('nenial:theme-changed', (event) => {
    applyTheme(event.detail?.theme);
});

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
