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
const darkMode = ref(false);

function applyTheme() {
    document.documentElement.dataset.theme = darkMode.value ? 'dark' : 'light';
    localStorage.setItem('nenial-theme', darkMode.value ? 'dark' : 'light');
    window.dispatchEvent(new CustomEvent('nenial-theme-change', { detail: darkMode.value }));
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

onMounted(() => {
    darkMode.value = document.documentElement.dataset.theme === 'dark';
    loadSync();
});
</script>

<template>
    <PageHeader title="Settings" subtitle="Account security and store connectivity">
        <label class="theme-toggle">
            <input v-model="darkMode" type="checkbox" @change="applyTheme">
            <span class="theme-switch" aria-hidden="true"><i></i></span>
            <span>{{ darkMode ? 'Dark mode' : 'Light mode' }}</span>
        </label>
    </PageHeader>
    <p v-if="auth.user.must_change_password" class="notice">An administrator issued a temporary password. Change it now before continuing normal work.</p>
    <div class="two-col">
        <section class="panel profile">
            <div class="settings-section-title"><span class="settings-icon" aria-hidden="true">●</span><h2>Profile</h2></div>
            <img src="/media/Nenial.jpg">
            <h3>{{ auth.user.name }}</h3><p>{{ auth.user.email }}</p>
            <div class="profile-details">
                <div><span class="profile-detail-icon" aria-hidden="true">▣</span><span>Role</span><strong>{{ auth.user.role === 'admin' ? 'Administrator' : auth.user.role }}</strong></div>
                <div><span class="profile-detail-icon" aria-hidden="true">◇</span><span>Account status</span><b>Active</b></div>
            </div>
        </section>
        <form class="panel stack" @submit.prevent="save">
            <div class="panel-head settings-form-head"><div><span class="settings-icon" aria-hidden="true">▣</span><h2>Change password</h2></div></div>
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
        <div class="panel-head settings-sync-head"><div><span class="settings-icon" aria-hidden="true">♧</span><span><h2>Store synchronization</h2><small>{{ sync.enabled ? `Local node: ${sync.node_id}` : 'Cloud deployment' }}</small></span></div><span class="tag" :class="{ warn: sync.conflicts || !sync.online }">{{ sync.enabled ? (sync.online ? 'Connected' : 'Offline') : 'Cloud mode' }}</span></div>
        <div class="sync-grid">
            <div><i class="sync-icon" aria-hidden="true">◷</i><span><small>Pending events</small><strong>{{ sync.pending }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">△</i><span><small>Open conflicts</small><strong>{{ sync.conflicts }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">♙</i><span><small>Accounts & workforce</small><strong>{{ sync.accounts_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">▤</i><span><small>Devices</small><strong>{{ sync.devices_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">◉</i><span><small>Face enrollments</small><strong>{{ sync.face_enrollments_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">◇</i><span><small>Inventory activity</small><strong>{{ sync.activity_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">✓</i><span><small>Order fulfillment</small><strong>{{ sync.orders_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">◷</i><span><small>Attendance</small><strong>{{ sync.attendance_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">▧</i><span><small>Payroll snapshots</small><strong>{{ sync.payroll_synced ? 'Synchronized' : 'Awaiting cloud update' }}</strong></span></div>
            <div><i class="sync-icon" aria-hidden="true">☁</i><span><small>Last synchronized</small><strong>{{ sync.last_synced_at ? new Date(sync.last_synced_at).toLocaleString() : 'Not yet' }}</strong></span></div>
            <button v-if="sync.enabled" class="btn primary" :disabled="syncing" @click="runSync">{{ syncing ? 'Synchronizing…' : 'Synchronize now' }}</button>
        </div>
        <p v-if="syncMessage || sync.message" class="notice">{{ syncMessage || sync.message }}</p>
    </section>
</template>

<style scoped>
.theme-toggle { display: flex; align-items: center; justify-content: flex-end; gap: .65rem; width: max-content; color: var(--ink); font-size: .82rem; white-space: nowrap; cursor: pointer; }.theme-toggle input { position: absolute; width: 1px; min-height: 1px; opacity: 0; }.theme-switch { position: relative; width: 44px; height: 24px; border-radius: 999px; background: #bdcbc2; transition: background .18s; }.theme-switch i { position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.18); transition: transform .18s; }.theme-toggle input:checked + .theme-switch { background: var(--brand); }.theme-toggle input:checked + .theme-switch i { transform: translateX(20px); }.theme-toggle input:focus-visible + .theme-switch { outline: 3px solid rgba(23,107,67,.25); outline-offset: 3px; }
.settings-section-title,.settings-form-head>div,.settings-sync-head>div{display:flex;align-items:center;gap:.7rem}.settings-section-title{width:100%;margin-bottom:16px;align-self:start}.settings-section-title h2{margin:0;font-size:1rem}.settings-icon{display:grid;width:38px;height:38px;place-items:center;border-radius:10px;color:var(--brand);background:#e5f4eb;font-size:1.05rem;font-weight:850}.profile h3{margin:14px 0 4px;font-size:1.15rem}.profile-details{display:grid;gap:14px;width:100%;margin-top:20px;padding-top:20px;border-top:1px solid var(--line);text-align:left}.profile-details>div{display:grid;grid-template-columns:26px 1fr auto;align-items:center;gap:9px;font-size:.82rem}.profile-detail-icon{display:grid;width:22px;height:22px;place-items:center;color:var(--brand);font-style:normal;font-weight:850}.profile-details strong{font-size:.82rem;font-weight:700;text-transform:capitalize}.profile-details b{padding:.3rem .55rem;border-radius:999px;color:#12623b;background:#e4f5ea;font-size:.72rem}.settings-form-head{padding:0 0 14px;background:transparent!important}.settings-form-head h2{margin:0}.settings-sync-head>div>span:last-child{display:grid;gap:3px}.settings-sync-head h2{margin:0}.settings-sync-head small{color:var(--muted)}.sync-panel .sync-grid{grid-template-columns:repeat(5,minmax(0,1fr))!important;grid-auto-rows:112px}.sync-grid>div{display:flex!important;align-items:center;gap:11px;height:112px;padding:15px 13px;border:1px solid #e1ece5;border-radius:10px;background:#fcfefd}.sync-grid>div>span{display:grid;gap:4px}.sync-grid>div small{color:var(--muted);font-size:.71rem}.sync-icon{display:grid;flex:0 0 35px;width:35px;height:35px;place-items:center;border-radius:10px;color:var(--brand);background:#e7f5ec;font-size:1rem;font-style:normal;font-weight:850}.sync-grid>div strong{font-size:.82rem}@media(max-width:1100px){.sync-panel .sync-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important}}@media(max-width:700px){.sync-panel .sync-grid{grid-template-columns:1fr!important;grid-auto-rows:auto}.sync-grid>div{height:auto;min-height:88px}}
:global(html[data-theme="dark"]) .sync-panel{border-color:#365a46!important;background:linear-gradient(145deg,#142f22,#10271c)!important;box-shadow:0 20px 44px rgba(0,0,0,.22)!important}:global(html[data-theme="dark"]) .sync-panel .panel-head{border-color:#365a46!important;background:linear-gradient(90deg,#193927,#163222)!important}:global(html[data-theme="dark"]) .sync-panel .settings-icon{color:#8ce6ae;background:#244c35}:global(html[data-theme="dark"]) .sync-panel .sync-grid>div{border-color:#355b45;background:linear-gradient(145deg,#1c392a,#193425);box-shadow:inset 0 1px rgba(255,255,255,.025)}:global(html[data-theme="dark"]) .sync-panel .sync-grid>div small{color:#acc9b7}:global(html[data-theme="dark"]) .sync-panel .sync-grid>div strong{color:#edf8f0}:global(html[data-theme="dark"]) .sync-panel .sync-icon{color:#8ce6ae;background:#28533a}:global(html[data-theme="dark"]) .sync-panel .tag{color:#b9efca;background:#25563b}
</style>
