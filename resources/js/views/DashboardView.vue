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
    <div class="dashboard-view">
        <PageHeader
            :title="data.customer_view ? 'Customer dashboard' : 'Operations overview'"
            :subtitle="data.customer_view ? 'Track orders and return to the live storefront' : `Live company status for ${auth.user.name}`"
        ><span v-if="!data.customer_view" class="dashboard-status"><i></i> Live overview</span></PageHeader>
        <section class="dashboard-intro">
            <div>
                <span class="dashboard-kicker">{{ data.customer_view ? 'Your account at a glance' : 'Business pulse' }}</span>
                <h2>{{ data.customer_view ? 'Everything you need, in one place.' : 'Keep your operations moving.' }}</h2>
                <p>{{ data.customer_view ? 'Review active orders or continue exploring what is in stock.' : 'A focused view of today’s sales, orders, inventory, and team.' }}</p>
            </div>
            <RouterLink v-if="data.customer_view" class="btn light dashboard-cta" to="/">Browse products <span aria-hidden="true">→</span></RouterLink>
            <div v-else class="dashboard-mark" aria-hidden="true"><span></span><span></span><span></span></div>
        </section>
        <div class="stat-grid dashboard-stats">
            <article class="stat dashboard-stat"><span class="stat-icon catalog-icon" aria-hidden="true">□</span><div><span>Available catalog</span><strong>{{ data.products || 0 }}</strong><small>{{ data.customer_view ? "Live products" : "Tracked SKUs" }}</small></div></article>
            <article class="stat dashboard-stat featured-stat"><span class="stat-icon sales-icon" aria-hidden="true">↗</span><div><span>{{ data.customer_view ? "My open orders" : "Sales today" }}</span><strong v-if="data.customer_view">{{ data.orders_pending || 0 }}</strong><strong v-else>₱{{ Number(data.sales_today || 0).toLocaleString() }}</strong><small>{{ data.customer_view ? "Preparing or in delivery" : "Completed POS transactions" }}</small></div></article>
            <article v-if="!data.customer_view" class="stat dashboard-stat"><span class="stat-icon orders-icon" aria-hidden="true">◌</span><div><span>Open orders</span><strong>{{ data.orders_pending || 0 }}</strong><small>Reserved inventory</small></div></article>
            <article v-if="!data.customer_view" class="stat dashboard-stat"><span class="stat-icon team-icon" aria-hidden="true">♧</span><div><span>Employees</span><strong>{{ data.employees || 0 }}</strong><small>Active workforce</small></div></article>
            <article v-else class="stat dashboard-stat"><span class="stat-icon shop-icon" aria-hidden="true">⌂</span><div><span>Shop</span><strong>Browse</strong><small>Current inventory</small></div></article>
        </div>
    <section v-if="!data.customer_view" class="panel table-wrap dashboard-activity">
        <div class="panel-head">
            <div><span class="dashboard-kicker">Inventory</span><h2>Live inventory activity</h2><small>Search by product, SKU, movement type, reason, or product ID</small></div>
            <span class="live"><i></i> Monitoring</span>
        </div>
        <div class="filters inline-filters dashboard-filters">
            <label>Search activity<input v-model="activityFilters.search" placeholder="Cement, CON-001, sale…" /></label>
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
    </div>
</template>

<style scoped>
.dashboard-view { max-width: 1600px; margin: 0 auto; }
.dashboard-view :deep(.page-header) { margin-bottom: 16px; }
.dashboard-status, .live { display: inline-flex; align-items: center; gap: .45rem; padding: .48rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 800; }
.dashboard-status { border: 1px solid #cfe5d7; color: var(--dark); background: #f7fcf8; }.dashboard-status i, .live i { width: 7px; height: 7px; border-radius: 50%; background: #45a36d; box-shadow: 0 0 0 4px rgba(69,163,109,.12); }
.dashboard-intro { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; min-height: 174px; margin-bottom: 18px; padding: 28px 32px; overflow: hidden; border-radius: 16px; color: #f7fff9; background: linear-gradient(112deg, #0d3221, #176b43); box-shadow: 0 18px 40px rgba(13,62,40,.18); }.dashboard-intro > div:first-child { position: relative; z-index: 1; max-width: 650px; }.dashboard-kicker { display: block; margin-bottom: .4rem; color: #6fbc90; font-size: .68rem; font-weight: 850; letter-spacing: .12em; text-transform: uppercase; }.dashboard-intro .dashboard-kicker { color: #9dd8b5; }.dashboard-intro h2 { margin: 0; font-size: clamp(1.3rem,2.4vw,1.8rem); letter-spacing: -.035em; }.dashboard-intro p { max-width: 570px; margin: .55rem 0 0; color: #cfe5d7; font-size: .88rem; line-height: 1.55; }.dashboard-cta { position: relative; z-index: 1; flex: 0 0 auto; color: var(--dark); border: 0; font-size: .82rem; }.dashboard-cta span { font-size: 1.1rem; }
.dashboard-mark { position: absolute; right: 34px; bottom: -35px; display: flex; align-items: end; gap: 10px; height: 155px; opacity: .25; }.dashboard-mark span { display: block; width: 32px; border-radius: 10px 10px 0 0; background: #bcefd0; }.dashboard-mark span:nth-child(1) { height: 57px; }.dashboard-mark span:nth-child(2) { height: 104px; }.dashboard-mark span:nth-child(3) { height: 143px; }
.dashboard-stats { grid-template-columns: repeat(4,minmax(0,1fr)); }.dashboard-stat { display: flex; align-items: flex-start; gap: 13px; min-height: 132px; padding: 18px; border: 1px solid #d5e9dc; border-top: 3px solid var(--brand); background: linear-gradient(145deg,#fff 18%,#eef8f2); box-shadow: 0 12px 28px rgba(13,50,33,.13), 0 2px 6px rgba(13,50,33,.05) !important; transition: transform .18s ease, box-shadow .18s ease; }.dashboard-stat:hover { transform: translateY(-2px); box-shadow: 0 18px 34px rgba(13,50,33,.16), 0 3px 8px rgba(13,50,33,.06) !important; }.dashboard-stat > div { display: grid; gap: .28rem; min-width: 0; }.dashboard-stat strong { font-size: clamp(1.45rem,2.1vw,1.9rem); letter-spacing: -.04em; }.dashboard-stat small { line-height: 1.35; }.featured-stat { border-top-color: #0d5635; background: linear-gradient(145deg,#fff,#e6f5eb); }.stat-icon { display: grid; flex: 0 0 34px; width: 34px; height: 34px; place-items: center; border-radius: 10px; color: var(--brand); background: #dff2e6; font-size: 1.15rem; font-weight: 800; box-shadow: inset 0 0 0 1px rgba(23,107,67,.06); }.sales-icon { color: #fff; background: var(--brand); }.orders-icon { color: var(--brand); background: #dff2e6; }.team-icon { color: var(--brand); background: #dff2e6; }.shop-icon { color: var(--brand); background: #dff2e6; }
.dashboard-activity { position: relative; margin-bottom: 0; border-color: #cfe5d7; box-shadow: 0 16px 34px rgba(13,50,33,.1) !important; }.dashboard-activity::before { position: absolute; top: 0; right: 0; left: 0; z-index: 2; height: 4px; content: ''; background: linear-gradient(90deg,#0d5635,#4fa875 55%,#d8eddf); }.dashboard-activity .panel-head { padding: 20px 24px 18px; background: linear-gradient(105deg,#f3fbf6,#fff 70%) !important; }.dashboard-activity .panel-head h2 { margin: 0 0 .22rem; color: #123d29; }.dashboard-activity .panel-head small { display: block; }.dashboard-activity .dashboard-kicker { margin-bottom: .3rem; }.live { color: var(--brand); background: #dff3e6; }.dashboard-filters { padding: 18px 22px !important; border-bottom: 1px solid #e2eee6; background: #f8fcf9 !important; }
@media (max-width:1050px) { .dashboard-stats { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width:700px) { .dashboard-status { display: none; }.dashboard-intro { align-items: flex-start; flex-direction: column; min-height: 0; padding: 24px 20px; }.dashboard-mark { right: 18px; }.dashboard-cta { width: 100%; }.dashboard-stats { grid-template-columns: 1fr; }.dashboard-stat { min-height: 112px; } }
</style>
