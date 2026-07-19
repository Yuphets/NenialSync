<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const data = ref({});
const activityFilters = reactive({ search: "", from: "", to: "" });
const activityPage = ref(1);
const activityPageSize = ref(5);
let refreshTimer;
let loading = false;

const hasActivityFilters = computed(
    () => activityFilters.search || activityFilters.from || activityFilters.to,
);
const pagedMovements = computed(() =>
    (data.value.latest_movements || []).slice(
        (activityPage.value - 1) * activityPageSize.value,
        activityPage.value * activityPageSize.value,
    ),
);

async function load() {
    if (loading) return;
    loading = true;
    try {
        data.value = (
            await axios.get("/api/dashboard", {
                params: {
                    activity_search: activityFilters.search || undefined,
                    activity_from: activityFilters.from || undefined,
                    activity_to: activityFilters.to || undefined,
                },
            })
        ).data;
    } catch {
        // Keep the last good snapshot visible during a brief connection loss.
    } finally {
        loading = false;
    }
}

function clearActivityFilters() {
    Object.assign(activityFilters, { search: "", from: "", to: "" });
    activityPage.value = 1;
    load();
}

onMounted(() => {
    load();
    refreshTimer = window.setInterval(load, 3000);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));
</script>

<template>
    <PageHeader
        :title="data.customer_view ? 'Customer dashboard' : 'Operations overview'"
        :subtitle="
            data.customer_view
                ? 'Track orders and return to the live storefront'
                : `Live company status for ${auth.user.name}`
        "
    />
    <div class="stat-grid">
        <article class="stat">
            <span>Available catalog</span><strong>{{ data.products || 0 }}</strong
            ><small>{{ data.customer_view ? "Live products" : "Tracked SKUs" }}</small>
        </article>
        <article class="stat">
            <span>{{ data.customer_view ? "My open orders" : "Sales today" }}</span>
            <strong v-if="data.customer_view">{{ data.orders_pending || 0 }}</strong>
            <strong v-else>₱{{ Number(data.sales_today || 0).toLocaleString() }}</strong>
            <small>{{ data.customer_view ? "Preparing or in delivery" : "Completed POS transactions" }}</small>
        </article>
        <article v-if="!data.customer_view" class="stat">
            <span>Open orders</span><strong>{{ data.orders_pending || 0 }}</strong><small>Reserved inventory</small>
        </article>
        <article v-if="!data.customer_view" class="stat">
            <span>Employees</span><strong>{{ data.employees || 0 }}</strong><small>Active workforce</small>
        </article>
        <article v-else class="stat">
            <span>Shop</span><RouterLink class="btn primary" to="/">Browse products</RouterLink><small>Current inventory</small>
        </article>
    </div>
    <section v-if="!data.customer_view" class="panel table-wrap">
        <div class="panel-head">
            <div><h2>Live inventory activity</h2><small>Search by product, SKU, movement type, reason, or product ID</small></div>
            <span class="live">● Monitoring</span>
        </div>
        <div class="filters inline-filters">
            <label>Search activity<input v-model="activityFilters.search" placeholder="Cement, CON-001, sale, reservation" /></label>
            <label>From<input v-model="activityFilters.from" type="date" /></label>
            <label>To<input v-model="activityFilters.to" type="date" /></label>
            <div class="actions">
                <button class="btn primary" @click="activityPage = 1; load()">Apply</button>
                <button v-if="hasActivityFilters" class="btn" @click="clearActivityFilters">Clear</button>
            </div>
        </div>
        <TablePager
            v-if="data.latest_movements?.length"
            v-model:page="activityPage"
            v-model:page-size="activityPageSize"
            :total="data.latest_movements.length"
            label="inventory movements"
        />
        <div v-if="!data.latest_movements?.length" class="empty">No inventory movements found for this filter.</div>
        <table v-else>
            <thead><tr><th>Time</th><th>Product</th><th>Type</th><th>Stock change</th><th>Reserved change</th></tr></thead>
            <tbody>
                <tr v-for="movement in pagedMovements" :key="movement.id">
                    <td data-label="Time">{{ new Date(movement.created_at).toLocaleString("en-US", { timeZone: "Asia/Manila" }) }}</td>
                    <td data-label="Product"><strong>{{ movement.product_name || `#${movement.product_id}` }}</strong><small>{{ movement.product_sku || `Product #${movement.product_id}` }}</small></td>
                    <td data-label="Type">{{ movement.type }}</td>
                    <td data-label="Stock change">{{ movement.quantity_delta }}</td>
                    <td data-label="Reserved change">{{ movement.reserved_delta }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
