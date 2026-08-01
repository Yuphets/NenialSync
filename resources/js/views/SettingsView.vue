<script setup>
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordMessage = ref('');
const syncMessage = ref('');
const showPasswords = ref(false);
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
    window.addEventListener('nenial:maintenance-changed', maintenanceChanged);
    return Promise.all([loadSync(), loadMaintenance()]);
});
onBeforeUnmount(() => {
    window.removeEventListener('nenial:maintenance-changed', maintenanceChanged);
});
</script>

<template>
    <PageHeader title="Settings" subtitle="Account security and store connectivity" />
    <p v-if="auth.user.must_change_password" class="notice">An administrator issued a temporary password. Change it now before continuing normal work.</p>
    <div class="two-col">
        <section class="panel profile">
            <img src="/media/Nenial.jpg">
            <h2>{{ auth.user.name }}</h2><p>{{ auth.user.email }}</p><span class="tag">{{ auth.user.role }}</span>
        </section>
        <form class="panel stack" @submit.prevent="save">
            <div class="panel-head"><h2>Change password</h2></div>
            <label>Current password<input v-model="form.current_password" :type="showPasswords ? 'text' : 'password'" autocomplete="current-password" required></label>
            <label>New password<input v-model="form.password" :type="showPasswords ? 'text' : 'password'" autocomplete="new-password" minlength="8" required></label>
            <label>Confirm new password<input v-model="form.password_confirmation" :type="showPasswords ? 'text' : 'password'" autocomplete="new-password" required></label>
            <label class="password-toggle"><input v-model="showPasswords" type="checkbox"><span>{{ showPasswords ? 'Hide passwords' : 'Show passwords' }}</span></label>
            <small>Use at least 8 characters with uppercase, lowercase, and a number.</small>
            <p v-if="passwordMessage" class="notice">{{ passwordMessage }}</p>
            <button class="btn primary">Update password</button>
        </form>
    </div>

    <section v-if="auth.role === 'admin' && maintenance" class="panel maintenance-control" :class="{ active: maintenance.enabled }">
        <div class="panel-head">
            <div>
                <span class="maintenance-kicker">Website availability</span>
                <h2>Maintenance mode</h2>
                <small>Temporarily block customer and staff access while keeping administrator recovery, synchronization, payment webhooks, and registered attendance devices available.</small>
            </div>
            <span class="tag" :class="{ warn: maintenance.enabled }">{{ maintenance.enabled ? 'Maintenance active' : 'Website online' }}</span>
        </div>
        <div class="maintenance-control-body">
            <div>
                <strong>{{ maintenance.enabled ? 'Only administrators can access the application.' : 'The website is available normally.' }}</strong>
                <p>{{ maintenance.message }}</p>
                <small v-if="maintenance.updated_at">Last changed {{ new Date(maintenance.updated_at).toLocaleString('en-US', { timeZone: 'Asia/Manila' }) }}</small>
            </div>
            <button
                ref="maintenanceLaunch"
                class="btn"
                :class="maintenance.enabled ? 'primary' : 'danger'"
                type="button"
                @click="openMaintenanceDialog(!maintenance.enabled)"
            >
                {{ maintenance.enabled ? 'Restore website access' : 'Start maintenance' }}
            </button>
        </div>
        <p v-if="maintenanceMessage" class="notice" role="status">{{ maintenanceMessage }}</p>
    </section>

    <section v-if="sync" class="panel sync-panel">
        <div class="panel-head"><div><h2>Store synchronization</h2><small>{{ sync.enabled ? `Local node: ${sync.node_id}` : 'Cloud deployment' }}</small></div><span class="tag" :class="{ warn: sync.conflicts || !sync.online }">{{ sync.enabled ? (sync.online ? 'Connected' : 'Offline') : 'Cloud mode' }}</span></div>
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
