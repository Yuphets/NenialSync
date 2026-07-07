<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();
const products = ref([]);
const category = ref('All');
const cart = ref(JSON.parse(localStorage.getItem('nenial-cart') || '[]'));
const loading = ref(true);
const notice = ref('');
const paying = ref(false);
const paymentProvider = ref('stripe');
const categories = ['All', 'Materials', 'Aggregates', 'Tools', 'Safety', 'Finishing'];
let checkoutKey = null;

watch(cart, value => localStorage.setItem('nenial-cart', JSON.stringify(value)), { deep: true });
const visible = computed(() => category.value === 'All' ? products.value : products.value.filter(product => product.category === category.value));
const total = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity * (1 - item.discount_percent / 100), 0));
const itemCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));

onMounted(async () => {
    try {
        const payload = (await axios.get('/api/storefront/products')).data;
        if (!Array.isArray(payload?.data)) throw new Error('Invalid product response');
        products.value = payload.data;
    } catch {
        products.value = [];
        notice.value = 'Storefront is temporarily unavailable.';
    } finally { loading.value = false; }
});

function add(product) {
    const item = cart.value.find(value => value.id === product.id);
    const quantity = (item?.quantity || 0) + 1;
    if (quantity > product.available_quantity) return notice.value = 'No more available stock.';
    item ? item.quantity++ : cart.value.push({ ...product, quantity: 1 });
    checkoutKey = null;
    notice.value = `${product.name} added.`;
}

async function checkout() {
    if (!auth.authenticated) return router.push({ path: '/login', query: { redirect: '/' } });
    if (auth.role !== 'user') return notice.value = 'Online checkout requires a customer account.';
    checkoutKey ||= crypto.randomUUID();
    paying.value = true;
    try {
        const { data: order } = await axios.post('/api/orders', { items: cart.value.map(item => ({ product_id: item.id, quantity: item.quantity })), payment_method: paymentProvider.value, idempotency_key: checkoutKey });
        const { data: payment } = await axios.post(`/api/orders/${order.id}/payment-checkout`, { provider: paymentProvider.value });
        cart.value = []; checkoutKey = null;
        window.location.assign(payment.payment_url);
    } catch (error) {
        notice.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'Checkout failed.';
        paying.value = false;
    }
}
</script>

<template>
    <div class="store">
        <header class="store-nav">
            <a class="brand" href="#"><img src="/media/Nenial.jpg" alt="Nenial"><span>Nenial</span></a>
            <nav aria-label="Product categories"><button v-for="item in categories" :key="item" :class="{ active: category === item }" @click="category = item">{{ item }}</button></nav>
            <div class="store-actions">
                <template v-if="auth.authenticated"><RouterLink class="btn ghost" to="/app/dashboard">Dashboard</RouterLink><RouterLink v-if="auth.role === 'user'" class="btn ghost" to="/app/orders">Orders</RouterLink></template>
                <template v-else><RouterLink class="btn ghost" to="/login">Sign in</RouterLink><RouterLink class="btn signup" :to="{ path: '/login', query: { mode: 'register' } }">Sign up</RouterLink></template>
                <button class="btn primary cart-button" :disabled="!cart.length" aria-label="Open cart and checkout" @click="checkout"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 4h2l2.3 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7M10 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg><span>Cart</span><b>{{ itemCount }}</b></button>
            </div>
        </header>
        <section class="store-hero"><div><span class="eyebrow">Construction supply, connected</span><h1>Materials in stock. Operations in sync.</h1><p>Shop live inventory with protected payment and delivery confirmation.</p><div class="hero-actions"><a class="btn light" href="#catalog">Browse catalog</a><RouterLink v-if="!auth.authenticated" class="btn primary" :to="{ path: '/login', query: { mode: 'register' } }">Create an account</RouterLink></div></div></section>
        <main id="catalog" class="catalog"><div class="section-title"><div><span class="eyebrow">{{ category }} catalog</span><h2>Available products</h2></div><strong>{{ products.length }} live SKUs</strong></div><p v-if="notice" class="notice">{{ notice }}</p><div v-if="loading" class="empty">Loading current inventory…</div><div class="product-grid"><article v-for="product in visible" :key="product.id" class="product-card"><div class="product-image"><img :src="product.image_url || '/media/Background.jpg'" :alt="product.name"></div><span class="tag">{{ product.category }}</span><h3>{{ product.name }}</h3><p>{{ product.supplier }} · {{ product.sku }}</p><div class="product-bottom"><strong>₱{{ Number(product.price).toLocaleString() }}</strong><small :class="{ low: product.is_low_stock }">{{ product.available_quantity }} {{ product.unit }}</small></div><button class="btn primary full" :disabled="product.available_quantity < 1" @click="add(product)">{{ product.available_quantity ? 'Add to cart' : 'Out of stock' }}</button></article></div></main>
        <aside v-if="cart.length" class="cart-dock"><div class="cart-dock-summary"><span class="cart-mark"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 4h2l2.3 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7M10 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg></span><div><strong>{{ itemCount }} items</strong><span>₱{{ total.toLocaleString(undefined, { maximumFractionDigits: 2 }) }}</span></div></div><label class="payment-choice"><span>Pay with</span><select v-model="paymentProvider" aria-label="Payment method"><option value="stripe">Credit / debit card</option><option value="gcash">GCash</option><option value="maya">Maya</option></select></label><button class="btn primary" :disabled="paying" @click="checkout">{{ paying ? 'Opening secure checkout…' : 'Secure checkout' }}</button></aside>
    </div>
</template>

<style scoped>
:global(.store) { padding-top: 0 !important; }
:global(.store-nav) {
    position: fixed !important;
    top: 0;
    left: 0;
    right: 0;
    width: 100%;
    z-index: 1000;
    box-shadow: 0 10px 26px rgba(5, 28, 17, .16);
}
:global(.store-hero) {
    padding-top: max(132px, calc(70px + env(safe-area-inset-top))) !important;
}
:global(.store-hero),
:global(.catalog) { scroll-margin-top: 92px; }
.payment-choice { min-width: 190px; }
.payment-choice select { min-height: 38px; padding: .45rem .6rem; }
@media (max-width: 700px) {
    :global(.store-hero) { padding-top: 190px !important; }
    .cart-dock { right: 10px; bottom: 10px; left: 10px; align-items: stretch; flex-direction: column; gap: 9px; }
    .payment-choice { min-width: 0; }
    .cart-dock .btn { width: 100%; }
}
</style>
