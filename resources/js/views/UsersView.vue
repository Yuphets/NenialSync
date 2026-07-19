<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const users = ref([]);
const tickets = ref([]);
const search = ref("");
const userPage = ref(1);
const userPageSize = ref(5);
const ticketPage = ref(1);
const ticketPageSize = ref(5);
const message = ref("");
const selected = ref(null);
const accessTarget = ref(null);
const eraseTarget = ref(null);
const showPasswords = ref(false);
const resetForm = reactive({
    ticket_id: "",
    current_password: "",
    password: "",
    password_confirmation: "",
});
const accessForm = reactive({ current_password: "", reason: "" });
const eraseForm = reactive({ current_password: "", reason: "", email_confirmation: "", confirmation_phrase: "" });
const filtered = computed(() =>
    users.value.filter((user) =>
        `${user.name} ${user.email}`
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
const pagedUsers = computed(() =>
    filtered.value.slice(
        (userPage.value - 1) * userPageSize.value,
        userPage.value * userPageSize.value,
    ),
);
const pagedTickets = computed(() =>
    tickets.value.slice(
        (ticketPage.value - 1) * ticketPageSize.value,
        ticketPage.value * ticketPageSize.value,
    ),
);

async function load() {
    [users.value, tickets.value] = await Promise.all([
        axios.get("/api/users").then((response) => response.data),
        axios.get("/api/password-tickets").then((response) => response.data),
    ]);
}

async function role(user) {
    await axios.put(`/api/users/${user.id}/role`, { role: user.role });
    message.value = "Role updated.";
}

function openAccess(user, restore = false) {
    if (user.id === auth.user.id) return;
    accessTarget.value = { user, restore };
    Object.assign(accessForm, { current_password: "", reason: "" });
}

async function changeAccess() {
    const { user, restore } = accessTarget.value;
    try {
        if (restore)
            await axios.put(`/api/users/${user.id}/restore`, accessForm);
        else await axios.delete(`/api/users/${user.id}`, { data: accessForm });
        message.value = restore
            ? `Access restored for ${user.name}.`
            : `Access disabled for ${user.name}; active sessions were signed out.`;
        accessTarget.value = null;
        await load();
    } catch (error) {
        message.value =
            error.response?.data?.message ||
            Object.values(error.response?.data?.errors || {})[0]?.[0] ||
            "Unable to change account access.";
    }
}

function openErase(user) {
    eraseTarget.value = user;
    Object.assign(eraseForm, { current_password: "", reason: "", email_confirmation: "", confirmation_phrase: "" });
}

async function eraseAccount() {
    try {
        await axios.post(`/api/users/${eraseTarget.value.id}/erase`, eraseForm);
        message.value = "The account and identifying profile data were permanently erased. Historical business records remain anonymized.";
        eraseTarget.value = null;
        await load();
    } catch (error) {
        message.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || "Account erasure failed.";
    }
}

function openReset(ticket) {
    if (!ticket.user) {
        message.value = `No account currently matches ${ticket.email}.`;
        return;
    }
    selected.value = { ticket, user: ticket.user };
    Object.assign(resetForm, {
        ticket_id: ticket.id,
        current_password: "",
        password: "",
        password_confirmation: "",
    });
}

async function resetPassword() {
    try {
        const { data } = await axios.post(
            `/api/users/${selected.value.user.id}/password-reset`,
            resetForm,
        );
        message.value = data.message;
        selected.value = null;
        await load();
    } catch (error) {
        message.value =
            error.response?.data?.message ||
            Object.values(error.response?.data?.errors || {})[0]?.[0] ||
            "Password reset failed.";
    }
}

onMounted(load);
</script>

<template>
    <PageHeader
        title="User access"
        subtitle="Manage roles and ticket-based password assistance"
    />
    <p v-if="message" class="notice">{{ message }}</p>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Password assistance tickets</h2>
                <small
                    >Verify the requester before issuing a temporary
                    password.</small
                >
            </div>
            <span class="tag">{{ tickets.length }} open</span>
        </div>
        <div v-if="!tickets.length" class="empty">
            No open password tickets.
        </div>
        <div v-else class="ticket-list">
            <article v-for="ticket in pagedTickets" :key="ticket.id" class="device">
                <div>
                    <strong>{{ ticket.user?.name || ticket.email }}</strong
                    ><small
                        >{{ ticket.ticket_number }} ·
                        {{
                            new Date(ticket.requested_at).toLocaleString()
                        }}</small
                    >
                    <p>{{ ticket.reason }}</p>
                </div>
                <button
                    class="btn tiny primary"
                    :disabled="!ticket.user"
                    @click="openReset(ticket)"
                >
                    Reset password
                </button>
            </article>
        </div>
        <TablePager
            v-if="tickets.length"
            v-model:page="ticketPage"
            v-model:page-size="ticketPageSize"
            :total="tickets.length"
            label="tickets"
        />
    </section>

    <section class="panel">
        <div class="filters">
            <label
                >Search users<input
                    v-model="search"
                    placeholder="Name or email"
            /></label>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in pagedUsers" :key="user.id">
                        <td data-label="User">
                            <strong>{{ user.name }}</strong
                            ><small v-if="user.must_change_password"
                                >Password change required</small
                            >
                        </td>
                        <td data-label="Email">{{ user.email }}</td>
                        <td data-label="Role">
                            <select
                                v-model="user.role"
                                :disabled="
                                    user.id === auth.user.id || !user.is_active
                                "
                                @change="role(user)"
                            >
                                <option>admin</option>
                                <option>assistant</option>
                                <option>cashier</option>
                                <option>user</option>
                            </select>
                        </td>
                        <td data-label="Status">
                            <span
                                class="tag"
                                :class="{ warn: !user.is_active }"
                                >{{
                                    user.is_active ? "Active" : "Disabled"
                                }}</span
                            >
                        </td>
                        <td data-label="Action">
                            <button
                                v-if="user.is_active"
                                class="btn tiny danger"
                                :disabled="user.id === auth.user.id"
                                @click="openAccess(user, false)"
                            >
                                {{
                                    user.id === auth.user.id
                                        ? "Current account"
                                        : "Remove access"
                                }}
                            </button>
                            <div v-else class="actions"><button class="btn tiny primary" @click="openAccess(user, true)">Grant access again</button><button class="btn tiny danger" @click="openErase(user)">Permanently erase</button></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePager
            v-model:page="userPage"
            v-model:page-size="userPageSize"
            :total="filtered.length"
            label="users"
        />
    </section>

    <div v-if="eraseTarget" class="modal"><form class="modal-card" @submit.prevent="eraseAccount"><div class="panel-head"><div><h2>Permanently erase account</h2><small>{{ eraseTarget.name }} · {{ eraseTarget.email }}</small></div><button type="button" class="btn ghost" @click="eraseTarget = null">Close</button></div><p class="error">This cannot be undone. Personal profile and login data will be anonymized permanently. Historical orders and audit records remain for company accounting.</p><label>Reason<textarea v-model="eraseForm.reason" rows="3" minlength="10" required></textarea></label><label>Type the account email<input v-model="eraseForm.email_confirmation" type="email" required></label><label>Type PERMANENTLY ERASE<input v-model="eraseForm.confirmation_phrase" required></label><label>Your administrator password<input v-model="eraseForm.current_password" type="password" autocomplete="current-password" required></label><button class="btn danger full">Permanently erase this account</button></form></div>

    <div v-if="accessTarget" class="modal">
        <form class="modal-card" @submit.prevent="changeAccess">
            <div class="panel-head">
                <div>
                    <h2>
                        {{
                            accessTarget.restore
                                ? "Grant access again"
                                : "Disable user access"
                        }}
                    </h2>
                    <small
                        >{{ accessTarget.user.name }} ·
                        {{ accessTarget.user.email }}</small
                    >
                </div>
                <button
                    type="button"
                    class="btn ghost"
                    @click="accessTarget = null"
                >
                    Close
                </button>
            </div>
            <p>
                {{
                    accessTarget.restore
                        ? "The user will be able to sign in again immediately."
                        : "The user will be signed out on every device and prevented from signing in until access is restored."
                }}
            </p>
            <label
                >Reason<textarea
                    v-model="accessForm.reason"
                    rows="3"
                    :placeholder="
                        accessTarget.restore
                            ? 'Why is access being restored?'
                            : 'Why is access being disabled?'
                    "
                    required
                ></textarea>
            </label>
            <label
                >Your administrator password<input
                    v-model="accessForm.current_password"
                    type="password"
                    autocomplete="current-password"
                    required
            /></label>
            <button
                class="btn full"
                :class="accessTarget.restore ? 'primary' : 'danger'"
            >
                {{
                    accessTarget.restore
                        ? "Confirm and grant access"
                        : "Confirm and disable access"
                }}
            </button>
        </form>
    </div>

    <div v-if="selected" class="modal">
        <form class="modal-card" @submit.prevent="resetPassword">
            <div class="panel-head">
                <div>
                    <h2>Issue temporary password</h2>
                    <small
                        >{{ selected.user.name }} ·
                        {{ selected.ticket.ticket_number }}</small
                    >
                </div>
                <button
                    type="button"
                    class="btn ghost"
                    @click="selected = null"
                >
                    Close
                </button>
            </div>
            <label
                >Your administrator password<input
                    v-model="resetForm.current_password"
                    :type="showPasswords ? 'text' : 'password'"
                    autocomplete="current-password"
                    required
            /></label>
            <label
                >Temporary password<input
                    v-model="resetForm.password"
                    :type="showPasswords ? 'text' : 'password'"
                    autocomplete="new-password"
                    minlength="8"
                    required
            /></label>
            <label
                >Confirm temporary password<input
                    v-model="resetForm.password_confirmation"
                    :type="showPasswords ? 'text' : 'password'"
                    autocomplete="new-password"
                    required
            /></label>
            <label class="password-toggle"
                ><input v-model="showPasswords" type="checkbox" /><span
                    >Show passwords</span
                ></label
            >
            <small
                >The user will be required to replace this password after
                signing in.</small
            >
            <button class="btn primary">Apply temporary password</button>
        </form>
    </div>
</template>
