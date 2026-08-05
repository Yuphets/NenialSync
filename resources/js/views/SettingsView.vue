<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordMessage = ref('');
const syncMessage = ref('');
const showPasswords = ref(false);
const darkMode = ref(false);
const sync = ref(null);
const syncing = ref(false);
const conflicts = ref([]);
const resolvingConflict = ref(null);
const maintenance = ref(null);
const maintenanceBusy = ref(false);
const maintenanceMessage = ref('');
const showMaintenanceDialog = ref(false);
const showMaintenancePassword = ref(false);
const maintenanceDialog = ref(null);
const maintenanceLaunch = ref(null);
const maintenanceForm = reactive({
    enabled: false,
    message: 'We are currently performing scheduled maintenance. Please check back shortly.',
    current_password: '',
    confirmation: '',
});

const memberSince = computed(() => {
    if (!auth.user?.created_at) return 'Not available';

    const createdAt = new Date(auth.user.created_at);
    if (Number.isNaN(createdAt.getTime())) return 'Not available';

    return createdAt.toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Manila',
    });
});

function toggleDarkMode() {
    darkMode.value = !darkMode.value;
    const theme = darkMode.value ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    try {
        localStorage.setItem('nenial.theme.v1', theme);
    } catch {
        // The theme still applies for this session when storage is blocked.
    }
    window.dispatchEvent(new CustomEvent('nenial:theme-changed', {
        detail: { theme },
    }));
}

function themeChanged(event) {
    darkMode.value = event.detail?.theme === 'dark';
}

async function save() {
    try {
        passwordMessage.value = (await axios.put('/api/auth/password', form)).data.message;
        await auth.hydrate();
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
    } catch (error) {
        passwordMessage.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'Unable to update password.';
    }
}

async function loadSync() {
    if (!['admin', 'assistant'].includes(auth.role)) return;
    try {
        sync.value = (await axios.get('/api/local-sync/status')).data;
        await loadConflicts();
    } catch {
        sync.value = null;
        conflicts.value = [];
    }
}

async function loadConflicts() {
    if (auth.role !== 'admin' || !sync.value?.enabled) {
        conflicts.value = [];
        return;
    }

    try {
        conflicts.value = (await axios.get('/api/local-sync/conflicts')).data.data || [];
    } catch {
        conflicts.value = [];
    }
}

async function runSync() {
    syncing.value = true;
    syncMessage.value = '';
    try {
        sync.value = (await axios.post('/api/local-sync/run')).data;
        syncMessage.value = sync.value?.message || '';
        await loadConflicts();
    } catch (error) {
        syncMessage.value = error.response?.data?.message || error.response?.data?.sync?.message || 'Cloud synchronization failed.';
        await loadSync();
    } finally {
        syncing.value = false;
    }
}

async function resolveConflict(conflict, action) {
    if (
        action === 'accept_remote' &&
        !confirm('Keep the cloud version and discard this store-local change? This cannot be undone.')
    ) {
        return;
    }

    resolvingConflict.value = conflict.id;
    syncMessage.value = '';
    try {
        await axios.post(`/api/local-sync/conflicts/${conflict.id}/resolve`, {
            action,
        });
        sync.value = (await axios.post('/api/local-sync/run')).data;
        await loadConflicts();
        syncMessage.value = sync.value?.online
            ? action === 'retry'
                ? 'The local change was retried and synchronization completed.'
                : 'The conflict was resolved and the cloud version was applied locally.'
            : sync.value?.message || 'The conflict was resolved, but synchronization is still offline.';
    } catch (error) {
        syncMessage.value =
            error.response?.data?.message ||
            'The synchronization conflict could not be resolved.';
    } finally {
        resolvingConflict.value = null;
    }
}

async function loadMaintenance() {
    if (auth.role !== 'admin') return;
    try {
        maintenance.value = (await axios.get('/api/system/status')).data;
    } catch {
        maintenance.value = null;
    }
}

function maintenanceChanged(event) {
    maintenance.value = event.detail;
}

function openMaintenanceDialog(enabled) {
    maintenanceMessage.value = '';
    showMaintenancePassword.value = false;
    Object.assign(maintenanceForm, {
        enabled,
        message: maintenance.value?.message || 'We are currently performing scheduled maintenance. Please check back shortly.',
        current_password: '',
        confirmation: '',
    });
    showMaintenanceDialog.value = true;
    nextTick(() => {
        const initialControl = maintenanceDialog.value?.querySelector(
            enabled ? 'textarea' : 'input[autocomplete="current-password"]',
        );
        initialControl?.focus();
    });
}

function closeMaintenanceDialog(force = false) {
    if (maintenanceBusy.value && !force) return;
    showMaintenanceDialog.value = false;
    showMaintenancePassword.value = false;
    nextTick(() => maintenanceLaunch.value?.focus());
}

function handleMaintenanceDialogKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeMaintenanceDialog();
        return;
    }
    if (event.key !== 'Tab') return;

    const focusable = [...maintenanceDialog.value.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]',
    )].filter((element) => element.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

async function saveMaintenance() {
    maintenanceBusy.value = true;
    maintenanceMessage.value = '';
    try {
        const { data } = await axios.put('/api/admin/maintenance', maintenanceForm);
        maintenance.value = data.maintenance;
        maintenanceMessage.value = data.message;
        window.dispatchEvent(new CustomEvent('nenial:maintenance-changed', {
            detail: data.maintenance,
        }));
        closeMaintenanceDialog(true);
    } catch (error) {
        maintenanceMessage.value =
            error.response?.data?.message ||
            Object.values(error.response?.data?.errors || {})[0]?.[0] ||
            'Unable to change maintenance mode.';
    } finally {
        maintenanceBusy.value = false;
    }
}

onMounted(() => {
    darkMode.value = document.documentElement.dataset.theme === 'dark';
    window.addEventListener('nenial:maintenance-changed', maintenanceChanged);
    window.addEventListener('nenial:theme-changed', themeChanged);
    return Promise.all([loadSync(), loadMaintenance()]);
});
onBeforeUnmount(() => {
    window.removeEventListener('nenial:maintenance-changed', maintenanceChanged);
    window.removeEventListener('nenial:theme-changed', themeChanged);
});
</script>

<template>
    <div class="settings-topbar">
        <PageHeader title="Settings" subtitle="Manage your account, password, and system preferences." />
        <button class="settings-theme-card" type="button" role="switch" :aria-checked="darkMode" aria-label="Toggle dark mode" @click="toggleDarkMode">
            <span class="settings-icon settings-moon" aria-hidden="true">☾</span>
            <span><strong>Dark mode</strong><small>Switch between light and<br>dark appearance.</small></span>
            <span class="settings-switch" :class="{ active: darkMode }" aria-hidden="true"><i></i></span>
        </button>
    </div>
    <p v-if="auth.user.must_change_password" class="notice">An administrator issued a temporary password. Change it now before continuing normal work.</p>
    <div class="settings-grid">
        <section class="panel settings-card settings-profile">
            <div class="settings-card-title"><span class="settings-icon" aria-hidden="true">♙</span><h2>Profile</h2></div>
            <div class="settings-profile-main">
                <img src="/media/Nenial.jpg" alt="Nenial profile">
                <h2>{{ auth.user.name }}</h2><p>{{ auth.user.email }}</p><span class="tag">{{ auth.user.role }}</span>
            </div>
            <div class="settings-details">
                <div><span class="settings-detail-icon" aria-hidden="true">♙</span><span>Role</span><strong>{{ auth.user.role === 'admin' ? 'Administrator' : auth.user.role }}</strong></div>
                <div><span class="settings-detail-icon" aria-hidden="true">◷</span><span>Account Status</span><strong class="settings-status">Active</strong></div>
                <div><span class="settings-detail-icon" aria-hidden="true">◷</span><span>Member Since</span><strong>{{ memberSince }}</strong></div>
            </div>
        </section>
        <form class="panel settings-card settings-password" @submit.prevent="save">
            <div class="settings-card-title"><span class="settings-icon" aria-hidden="true">♙</span><h2>Change password</h2></div>
            <label>Current password<div class="settings-input-wrap"><input v-model="form.current_password" :type="showPasswords ? 'text' : 'password'" autocomplete="current-password" required><span aria-hidden="true">⊙</span></div></label>
            <label>New password<div class="settings-input-wrap"><input v-model="form.password" :type="showPasswords ? 'text' : 'password'" autocomplete="new-password" minlength="8" required><span aria-hidden="true">⊙</span></div></label>
            <label>Confirm new password<div class="settings-input-wrap"><input v-model="form.password_confirmation" :type="showPasswords ? 'text' : 'password'" autocomplete="new-password" required><span aria-hidden="true">⊙</span></div></label>
            <label class="password-toggle settings-checkbox"><input v-model="showPasswords" type="checkbox"><span>{{ showPasswords ? 'Hide passwords' : 'Show passwords' }}</span></label>
            <small>Use at least 8 characters with uppercase, lowercase, and a number.</small>
            <p v-if="passwordMessage" class="notice">{{ passwordMessage }}</p>
            <button class="btn primary settings-submit">Update password</button>
        </form>
    </div>

    <section v-if="auth.role === 'admin' && maintenance" class="panel settings-wide-card maintenance-control" :class="{ active: maintenance.enabled }">
        <div class="panel-head">
            <div class="settings-section-heading">
                <span class="settings-icon" aria-hidden="true">◎</span>
                <div>
                <span class="maintenance-kicker">Website availability</span>
                <h2>Maintenance mode</h2>
                <small>Temporarily block customer and staff access while keeping administrator recovery, synchronization, payment webhooks, and registered attendance devices available.</small>
                </div>
            </div>
            <button
                ref="maintenanceLaunch"
                class="btn settings-maintenance-button"
                :class="maintenance.enabled ? 'primary' : 'danger'"
                type="button"
                @click="openMaintenanceDialog(!maintenance.enabled)"
            >
                {{ maintenance.enabled ? 'Restore website access' : 'Start maintenance' }}
            </button>
        </div>
        <div class="maintenance-control-body">
            <div>
                <span class="tag" :class="{ warn: maintenance.enabled }">{{ maintenance.enabled ? 'Maintenance active' : 'Website online' }}</span>
                <strong>{{ maintenance.enabled ? 'Only administrators can access the application.' : 'The website is available normally.' }}</strong>
                <p>{{ maintenance.message }}</p>
                <small v-if="maintenance.updated_at">Last changed {{ new Date(maintenance.updated_at).toLocaleString('en-US', { timeZone: 'Asia/Manila' }) }}</small>
            </div>
        </div>
        <p v-if="maintenanceMessage" class="notice" role="status">{{ maintenanceMessage }}</p>
    </section>

    <section v-if="sync" class="panel settings-wide-card sync-panel">
        <div class="panel-head settings-sync-head"><div class="settings-section-heading"><span class="settings-icon" aria-hidden="true">☁</span><div><h2>Store synchronization</h2><small>{{ sync.enabled ? `Local node: ${sync.node_id}` : 'Cloud deployment' }}</small></div></div><span class="tag" :class="{ warn: sync.conflicts || !sync.online }">{{ sync.enabled ? (sync.online ? 'Connected' : 'Offline') : 'Cloud mode' }}</span></div>
        <div class="sync-grid">
            <div><span>Pending events</span><strong>{{ sync.pending }}</strong></div>
            <div><span>Open conflicts</span><strong>{{ sync.conflicts }}</strong></div>
            <div><span>Accounts & workforce</span><strong>{{ sync.accounts_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Devices</span><strong>{{ sync.devices_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Face enrollments</span><strong>{{ sync.face_enrollments_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Inventory activity</span><strong>{{ sync.activity_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Order fulfillment</span><strong>{{ sync.orders_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Attendance</span><strong>{{ sync.attendance_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Payroll snapshots</span><strong>{{ sync.payroll_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></div>
            <div><span>Payroll standards</span><strong>{{ sync.statutory_rates_synced ? 'Synchronized' : (sync.enabled ? 'Awaiting cloud update' : 'Cloud source') }}</strong></div>
            <div><span>Last synchronized</span><strong>{{ sync.last_synced_at ? new Date(sync.last_synced_at).toLocaleString('en-US', { timeZone: 'Asia/Manila' }) : 'Not yet' }}</strong></div>
            <button v-if="sync.enabled" class="btn primary" :disabled="syncing" @click="runSync">{{ syncing ? 'Synchronizing…' : 'Synchronize now' }}</button>
        </div>
        <p v-if="syncMessage || sync.message" class="notice">{{ syncMessage || sync.message }}</p>
    </section>

    <section v-if="conflicts.length" class="panel sync-conflicts">
        <div class="panel-head">
            <div>
                <h2>Synchronization conflicts</h2>
                <small>Review store-local changes that could not be applied safely to the cloud.</small>
            </div>
            <span class="tag warn">{{ conflicts.length }} open</span>
        </div>
        <div class="sync-conflict-list">
            <article v-for="conflict in conflicts" :key="conflict.id" class="sync-conflict">
                <div>
                    <strong>{{ conflict.event_type.replaceAll('.', ' ') }}</strong>
                    <p>{{ conflict.reason }}</p>
                    <small>{{ new Date(conflict.created_at).toLocaleString('en-US', { timeZone: 'Asia/Manila' }) }}</small>
                </div>
                <div class="actions">
                    <button
                        v-if="conflict.can_retry"
                        class="btn"
                        type="button"
                        :disabled="resolvingConflict === conflict.id"
                        @click="resolveConflict(conflict, 'retry')"
                    >
                        Retry local change
                    </button>
                    <button
                        class="btn danger"
                        type="button"
                        :disabled="resolvingConflict === conflict.id"
                        @click="resolveConflict(conflict, 'accept_remote')"
                    >
                        Keep cloud version
                    </button>
                </div>
            </article>
        </div>
    </section>

    <div
        v-if="showMaintenanceDialog"
        class="modal"
        @click.self="closeMaintenanceDialog()"
        @keydown="handleMaintenanceDialogKeydown"
    >
        <form
            ref="maintenanceDialog"
            class="modal-card maintenance-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="maintenance-dialog-title"
            @submit.prevent="saveMaintenance"
        >
            <div class="panel-head">
                <div>
                    <span class="maintenance-kicker">Administrator confirmation</span>
                    <h2 id="maintenance-dialog-title">{{ maintenanceForm.enabled ? 'Start website maintenance' : 'Restore website access' }}</h2>
                </div>
                <button type="button" class="btn ghost" :disabled="maintenanceBusy" @click="closeMaintenanceDialog()">Close</button>
            </div>
            <p :class="maintenanceForm.enabled ? 'maintenance-warning' : 'notice'">
                {{ maintenanceForm.enabled
                    ? 'Customers, assistants, cashiers, and ordinary users will be signed out or blocked immediately. You will retain administrator access.'
                    : 'This will reopen the storefront and staff workspaces immediately.' }}
            </p>
            <label v-if="maintenanceForm.enabled">Public maintenance message
                <textarea v-model="maintenanceForm.message" rows="3" maxlength="240" required></textarea>
            </label>
            <label>Administrator password
                <span class="password-field">
                    <input v-model="maintenanceForm.current_password" :type="showMaintenancePassword ? 'text' : 'password'" autocomplete="current-password" required>
                    <button
                        class="password-eye"
                        type="button"
                        :aria-label="showMaintenancePassword ? 'Hide administrator password' : 'Show administrator password'"
                        :aria-pressed="showMaintenancePassword"
                        @click="showMaintenancePassword = !showMaintenancePassword"
                    >
                        {{ showMaintenancePassword ? 'Hide' : 'Show' }}
                    </button>
                </span>
            </label>
            <label>Type <strong>{{ maintenanceForm.enabled ? 'START MAINTENANCE' : 'RESTORE WEBSITE' }}</strong>
                <input v-model="maintenanceForm.confirmation" autocomplete="off" required>
            </label>
            <p v-if="maintenanceMessage" class="error" role="alert">{{ maintenanceMessage }}</p>
            <button class="btn full" :class="maintenanceForm.enabled ? 'danger' : 'primary'" :disabled="maintenanceBusy">
                {{ maintenanceBusy ? 'Applying…' : maintenanceForm.enabled ? 'Confirm maintenance shutdown' : 'Confirm website restoration' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
.settings-topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;max-width:1600px;margin:0 auto 22px}.settings-topbar :deep(.page-header){margin:0;flex:1}.settings-theme-card{display:flex;align-items:center;gap:13px;min-width:328px;padding:17px 18px;border:1px solid #dce6df;border-radius:13px;background:#fff;box-shadow:0 10px 28px rgba(18,55,36,.06)}.settings-theme-card strong,.settings-theme-card small{display:block}.settings-theme-card strong{font-size:.78rem}.settings-theme-card small{margin-top:4px;color:var(--muted);font-size:.72rem;line-height:1.45}.settings-icon{display:grid;place-items:center;flex:0 0 40px;width:40px;height:40px;border-radius:9px;color:#08733f;background:#e2f5e9;font-size:1.45rem;line-height:1}.settings-moon{font-size:1.7rem}.settings-switch{display:block;position:relative;width:38px;height:22px;margin-left:auto;border-radius:999px;background:#08733f}.settings-switch i{position:absolute;top:3px;right:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.22)}.settings-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:16px;max-width:1600px;margin:0 auto 18px}.settings-card{margin:0;min-width:0;border-radius:15px}.settings-card-title{display:flex;align-items:center;gap:15px;padding:16px 18px;border-bottom:1px solid var(--line)}.settings-card-title h2{margin:0;font-size:1rem}.settings-profile{padding:0}.settings-profile-main{display:grid;place-items:center;padding:18px 24px 20px;text-align:center}.settings-profile-main img{width:92px;height:92px;border:5px solid #b8eac8;border-radius:50%;object-fit:cover}.settings-profile-main h2{margin:12px 0 4px;font-size:1.1rem}.settings-profile-main p{margin:0 0 10px;color:var(--muted);font-size:.8rem}.settings-details{margin:0 18px;border-top:1px solid var(--line)}.settings-details>div{display:grid;grid-template-columns:24px 1fr auto;align-items:center;gap:12px;padding:13px 4px;border-bottom:1px solid var(--line);font-size:.78rem}.settings-details>div:last-child{border-bottom:0}.settings-details strong{font-weight:500}.settings-detail-icon{color:var(--brand);font-size:1.15rem}.settings-status{padding:5px 10px;border-radius:999px;color:#08733f!important;background:#e2f5e9;font-weight:750!important}.settings-password{display:grid;gap:13px;padding:0 18px 16px}.settings-password .settings-card-title{margin:0 -18px 2px}.settings-password label{gap:6px}.settings-input-wrap{position:relative}.settings-input-wrap input{padding-right:42px}.settings-input-wrap>span{position:absolute;top:50%;right:14px;color:#61766b;font-size:1.15rem;transform:translateY(-50%)}.settings-checkbox{margin:1px 0 0}.settings-password>small{color:#587064;font-size:.72rem}.settings-submit{width:100%;min-height:38px;margin-top:4px}.settings-wide-card{max-width:1600px;margin:0 auto 18px;border-radius:15px}.settings-section-heading{display:flex;align-items:flex-start;gap:15px}.settings-section-heading>div{display:grid;gap:3px}.settings-section-heading h2{margin:0}.settings-section-heading small{max-width:900px;line-height:1.45}.settings-maintenance-button{min-width:152px;background:#fff}.maintenance-control .panel-head{align-items:flex-start;padding:16px 18px}.maintenance-control-body{padding:14px 72px 18px 73px}.maintenance-control-body>div{display:grid;gap:7px}.maintenance-control-body .tag{margin-bottom:3px}.maintenance-control-body strong{color:var(--brand);font-size:.8rem}.maintenance-control-body p{font-size:.78rem}.sync-panel{margin-top:0}.settings-sync-head{padding:16px 18px}.sync-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:0;padding:0;border:1px solid var(--line);border-radius:12px;margin:0 14px 14px;overflow:hidden}.sync-grid>div{min-height:70px;padding:13px 12px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);background:#fff}.sync-grid>div:nth-child(5n){border-right:0}.sync-grid>div:nth-last-child(-n+5){border-bottom:0}.sync-grid span{font-size:.67rem}.sync-grid strong{font-size:.74rem;line-height:1.35}.sync-grid>.btn{margin:13px;grid-column:span 2}.sync-conflicts{max-width:1600px}.settings-theme-card+*{max-width:1600px}
@media(max-width:1000px){.settings-grid{grid-template-columns:1fr}.sync-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.sync-grid>div:nth-child(5n){border-right:1px solid var(--line)}.sync-grid>div:nth-child(3n){border-right:0}.sync-grid>div:nth-last-child(-n+5){border-bottom:1px solid var(--line)}.sync-grid>div:nth-last-child(-n+3){border-bottom:0}}
@media(max-width:700px){.settings-topbar{display:block}.settings-theme-card{min-width:0;margin-top:16px}.settings-grid{gap:14px}.maintenance-control .panel-head{display:block}.settings-maintenance-button{width:100%;margin-top:14px}.maintenance-control-body{padding:14px 18px 18px}.sync-grid{grid-template-columns:repeat(2,minmax(0,1fr));margin:0 10px 10px}.sync-grid>div:nth-child(3n){border-right:1px solid var(--line)}.sync-grid>div:nth-child(2n){border-right:0}.sync-grid>div:nth-last-child(-n+3){border-bottom:1px solid var(--line)}.sync-grid>div:nth-last-child(-n+2){border-bottom:0}.sync-grid>.btn{grid-column:span 2}}
.settings-theme-card{color:var(--ink);text-align:left;cursor:pointer}.settings-theme-card:hover{border-color:#9bcbb0;box-shadow:0 12px 30px rgba(18,55,36,.11)}.settings-switch.active{background:#48a876}.settings-switch.active i{right:3px}
:global(html[data-theme="dark"]) .settings-theme-card{border-color:var(--line);background:var(--surface);color:var(--ink)}:global(html[data-theme="dark"]) .settings-profile-main img{border-color:#286d49}:global(html[data-theme="dark"]) .settings-profile-main,:global(html[data-theme="dark"]) .settings-details>div,:global(html[data-theme="dark"]) .sync-grid>div{background:var(--surface)}:global(html[data-theme="dark"]) .settings-maintenance-button{background:var(--surface)}:global(html[data-theme="dark"]) .settings-input-wrap>span{color:#b5cabe}
</style>
