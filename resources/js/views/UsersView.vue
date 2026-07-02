<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const users = ref([]);
const tickets = ref([]);
const search = ref('');
const message = ref('');
const selected = ref(null);
const showPasswords = ref(false);
const resetForm = reactive({ ticket_id: '', current_password: '', password: '', password_confirmation: '' });
const filtered = computed(() => users.value.filter(user => `${user.name} ${user.email}`.toLowerCase().includes(search.value.toLowerCase())));

async function load() {
    [users.value, tickets.value] = await Promise.all([
        axios.get('/api/users').then(response => response.data),
        axios.get('/api/password-tickets').then(response => response.data),
    ]);
}

async function role(user) {
    await axios.put(`/api/users/${user.id}/role`, { role: user.role });
    message.value = 'Role updated.';
}

async function remove(user) {
    if (user.id === auth.user.id) return;
    if (confirm(`Remove access for ${user.name}?`)) {
        try {
            await axios.delete(`/api/users/${user.id}`);
            await load();
        } catch (error) {
            message.value = error.response?.data?.message || 'Unable to remove account.';
        }
    }
}

function openReset(ticket) {
    if (!ticket.user) {
        message.value = `No account currently matches ${ticket.email}.`;
        return;
    }
    selected.value = { ticket, user: ticket.user };
    Object.assign(resetForm, { ticket_id: ticket.id, current_password: '', password: '', password_confirmation: '' });
}

async function resetPassword() {
    try {
        const { data } = await axios.post(`/api/users/${selected.value.user.id}/password-reset`, resetForm);
        message.value = data.message;
        selected.value = null;
        await load();
    } catch (error) {
        message.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'Password reset failed.';
    }
}

onMounted(load);
</script>

<template>
    <PageHeader title="User access" subtitle="Manage roles and ticket-based password assistance" />
    <p v-if="message" class="notice">{{ message }}</p>

    <section class="panel">
        <div class="panel-head"><div><h2>Password assistance tickets</h2><small>Verify the requester before issuing a temporary password.</small></div><span class="tag">{{ tickets.length }} open</span></div>
        <div v-if="!tickets.length" class="empty">No open password tickets.</div>
        <div v-else class="ticket-list">
            <article v-for="ticket in tickets" :key="ticket.id" class="device">
                <div><strong>{{ ticket.user?.name || ticket.email }}</strong><small>{{ ticket.ticket_number }} · {{ new Date(ticket.requested_at).toLocaleString() }}</small><p>{{ ticket.reason }}</p></div>
                <button class="btn tiny primary" :disabled="!ticket.user" @click="openReset(ticket)">Reset password</button>
            </article>
        </div>
    </section>

    <section class="panel">
        <div class="filters"><label>Search users<input v-model="search" placeholder="Name or email"></label></div>
        <div class="table-wrap"><table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <tr v-for="user in filtered" :key="user.id"><td><strong>{{ user.name }}</strong><small v-if="user.must_change_password">Password change required</small></td><td>{{ user.email }}</td><td><select v-model="user.role" :disabled="user.id === auth.user.id" @change="role(user)"><option>admin</option><option>assistant</option><option>cashier</option><option>user</option></select></td><td><span class="tag" :class="{ warn: !user.is_active }">{{ user.is_active ? 'Active' : 'Disabled' }}</span></td><td><button class="btn tiny danger" :disabled="user.id === auth.user.id" @click="remove(user)">{{ user.id === auth.user.id ? 'Current account' : 'Remove access' }}</button></td></tr>
        </tbody></table></div>
    </section>

    <div v-if="selected" class="modal">
        <form class="modal-card" @submit.prevent="resetPassword">
            <div class="panel-head"><div><h2>Issue temporary password</h2><small>{{ selected.user.name }} · {{ selected.ticket.ticket_number }}</small></div><button type="button" class="btn ghost" @click="selected = null">Close</button></div>
            <label>Your administrator password<input v-model="resetForm.current_password" :type="showPasswords ? 'text' : 'password'" autocomplete="current-password" required></label>
            <label>Temporary password<input v-model="resetForm.password" :type="showPasswords ? 'text' : 'password'" autocomplete="new-password" minlength="8" required></label>
            <label>Confirm temporary password<input v-model="resetForm.password_confirmation" :type="showPasswords ? 'text' : 'password'" autocomplete="new-password" required></label>
            <label class="password-toggle"><input v-model="showPasswords" type="checkbox"><span>Show passwords</span></label>
            <small>The user will be required to replace this password after signing in.</small>
            <button class="btn primary">Apply temporary password</button>
        </form>
    </div>
</template>
