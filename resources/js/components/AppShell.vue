<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import axios from "axios";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const isCashier = computed(() => auth.role === "cashier");
const sidebarCollapsed = ref(readSidebarPreference());
const navigation = ref(null);
const maintenance = ref(null);
const links = computed(() =>
    [
        ["Dashboard", "/app/dashboard", "▦"],
        ["POS Terminal", "/app/pos", "▣"],
        ["Inventory", "/app/inventory", "▤"],
        ["Orders", "/app/orders", "▥"],
        ["Workforce", "/app/workforce", "◉"],
        ["Reports", "/app/reports", "▧"],
        ["Users", "/app/users", "◉"],
        ["Devices", "/app/devices", "⌁"],
        ["Settings", "/app/settings", "⚙"],
    ].filter(
        ([name]) =>
            ({
            admin: true,
            assistant: !["POS Terminal", "Users", "Devices"].includes(name),
            user: ["Dashboard", "Orders", "Settings"].includes(name),
            })[auth.role],
    ),
);

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    try {
        localStorage.setItem(
            "nenial.sidebar.collapsed.v1",
            sidebarCollapsed.value ? "1" : "0",
        );
    } catch {
        // The navigation still works when browser storage is unavailable.
    }
}

function readSidebarPreference() {
    try {
        return localStorage.getItem("nenial.sidebar.collapsed.v1") === "1";
    } catch {
        return false;
    }
}

async function loadMaintenance() {
    try {
        maintenance.value = (await axios.get("/api/system/status")).data;
    } catch {
        maintenance.value = null;
    }
}

function maintenanceChanged(event) {
    maintenance.value = event.detail;
}

function revealActiveNavigation() {
    nextTick(() => {
        navigation.value
            ?.querySelector(".router-link-active")
            ?.scrollIntoView({ block: "nearest", inline: "nearest" });
    });
}

async function logout() {
    await auth.logout();
    router.push("/");
}

onMounted(() => {
    loadMaintenance();
    revealActiveNavigation();
    window.addEventListener("nenial:maintenance-changed", maintenanceChanged);
});
watch(() => route.fullPath, revealActiveNavigation, { flush: "post" });
onBeforeUnmount(() =>
    window.removeEventListener(
        "nenial:maintenance-changed",
        maintenanceChanged,
    ),
);
</script>

<template>
    <div v-if="isCashier" class="cashier-shell">
        <header class="cashier-topbar">
            <RouterLink class="brand" to="/app/pos">
                <img src="/media/Nenial.jpg" alt="Nenial" />
                <span>Nenial<small>Point of Sale</small></span>
            </RouterLink>
            <div class="cashier-session">
                <span><small>Signed in as</small><strong>{{ auth.user.name }}</strong></span>
                <RouterLink class="btn ghost" to="/app/settings">Settings</RouterLink>
                <button class="btn ghost" @click="logout">Sign out</button>
            </div>
        </header>
        <main class="cashier-workspace"><RouterView /></main>
    </div>
    <div
        v-else
        class="shell"
        :class="{ 'sidebar-collapsed': sidebarCollapsed }"
    >
        <aside id="app-sidebar" class="sidebar">
            <div class="sidebar-brand-row">
                <RouterLink class="brand" to="/" title="Nenial storefront">
                    <img src="/media/Nenial.jpg" alt="Nenial" />
                    <span>Nenial<small>Operations</small></span>
                </RouterLink>
                <button
                    class="sidebar-toggle"
                    type="button"
                    :aria-pressed="sidebarCollapsed"
                    aria-controls="app-sidebar"
                    :aria-label="
                        sidebarCollapsed
                            ? 'Expand navigation'
                            : 'Collapse navigation'
                    "
                    :title="
                        sidebarCollapsed
                            ? 'Expand navigation'
                            : 'Collapse navigation'
                    "
                    @click="toggleSidebar"
                >
                    {{ sidebarCollapsed ? "›" : "‹" }}
                </button>
            </div>
            <div class="identity">
                <strong>{{ auth.user.name }}</strong>
                <span>{{ auth.user.email }}</span>
                <b>{{ auth.role }}</b>
            </div>
            <nav ref="navigation">
                <RouterLink
                    v-for="link in links"
                    :key="link[1]"
                    :to="link[1]"
                    :title="link[0]"
                    :aria-label="link[0]"
                >
                    <i aria-hidden="true">{{ link[2] }}</i
                    ><span class="nav-label">{{ link[0] }}</span>
                </RouterLink>
            </nav>
            <button
                class="btn ghost logout"
                title="Sign out"
                aria-label="Sign out"
                @click="logout"
            >
                <i aria-hidden="true">↪</i
                ><span class="nav-label">Sign out</span>
            </button>
        </aside>
        <main class="workspace">
            <div
                v-if="auth.role === 'admin' && maintenance?.enabled"
                class="maintenance-admin-banner"
                role="status"
                aria-live="polite"
            >
                <span
                    ><strong>Maintenance mode is active.</strong>
                    Customers and non-admin staff are currently blocked.</span
                >
                <RouterLink class="btn tiny light" to="/app/settings"
                    >Manage maintenance</RouterLink
                >
            </div>
            <RouterView />
        </main>
    </div>
</template>
