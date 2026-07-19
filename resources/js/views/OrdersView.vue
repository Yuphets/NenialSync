<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const orders = ref([]);
const message = ref("");
const search = ref("");
const page = ref(1);
const pageSize = ref(5);
let refreshTimer;
let searchTimer;
let loading = false;

async function load() {
    if (loading) return;
    loading = true;
    try {
        orders.value = (
            await axios.get("/api/orders", {
                params: { search: search.value.trim() || undefined },
            })
        ).data.data;
    } catch {
        // Keep the last good order list visible during a brief outage.
    } finally {
        loading = false;
    }
}

onMounted(() => {
    load();
    refreshTimer = window.setInterval(load, 3000);
});
onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    window.clearTimeout(searchTimer);
});
watch(search, () => {
    page.value = 1;
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(load, 250);
});

async function status(order, value) {
    try {
        await axios.put(`/api/orders/${order.id}/status`, { status: value });
        await load();
    } catch (error) {
        message.value = error.response?.data?.message || "Update failed.";
    }
}
async function receive(order) {
    if (!confirm("Confirm all materials were received and release payment?")) return;
    await axios.post(`/api/orders/${order.id}/receive`);
    await load();
}
async function cancel(order) {
    if (!confirm("Cancel this order and release reserved inventory?")) return;
    await axios.post(`/api/orders/${order.id}/cancel`);
    await load();
}
async function pay(order) {
    try {
        const { data } = await axios.post(`/api/orders/${order.id}/payment-checkout`, {
            provider: "paymongo",
        });
        window.location.assign(data.payment_url);
    } catch (error) {
        message.value = error.response?.data?.message || "Could not open secure checkout.";
    }
}

const visibleOrders = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return orders.value;
    return orders.value.filter((order) =>
        [
            order.reference,
            order.customer?.name,
            order.customer?.email,
            order.status,
            order.payment_status,
            order.payment_provider || order.payment_method,
            order.total,
            ...(order.items || []).flatMap((item) => [item.product_name, item.sku, item.quantity]),
        ]
            .join(" ")
            .toLowerCase()
            .includes(needle),
    );
});
const pagedOrders = computed(() =>
    visibleOrders.value.slice(
        (page.value - 1) * pageSize.value,
        page.value * pageSize.value,
    ),
);
</script>

<template>
    <PageHeader
        :title="auth.role === 'user' ? 'My orders' : 'Order fulfillment'"
        subtitle="Protected payments and inventory reservations"
    />
    <p v-if="message" class="notice">{{ message }}</p>
    <section class="panel filters inline-filters">
        <label>Search orders<input v-model="search" placeholder="Reference, customer, item, status, payment" /></label>
        <button v-if="search" class="btn" @click="search = ''">Clear search</button>
        <small>{{ visibleOrders.length }} of {{ orders.length }} orders shown</small>
    </section>
    <section class="panel table-wrap">
        <TablePager
            v-model:page="page"
            v-model:page-size="pageSize"
            :total="visibleOrders.length"
            label="orders"
        />
        <table>
            <thead><tr><th>Reference</th><th>Customer</th><th>Items</th><th>Total</th><th>Delivery</th><th>Payment</th><th>Action</th></tr></thead>
            <tbody>
                <tr v-for="order in pagedOrders" :key="order.id">
                    <td data-label="Reference"><strong>{{ order.reference }}</strong><small>{{ new Date(order.created_at).toLocaleString("en-US", { timeZone: "Asia/Manila" }) }}</small></td>
                    <td data-label="Customer">{{ order.customer?.name }}</td>
                    <td data-label="Items">{{ order.items.map((item) => `${item.product_name} × ${item.quantity}`).join(", ") }}</td>
                    <td data-label="Total">₱{{ Number(order.total).toLocaleString() }}</td>
                    <td data-label="Delivery"><span class="tag">{{ order.status }}</span></td>
                    <td data-label="Payment"><span class="tag" :class="{ warn: order.payment_status !== 'paid' }">{{ order.payment_status }}</span><small>{{ order.payment_provider || order.payment_method }}</small></td>
                    <td data-label="Actions"><div class="actions">
                        <button v-if="auth.role === 'user' && order.status === 'preparing' && order.payment_status === 'on_hold'" class="btn tiny primary" @click="pay(order)">Pay securely</button>
                        <button v-if="['admin', 'assistant'].includes(auth.role) && order.status === 'preparing'" class="btn tiny" @click="status(order, 'dispatched')">Dispatch</button>
                        <button v-if="['admin', 'assistant'].includes(auth.role) && order.status === 'dispatched'" class="btn tiny primary" @click="status(order, 'delivered')">Delivered</button>
                        <button v-if="auth.role === 'user' && order.status === 'delivered'" class="btn tiny primary" @click="receive(order)">Confirm receipt</button>
                        <button v-if="['preparing', 'dispatched'].includes(order.status) && !order.paid_at && (auth.role === 'admin' || auth.role === 'user')" class="btn tiny danger" @click="cancel(order)">Cancel</button>
                    </div></td>
                </tr>
                <tr v-if="!visibleOrders.length" class="empty-row"><td colspan="7"><div class="empty">{{ orders.length ? "No orders match your search." : "No orders yet." }}</div></td></tr>
            </tbody>
        </table>
    </section>
</template>
