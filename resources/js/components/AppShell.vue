<script setup>
import { computed } from "vue";
import { RouterLink, RouterView, useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const router = useRouter();
const isCashier = computed(() => auth.role === "cashier");
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

async function logout() {
    await auth.logout();
    router.push("/");
}
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
    <div v-else class="shell">
        <aside class="sidebar">
            <RouterLink class="brand" to="/">
                <img src="/media/Nenial.jpg" alt="Nenial" />
                <span>Nenial<small>Operations</small></span>
            </RouterLink>
            <div class="identity">
                <strong>{{ auth.user.name }}</strong>
                <span>{{ auth.user.email }}</span>
                <b>{{ auth.role }}</b>
            </div>
            <nav>
                <RouterLink v-for="link in links" :key="link[1]" :to="link[1]">
                    <i>{{ link[2] }}</i>{{ link[0] }}
                </RouterLink>
            </nav>
            <button class="btn ghost logout" @click="logout">Sign out</button>
        </aside>
        <main class="workspace"><RouterView /></main>
    </div>
</template>
