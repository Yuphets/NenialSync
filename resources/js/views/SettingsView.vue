<script setup>
import { onMounted, reactive, ref } from 'vue';
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
    } catch {
        sync.value = null;
    }
}

async function runSync() {
    syncing.value = true;
    syncMessage.value = '';
    try {
        sync.value = (await axios.post('/api/local-sync/run')).data;
        syncMessage.value = sync.value?.message || '';
    } catch (error) {
        syncMessage.value = error.response?.data?.message || error.response?.data?.sync?.message || 'Cloud synchronization failed.';
        await loadSync();
    } finally {
        syncing.value = false;
    }
}

onMounted(loadSync);
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
            <div><span>Last synchronized</span><strong>{{ sync.last_synced_at ? new Date(sync.last_synced_at).toLocaleString() : 'Not yet' }}</strong></div>
            <button v-if="sync.enabled" class="btn primary" :disabled="syncing" @click="runSync">{{ syncing ? 'Synchronizing…' : 'Synchronize now' }}</button>
        </div>
        <p v-if="syncMessage || sync.message" class="notice">{{ syncMessage || sync.message }}</p>
    </section>
</template>
