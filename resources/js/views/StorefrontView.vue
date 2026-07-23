<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../stores/auth';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const router = useRouter();
const products = ref([]);
const category = ref('All');
const cart = ref(loadSavedCart());
const loading = ref(true);
const notice = ref('');
const noticeKind = ref('success');
const paying = ref(false);
const cartOpen = ref(false);
const selectedProduct = ref(null);
const paymentProvider = 'paymongo';
const categories = ['All', 'Materials', 'Aggregates', 'Tools', 'Safety', 'Finishing'];
const productArtwork = {
    'CON-001': '/media/portland-cement-40kg.png',
    'CON-002': '/media/steel-bar.jpg',
    'CON-003': '/media/plywood.png',
    'AGG-001': '/media/washed-sand.webp',
    'AGG-002': '/media/crushed-stones.webp',
    'AGG-003': '/media/filling-sand.avif',
    'CON-005': '/media/safety-helmet-with-chin-strap.png',
    'CON-006': '/media/masonry-tool-set.png',
    'CON-007': '/media/safety-goggles.png',
    'CON-008': '/media/work-gloves-leather.png',
    'CON-009': '/media/power-drill-18v.png',
};
const productDescriptions = {
    'CON-001': 'General-purpose Portland cement for concrete, masonry, plastering, and other structural construction work.',
    'CON-002': '10 mm deformed reinforcing steel bar designed to improve the tensile strength and durability of concrete.',
    'CON-003': 'Durable 3/4-inch plywood suitable for concrete formwork, site fabrication, and reusable construction panels.',
    'AGG-001': 'Clean, washed construction sand suitable for concrete mixes, masonry work, and general site preparation.',
    'AGG-002': 'Graded crushed gravel for concrete production, drainage layers, pathways, and stable structural base work.',
    'AGG-003': 'Fine filling sand for leveling, backfilling, landscaping, and preparing construction surfaces.',
    'CON-005': 'Protective safety helmet with an adjustable chin strap for dependable head protection on active job sites.',
    'CON-006': 'A practical masonry tool set for laying blocks, applying mortar, finishing joints, and general site work.',
};
let checkoutKey = null;
let noticeTimer = null;

watch(cart, value => localStorage.setItem('nenial-cart', JSON.stringify(value)), { deep: true });
const visible = computed(() => category.value === 'All' ? products.value : products.value.filter(product => product.category === category.value));
const total = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity * (1 - item.discount_percent / 100), 0));
const itemCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));

function loadSavedCart() {
    try {
        const saved = JSON.parse(localStorage.getItem('nenial-cart') || '[]');
        return Array.isArray(saved) ? saved.filter(item => item?.id && Number(item.quantity) > 0) : [];
    } catch {
        localStorage.removeItem('nenial-cart');
        return [];
    }
}

onMounted(async () => {
    try {
        const payload = (await axios.get('/api/storefront/products')).data;
        if (!Array.isArray(payload?.data)) throw new Error('Invalid product response');
        products.value = payload.data;
        cart.value = cart.value.flatMap(savedItem => {
            const currentProduct = products.value.find(product => product.id === savedItem.id);
            if (!currentProduct?.available_quantity) return [];
            return [{ ...currentProduct, quantity: Math.min(Number(savedItem.quantity) || 1, currentProduct.available_quantity) }];
        });
    } catch {
        products.value = [];
        showNotice('Storefront is temporarily unavailable.', 'error');
    } finally { loading.value = false; }
});
onBeforeUnmount(() => clearTimeout(noticeTimer));

function add(product) {
    const item = cart.value.find(value => value.id === product.id);
    const quantity = (item?.quantity || 0) + 1;
    if (quantity > product.available_quantity) return showNotice('No more available stock.', 'error');
    item ? item.quantity++ : cart.value.push({ ...product, quantity: 1 });
    checkoutKey = null;
    showNotice(`${product.name} added to your cart.`);
}

function decrease(item) {
    if (item.quantity <= 1) return remove(item);
    item.quantity--;
    checkoutKey = null;
}

function remove(item) {
    cart.value = cart.value.filter(value => value.id !== item.id);
    checkoutKey = null;
    showNotice(`${item.name} removed from your cart.`, 'info');
}

function showNotice(message, kind = 'success') {
    clearTimeout(noticeTimer);
    notice.value = message;
    noticeKind.value = kind;
    noticeTimer = setTimeout(() => { notice.value = ''; }, 5000);
}

function productImage(product) {
    const configuredImage = String(product.image_url || '').trim();
    if (configuredImage && !configuredImage.endsWith('/Background.jpg')) return configuredImage;
    return productArtwork[product.sku] || configuredImage || '/media/Background.jpg';
}

function productDescription(product) {
    return product.description || productDescriptions[product.sku]
        || `${product.name} is supplied by ${product.supplier || 'Nenial'} for ${String(product.category || 'construction').toLowerCase()} applications. Contact the store if you need technical specifications or bulk-order assistance.`;
}

function lineTotal(item) {
    return Number(item.price) * item.quantity * (1 - Number(item.discount_percent || 0) / 100);
}

async function checkout() {
    if (!cart.value.length) return showNotice('Your cart is empty.', 'info');
    if (!auth.authenticated) return router.push({ path: '/login', query: { redirect: '/' } });
    if (auth.role !== 'user') {
        cartOpen.value = false;
        return showNotice('Online checkout requires a customer account.', 'error');
    }
    checkoutKey ||= crypto.randomUUID();
    paying.value = true;
    try {
        const { data: order } = await axios.post('/api/orders', { items: cart.value.map(item => ({ product_id: item.id, quantity: item.quantity })), payment_method: paymentProvider, idempotency_key: checkoutKey });
        const { data: payment } = await axios.post(`/api/orders/${order.id}/payment-checkout`, { provider: paymentProvider });
        cart.value = []; checkoutKey = null;
        window.location.assign(payment.payment_url);
    } catch (error) {
        showNotice(error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'Checkout failed.', 'error');
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
                <button class="btn primary cart-button" aria-label="View shopping cart" @click="cartOpen = true"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 4h2l2.3 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7M10 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg><span>Cart</span><b>{{ itemCount }}</b></button>
            </div>
        </header>
        <section class="store-hero"><div><span class="eyebrow">Construction supply, connected</span><h1>Materials in stock. Operations in sync.</h1><p>Shop live inventory with protected payment and delivery confirmation.</p></div></section>
        <main id="catalog" class="catalog">
            <div class="section-title"><div><span class="eyebrow">{{ category }} catalog</span><h2>Available products</h2></div><strong>{{ products.length }} live SKUs</strong></div>
            <div v-if="loading" class="empty">Loading current inventory…</div>
            <div class="product-grid">
                <article v-for="product in visible" :key="product.id" class="product-card">
                    <button class="product-preview" type="button" :aria-label="`View details for ${product.name}`" @click="selectedProduct = product">
                        <div class="product-image"><img :src="productImage(product)" :alt="product.name"></div>
                        <span class="tag">{{ product.category }}</span>
                        <h3>{{ product.name }}</h3>
                        <p>{{ product.supplier }} · {{ product.sku }}</p>
                        <div class="product-bottom"><strong>₱{{ Number(product.price).toLocaleString() }}</strong><small :class="{ low: product.is_low_stock }">{{ product.available_quantity }} {{ product.unit }}</small></div>
                        <span class="view-details">View product details</span>
                    </button>
                    <button class="btn primary full" :disabled="product.available_quantity < 1" @click="add(product)">{{ product.available_quantity ? 'Add to cart' : 'Out of stock' }}</button>
                </article>
            </div>
        </main>

        <div v-if="notice" class="store-toast" :class="noticeKind" role="status" aria-live="polite">
            <span>{{ notice }}</span><button type="button" aria-label="Dismiss notification" @click="notice = ''">×</button>
        </div>

        <aside v-if="cart.length" class="cart-dock">
            <button class="cart-dock-summary" type="button" @click="cartOpen = true"><span class="cart-mark"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 4h2l2.3 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7M10 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg></span><span><strong>{{ itemCount }} items</strong><small>₱{{ total.toLocaleString(undefined, { maximumFractionDigits: 2 }) }}</small></span></button>
            <button class="btn primary" @click="cartOpen = true">Review cart</button>
        </aside>

        <div v-if="cartOpen" class="store-overlay" @click.self="cartOpen = false">
            <section class="cart-panel" role="dialog" aria-modal="true" aria-labelledby="cart-title">
                <header><div><span class="eyebrow">Your selection</span><h2 id="cart-title">Shopping cart</h2></div><button class="close-button" type="button" aria-label="Close cart" @click="cartOpen = false">×</button></header>
                <div v-if="!cart.length" class="cart-empty"><span class="cart-mark large"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 4h2l2.3 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7M10 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg></span><h3>Your cart is empty</h3><p>Select a product from the catalog to begin.</p><button class="btn primary" @click="cartOpen = false">Continue shopping</button></div>
                <div v-else class="cart-content">
                    <div class="cart-lines">
                        <article v-for="item in cart" :key="item.id" class="cart-line">
                            <img :src="productImage(item)" :alt="item.name">
                            <div class="cart-line-info"><strong>{{ item.name }}</strong><small>{{ item.sku }} · ₱{{ Number(item.price).toLocaleString() }} each</small><button type="button" @click="remove(item)">Remove</button></div>
                            <div class="cart-line-actions"><div class="cart-quantity" :aria-label="`Quantity for ${item.name}`"><button type="button" aria-label="Reduce quantity" @click="decrease(item)">−</button><b>{{ item.quantity }}</b><button type="button" aria-label="Increase quantity" :disabled="item.quantity >= item.available_quantity" @click="add(item)">+</button></div><strong>₱{{ lineTotal(item).toLocaleString(undefined, { maximumFractionDigits: 2 }) }}</strong></div>
                        </article>
                    </div>
                    <footer class="cart-summary">
                        <div><span>Items</span><strong>{{ itemCount }}</strong></div>
                        <div><span>Subtotal after discounts</span><strong>₱{{ total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</strong></div>
                        <div class="payment-choice" aria-label="Payment provider"><span>Secure payment through PayMongo</span><small>Credit/debit card, GCash or Maya</small></div>
                        <p v-if="!auth.authenticated">You can review and edit your cart now. Sign in is only required when you continue to checkout.</p>
                        <button class="btn primary full" :disabled="paying" @click="checkout">{{ paying ? 'Opening PayMongo…' : auth.authenticated ? 'Proceed to secure checkout' : 'Sign in to checkout' }}</button>
                    </footer>
                </div>
            </section>
        </div>

        <div v-if="selectedProduct" class="store-overlay product-overlay" @click.self="selectedProduct = null">
            <section class="product-dialog" role="dialog" aria-modal="true" :aria-labelledby="`product-${selectedProduct.id}`">
                <button class="close-button" type="button" aria-label="Close product details" @click="selectedProduct = null">×</button>
                <div class="product-dialog-image"><img :src="productImage(selectedProduct)" :alt="selectedProduct.name"></div>
                <div class="product-dialog-content"><span class="tag">{{ selectedProduct.category }}</span><h2 :id="`product-${selectedProduct.id}`">{{ selectedProduct.name }}</h2><div class="detail-price">₱{{ Number(selectedProduct.price).toLocaleString() }} <small v-if="Number(selectedProduct.discount_percent)">Save {{ Number(selectedProduct.discount_percent) }}%</small></div><p class="description">{{ productDescription(selectedProduct) }}</p><dl><div><dt>Supplier</dt><dd>{{ selectedProduct.supplier || 'Nenial' }}</dd></div><div><dt>SKU</dt><dd>{{ selectedProduct.sku }}</dd></div><div><dt>Barcode</dt><dd>{{ selectedProduct.barcode }}</dd></div><div><dt>Availability</dt><dd>{{ selectedProduct.available_quantity }} {{ selectedProduct.unit }}</dd></div></dl><button class="btn primary full" :disabled="selectedProduct.available_quantity < 1" @click="add(selectedProduct)">{{ selectedProduct.available_quantity ? 'Add to cart' : 'Out of stock' }}</button></div>
            </section>
        </div>
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
    background: linear-gradient(90deg, rgba(4, 27, 17, .95), rgba(4, 27, 17, .45)), url('/media/construction-supply-bg.png') center / cover no-repeat !important;
    background-attachment: fixed !important;
}
:global(.store-hero h1) { text-shadow: 0 3px 18px rgba(0, 0, 0, .52); }
:global(.store-hero p) { text-shadow: 0 2px 8px rgba(0, 0, 0, .42); }
:global(.store-hero),
:global(.catalog) { scroll-margin-top: 92px; }
.cart-button svg,
.cart-mark svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.cart-button b { display: grid; place-items: center; min-width: 22px; height: 22px; padding: 0 5px; border-radius: 999px; color: var(--dark); background: #fff; font-size: .72rem; }
.product-preview { display: grid; gap: 9px; width: 100%; padding: 0; border: 0; color: inherit; background: transparent; text-align: left; }
.product-preview:focus-visible { outline: 3px solid rgba(23, 107, 67, .22); outline-offset: 5px; border-radius: 10px; }
.product-card { transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
.product-card:hover { transform: translateY(-3px); border-color: #b9d5c4; box-shadow: 0 22px 48px rgba(18, 55, 36, .14); }
.product-card .product-image img { transition: transform .25s ease; }
.product-card:hover .product-image img { transform: scale(1.035); }
.view-details { color: var(--brand); font-size: .74rem; font-weight: 800; }
.store-toast { position: fixed; top: 90px; right: 20px; z-index: 2200; display: flex; align-items: center; gap: 14px; max-width: min(440px, calc(100vw - 32px)); padding: 13px 15px; border: 1px solid #b9dbc7; border-radius: 12px; color: #123d29; background: #edfaf2; box-shadow: 0 18px 48px rgba(6, 35, 22, .2); font-weight: 700; }
.store-toast.error { border-color: #edc2c2; color: #8c2929; background: #fff2f2; }
.store-toast.info { border-color: #c8d9cf; color: #405149; background: #f7faf8; }
.store-toast button { padding: 0; border: 0; color: currentColor; background: transparent; font-size: 1.35rem; line-height: 1; }
.cart-dock-summary { display: flex; align-items: center; gap: 10px; padding: 0; border: 0; color: inherit; background: transparent; text-align: left; }
.cart-dock-summary > span:last-child { display: grid; gap: 2px; }
.cart-dock-summary small { color: var(--muted); font-size: .8rem; }
.cart-mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; color: #fff; background: var(--brand); }
.cart-mark.large { width: 58px; height: 58px; margin: 0 auto; border-radius: 16px; }
.cart-mark.large svg { width: 28px; height: 28px; }
.store-overlay { position: fixed; inset: 0; z-index: 2100; display: flex; justify-content: flex-end; padding: 0; background: rgba(5, 24, 15, .58); backdrop-filter: blur(5px); }
.cart-panel { display: flex; flex-direction: column; width: min(620px, 100%); height: 100%; color: var(--ink); background: #fff; box-shadow: -24px 0 70px rgba(0, 0, 0, .22); }
.cart-panel > header { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 22px 24px; border-bottom: 1px solid var(--line); background: linear-gradient(180deg, #fff, #f7faf8); }
.cart-panel h2 { margin: 5px 0 0; font-size: 1.65rem; }
.close-button { display: grid; place-items: center; flex: 0 0 auto; width: 40px; height: 40px; padding: 0; border: 1px solid var(--line); border-radius: 10px; color: var(--ink); background: #fff; font-size: 1.55rem; line-height: 1; }
.close-button:hover { color: var(--brand); border-color: #b9d5c4; background: var(--soft); }
.cart-empty { display: grid; place-content: center; justify-items: center; flex: 1; gap: 10px; padding: 30px; text-align: center; }
.cart-empty h3, .cart-empty p { margin: 0; }
.cart-empty p { margin-bottom: 8px; color: var(--muted); }
.cart-content { display: flex; flex: 1; min-height: 0; flex-direction: column; }
.cart-lines { flex: 1; min-height: 0; padding: 8px 24px; overflow: auto; }
.cart-line { display: grid; grid-template-columns: 82px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 16px 0; border-bottom: 1px solid var(--line); }
.cart-line > img { width: 82px; height: 74px; border-radius: 10px; background: #eef3ef; object-fit: cover; }
.cart-line-info { display: grid; gap: 5px; min-width: 0; }
.cart-line-info strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cart-line-info small { color: var(--muted); }
.cart-line-info button { width: max-content; padding: 0; border: 0; color: var(--danger); background: transparent; font-size: .75rem; font-weight: 750; }
.cart-line-actions { display: grid; justify-items: end; gap: 9px; }
.cart-quantity { display: flex; align-items: center; overflow: hidden; border: 1px solid #cbd9d0; border-radius: 9px; }
.cart-quantity button { width: 32px; height: 32px; border: 0; color: var(--dark); background: #f4f8f6; font-size: 1.1rem; }
.cart-quantity button:hover:not(:disabled) { background: var(--soft); }
.cart-quantity b { min-width: 34px; text-align: center; font-size: .82rem; }
.cart-summary { display: grid; gap: 11px; padding: 20px 24px max(22px, env(safe-area-inset-bottom)); border-top: 1px solid var(--line); background: #f8fbf9; }
.cart-summary > div:not(.payment-choice) { display: flex; justify-content: space-between; gap: 20px; }
.cart-summary > div:nth-child(2) { padding-top: 10px; border-top: 1px dashed #c9d7cf; font-size: 1.05rem; }
.cart-summary p { margin: 0; color: var(--muted); font-size: .78rem; line-height: 1.5; }
.payment-choice { display: grid; min-width: 190px; gap: 1px; }
.payment-choice small { color: var(--muted, #607369); }
.product-overlay { align-items: center; justify-content: center; padding: 24px; }
.product-dialog { position: relative; display: grid; grid-template-columns: minmax(280px, .9fr) minmax(340px, 1.1fr); width: min(920px, 100%); max-height: min(760px, calc(100vh - 48px)); overflow: auto; border-radius: 20px; color: var(--ink); background: #fff; box-shadow: 0 30px 90px rgba(0, 0, 0, .34); }
.product-dialog > .close-button { position: absolute; top: 14px; right: 14px; z-index: 2; }
.product-dialog-image { min-height: 460px; background: #eef3ef; }
.product-dialog-image img { width: 100%; height: 100%; min-height: 460px; object-fit: cover; }
.product-dialog-content { display: grid; align-content: center; gap: 15px; padding: 52px 38px 38px; }
.product-dialog-content h2 { margin: 0; font-size: clamp(1.7rem, 3vw, 2.4rem); line-height: 1.08; }
.detail-price { color: var(--brand); font-size: 1.65rem; font-weight: 850; }
.detail-price small { display: inline-flex; margin-left: 8px; padding: .3rem .5rem; border-radius: 999px; color: #724d00; background: #fff0c5; font-size: .7rem; vertical-align: middle; }
.description { margin: 0; color: var(--muted); line-height: 1.65; }
.product-dialog dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 0; padding: 14px 0; border-block: 1px solid var(--line); }
.product-dialog dl div { display: grid; gap: 3px; }
.product-dialog dt { color: var(--muted); font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
.product-dialog dd { margin: 0; font-size: .85rem; font-weight: 700; overflow-wrap: anywhere; }
@media (max-width: 700px) {
    :global(.store-hero) {
        padding-top: 190px !important;
        background-attachment: scroll !important;
    }
    .store-toast { top: 178px; right: 10px; left: 10px; max-width: none; }
    .cart-dock { right: 10px; bottom: 10px; left: 10px; align-items: center; flex-direction: row; gap: 9px; }
    .cart-dock-summary { flex: 1; }
    .payment-choice { min-width: 0; }
    .cart-dock .btn { width: auto; }
    .cart-panel > header, .cart-lines, .cart-summary { padding-inline: 16px; }
    .cart-line { grid-template-columns: 64px minmax(0, 1fr); }
    .cart-line > img { width: 64px; height: 64px; }
    .cart-line-actions { grid-column: 2; grid-template-columns: auto 1fr; align-items: center; justify-items: start; }
    .cart-line-actions > strong { justify-self: end; }
    .product-overlay { padding: 10px; }
    .product-dialog { grid-template-columns: 1fr; max-height: calc(100vh - 20px); }
    .product-dialog-image, .product-dialog-image img { min-height: 250px; max-height: 300px; }
    .product-dialog-content { padding: 28px 20px 24px; }
    .product-dialog dl { grid-template-columns: 1fr 1fr; }
}
</style>
