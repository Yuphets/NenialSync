<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import { useAuthStore } from "../stores/auth";
import { useInventoryStore } from "../stores/inventory";

const VAT_RATE = 0.12;
const auth = useAuthStore();
const inventory = useInventoryStore();
const search = ref("");
const barcode = ref("");
const selectedCategory = ref("All");
const cart = ref([]);
const message = ref("");
const busy = ref(false);
const saleDiscountPercent = ref(0);
const paymentMethod = ref("cash");
const cashReceived = ref("");
const printReceiptAutomatically = ref(true);
const receiptPaperSize = ref("80 mm");
const mobilePanel = ref("products");
const posRoot = ref(null);
const isFullscreen = ref(false);
let checkoutKey = crypto.randomUUID();
let scannerBuffer = "";
let scannerStartedAt = 0;
let scannerLastKeyAt = 0;
let scannerTimer = null;
const SCANNER_MAX_KEY_GAP_MS = 80;
const SCANNER_IDLE_SUBMIT_MS = 120;

const categories = computed(() => [
    "All",
    ...new Set(
        inventory.products.map((product) => product.category).filter(Boolean),
    ),
]);
const products = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return inventory.products.filter(
        (product) =>
            (selectedCategory.value === "All" ||
                product.category === selectedCategory.value) &&
            (!needle ||
                [
                    product.name,
                    product.sku,
                    product.barcode,
                    product.category,
                ].some((value) =>
                    String(value || "")
                        .toLowerCase()
                        .includes(needle),
                )),
    );
});
const cartQuantity = computed(() =>
    cart.value.reduce((sum, item) => sum + item.quantity, 0),
);
const subtotal = computed(() =>
    cart.value.reduce(
        (sum, item) => sum + Number(item.price) * item.quantity,
        0,
    ),
);
const discount = computed(() =>
    cart.value.reduce(
        (sum, item) =>
            sum +
            (Number(item.price) *
                item.quantity *
                Number(item.discount_percent)) /
                100,
        0,
    ),
);
const saleDiscount = computed(
    () =>
        ((subtotal.value - discount.value) *
            Math.max(
                0,
                Math.min(100, Number(saleDiscountPercent.value) || 0),
            )) /
        100,
);
const totalDiscount = computed(() => discount.value + saleDiscount.value);
const total = computed(() => subtotal.value - totalDiscount.value);
const vatable = computed(() => total.value / (1 + VAT_RATE));
const vat = computed(() => total.value - vatable.value);
const changeDue = computed(() =>
    Math.max(0, (Number(cashReceived.value) || 0) - total.value),
);
const money = (value) =>
    Number(value).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
const productTone = (category) =>
    `product-tile--${String(category || "general")
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")}`;
const categoryTone = (category) => productTone(category).replace("product-tile--", "category-tone--");
const productIcon = (category) =>
    ({
        Aggregates: "⛰",
        Materials: "ϟ",
        Finishing: "▤",
        Tools: "⚒",
        Safety: "⛑",
    })[category] || "◼";

onMounted(async () => {
    document.addEventListener("keydown", captureScannerKey, true);
    document.addEventListener("fullscreenchange", syncFullscreenState);
    syncFullscreenState();
    await inventory.load();
    inventory.start();
});
onBeforeUnmount(() => {
    inventory.stop();
    document.removeEventListener("keydown", captureScannerKey, true);
    document.removeEventListener("fullscreenchange", syncFullscreenState);
    document.body.classList.remove("pos-focus-mode");
    if (document.fullscreenElement === posRoot.value) {
        document.exitFullscreen().catch(() => {});
    }
    clearTimeout(scannerTimer);
});

function syncFullscreenState() {
    const active = document.fullscreenElement === posRoot.value;
    isFullscreen.value = active;
    document.body.classList.toggle("pos-focus-mode", active);
    if (active) resetPosViewport();
}

function resetPosViewport() {
    window.requestAnimationFrame(() => {
        if (!posRoot.value) return;
        posRoot.value.scrollTop = 0;
        posRoot.value.scrollLeft = 0;
    });
}

async function toggleFullscreen() {
    try {
        if (document.fullscreenElement === posRoot.value) {
            await document.exitFullscreen();
            return;
        }

        if (!posRoot.value?.requestFullscreen) {
            isFullscreen.value = !isFullscreen.value;
            document.body.classList.toggle(
                "pos-focus-mode",
                isFullscreen.value,
            );
            if (isFullscreen.value) resetPosViewport();
            return;
        }

        await posRoot.value.requestFullscreen();
        syncFullscreenState();
    } catch {
        message.value =
            "Fullscreen could not be opened. Check the browser permission and try again.";
    }
}

function add(product) {
    const item = cart.value.find((value) => value.id === product.id);
    const quantity = (item?.quantity || 0) + 1;
    if (quantity > product.available_quantity) {
        message.value = "Insufficient available stock.";
        return false;
    }
    item ? item.quantity++ : cart.value.push({ ...product, quantity: 1 });
    barcode.value = "";
    message.value = "";
    if (window.matchMedia("(max-width: 1240px)").matches)
        mobilePanel.value = "cart";
    return true;
}

function scan(scannedCode = barcode.value) {
    const code = String(scannedCode || "").trim();
    if (!code) return;
    const product = inventory.products.find(
        (item) =>
            item.barcode === code ||
            item.sku.toLowerCase() === code.toLowerCase(),
    );
    if (product) {
        if (add(product)) message.value = `${product.name} scanned and added.`;
    } else {
        barcode.value = code;
        message.value = `Barcode or SKU "${code}" was not found.`;
    }
}

function captureScannerKey(event) {
    if (event.ctrlKey || event.altKey || event.metaKey || event.isComposing || event.repeat) return;
    const target = event.target;
    if (target instanceof HTMLElement && (target.matches("input, textarea, select") || target.isContentEditable)) return;

    if ((event.key === "Enter" || event.key === "Tab") && scannerBuffer) {
        event.preventDefault();
        submitScannerBuffer();
        return;
    }
    if (event.key.length !== 1) return;

    const now = performance.now();
    if (scannerLastKeyAt && now - scannerLastKeyAt > SCANNER_MAX_KEY_GAP_MS) resetScannerBuffer();
    if (!scannerBuffer) scannerStartedAt = now;
    scannerBuffer += event.key;
    scannerLastKeyAt = now;
    clearTimeout(scannerTimer);
    scannerTimer = setTimeout(submitScannerBuffer, SCANNER_IDLE_SUBMIT_MS);
}

function submitScannerBuffer() {
    clearTimeout(scannerTimer);
    const code = scannerBuffer.trim();
    const duration = scannerLastKeyAt - scannerStartedAt;
    const averageGap = code.length > 1 ? duration / (code.length - 1) : Infinity;
    resetScannerBuffer();
    if (code.length < 3 || averageGap > SCANNER_MAX_KEY_GAP_MS) return;
    barcode.value = code;
    scan(code);
}

function resetScannerBuffer() {
    clearTimeout(scannerTimer);
    scannerBuffer = "";
    scannerStartedAt = 0;
    scannerLastKeyAt = 0;
}

function clearTicket() {
    if (!cart.value.length || confirm("Clear every item from this sale?")) {
        cart.value = [];
        saleDiscountPercent.value = 0;
        cashReceived.value = "";
    }
}

function setExactCash() {
    cashReceived.value = total.value.toFixed(2);
}

async function checkout() {
    busy.value = true;
    try {
        const { data } = await axios.post("/api/pos/checkout", {
            items: cart.value.map((item) => ({
                product_id: item.id,
                quantity: item.quantity,
            })),
            payment_method: paymentMethod.value,
            discount_percent: Number(saleDiscountPercent.value) || 0,
            idempotency_key: checkoutKey,
        });
        message.value = `Sale ${data.reference} completed · ₱${money(data.total)} (VAT ₱${money(data.vat_amount)})`;
        cart.value = [];
        saleDiscountPercent.value = 0;
        paymentMethod.value = "cash";
        cashReceived.value = "";
        mobilePanel.value = "products";
        checkoutKey = crypto.randomUUID();
        await inventory.load();
    } catch (error) {
        message.value =
            error.response?.data?.message ||
            Object.values(error.response?.data?.errors || {})[0]?.[0] ||
            "Sale failed.";
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        ref="posRoot"
        class="pos-page"
        :class="{
            'cashier-pos': auth.role === 'cashier',
            'pos-focus-active': isFullscreen,
        }"
    >
    <PageHeader
        title="POS Terminal"
        subtitle="Fast counter checkout with transaction-safe stock deduction"
        ><div class="actions pos-header-actions">
            <span class="live">● Inventory live</span>
            <button
                v-if="['admin', 'cashier'].includes(auth.role)"
                class="btn"
                type="button"
                :aria-pressed="isFullscreen"
                :aria-label="
                    isFullscreen
                        ? 'Exit POS fullscreen'
                        : 'Open POS fullscreen'
                "
                @click="toggleFullscreen"
            >
                {{ isFullscreen ? "Exit fullscreen" : "Fullscreen POS" }}
            </button>
        </div></PageHeader
    >
    <p v-if="message" class="notice">{{ message }}</p>
    <nav class="pos-mobile-tabs" aria-label="POS workspace">
        <button
            :class="{ active: mobilePanel === 'products' }"
            @click="mobilePanel = 'products'"
        >
            Products
        </button>
        <button
            :class="{ active: mobilePanel === 'cart' }"
            @click="mobilePanel = 'cart'"
        >
            Cart <b>{{ cartQuantity }}</b>
        </button>
    </nav>
    <div
        class="pos-layout pos-workstation"
        :class="`mobile-panel-${mobilePanel}`"
    >
        <section class="panel compact-products product-library">
            <div class="panel-head pos-panel-head">
                <div>
                    <h2>Product library</h2>
                    <small>{{ products.length }} products available</small>
                </div>
                <div class="pos-panel-actions">
                    <button
                        v-if="auth.role === 'cashier'"
                        class="btn tiny cashier-fullscreen-button"
                        type="button"
                        :aria-pressed="isFullscreen"
                        :aria-label="
                            isFullscreen
                                ? 'Exit POS fullscreen'
                                : 'Open POS fullscreen'
                        "
                        @click="toggleFullscreen"
                    >
                        {{
                            isFullscreen
                                ? "Exit fullscreen"
                                : "Fullscreen POS"
                        }}
                    </button>
                    <span class="register-state">Register open</span>
                </div>
            </div>
            <div class="scanner pos-scanner">
                <label class="scan-field"
                    ><span class="scan-label-head"><span>Barcode or SKU</span><small>Scanner ready · scan anytime</small></span><input
                        v-model="barcode"
                        autofocus
                        placeholder="Scan or type product code"
                        @keyup.enter="scan()"
                /></label>
                <button class="btn primary" @click="scan()">Add item</button>
            </div>
            <label class="pos-search"
                >Find a product<input
                    v-model="search"
                    type="search"
                    placeholder="Search name, SKU, barcode, or category"
            /></label>
            <nav class="category-strip" aria-label="Product categories">
                <button
                    v-for="category in categories"
                    :key="category"
                    :class="[categoryTone(category), { active: selectedCategory === category }]"
                    @click="selectedCategory = category"
                >
                    {{ category }}
                </button>
            </nav>
            <div class="pos-keys">
                <button
                    v-for="product in products"
                    :key="product.id"
                    :class="productTone(product.category)"
                    :disabled="!product.available_quantity"
                    @click="add(product)"
                >
                    <span class="product-tile-top">
                        <span class="product-tile-icon" aria-hidden="true">{{ productIcon(product.category) }}</span>
                        <span class="product-tile-category">{{ product.category }}</span>
                    </span>
                    <strong>{{ product.name }}</strong>
                    <small
                        >{{ product.sku }} · {{ product.available_quantity }}
                        {{ product.unit }} available</small
                    >
                    <b>₱{{ money(product.price) }}</b>
                </button>
                <div v-if="!products.length" class="empty product-empty">
                    No products match this search.
                </div>
            </div>
        </section>

        <section class="panel sale-ticket">
            <div class="ticket-head">
                <div>
                    <small>REGISTER 01 · CURRENT SALE</small>
                    <h2>Sale ticket</h2>
                </div>
                <div class="ticket-head-actions">
                    <span>{{ cartQuantity }} items</span>
                    <button
                        v-if="cart.length"
                        class="clear-ticket"
                        @click="clearTicket"
                    >
                        Clear
                    </button>
                </div>
            </div>
            <div class="ticket-column-head">
                <span>Item</span><span>Quantity</span><span>Amount</span>
            </div>
            <div class="ticket-lines">
                <div v-if="!cart.length" class="empty ticket-empty">
                    <strong>Ready for the next sale</strong>
                    <span>Scan a barcode or select a product tile.</span>
                </div>
                <div v-for="item in cart" :key="item.id" class="ticket-line">
                    <div>
                        <strong>{{ item.name }}</strong>
                        <small
                            >₱{{ money(item.price) }} each<span
                                v-if="item.discount_percent"
                            >
                                · {{ item.discount_percent }}% item discount</span
                            ></small
                        >
                    </div>
                    <div class="qty">
                        <button
                            aria-label="Decrease quantity"
                            @click="
                                item.quantity > 1
                                    ? item.quantity--
                                    : cart.splice(cart.indexOf(item), 1)
                            "
                        >
                            −</button
                        ><span>{{ item.quantity }}</span
                        ><button
                            aria-label="Increase quantity"
                            :disabled="item.quantity >= item.available_quantity"
                            @click="item.quantity++"
                        >
                            +
                        </button>
                    </div>
                    <b
                        >₱{{
                            money(
                                item.price *
                                    item.quantity *
                                    (1 - item.discount_percent / 100),
                            )
                        }}</b
                    >
                </div>
            </div>
            <div class="ticket-summary">
                <span>Subtotal <b>₱{{ money(subtotal) }}</b></span>
                <span
                    >Product discounts <b>−₱{{ money(discount) }}</b></span
                >
                <label class="ticket-discount">
                    <span>Additional discount</span>
                    <span
                        ><input
                            v-model.number="saleDiscountPercent"
                            type="number"
                            min="0"
                            max="100"
                            step="1"
                            aria-label="Additional sale discount percent"
                        />%</span
                    >
                </label>
                <span>Total discount <b>−₱{{ money(totalDiscount) }}</b></span>
                <span>VATable sales <b>₱{{ money(vatable) }}</b></span>
                <span>VAT (12%, included) <b>₱{{ money(vat) }}</b></span>
            </div>
            <div class="ticket-total">
                <span>Amount due</span><strong>₱{{ money(total) }}</strong>
            </div>
            <div class="tender-section">
                <span>Tender</span>
                <div class="tender-grid">
                    <button
                        v-for="method in [
                            ['cash', 'Cash'],
                            ['card', 'Card'],
                            ['gcash', 'GCash'],
                            ['paymaya', 'Maya'],
                        ]"
                        :key="method[0]"
                        :class="{ active: paymentMethod === method[0] }"
                        @click="paymentMethod = method[0]"
                    >
                        {{ method[1] }}
                    </button>
                </div>
                <small v-if="paymentMethod !== 'cash'" class="tender-note">Confirm approval on the connected payment terminal before completing the sale.</small>
            </div>
            <div class="payment-details">
                <label class="cash-received">
                    <span>Cash received</span>
                    <span class="money-input">
                        <span>₱</span>
                        <input
                            v-model.number="cashReceived"
                            type="number"
                            min="0"
                            step="0.01"
                            inputmode="decimal"
                            aria-label="Cash received"
                        />
                    </span>
                </label>
                <button
                    class="exact-cash"
                    type="button"
                    :disabled="!cart.length"
                    @click="setExactCash"
                >
                    Exact cash
                </button>
                <div class="change-due">
                    <span>Change</span><b>₱{{ money(changeDue) }}</b>
                </div>
            </div>
            <div class="receipt-options">
                <label class="receipt-toggle">
                    <input
                        v-model="printReceiptAutomatically"
                        type="checkbox"
                    />
                    <span>Print receipt automatically</span>
                </label>
                <label class="paper-size">
                    <span>Paper</span>
                    <select v-model="receiptPaperSize">
                        <option>80 mm</option>
                        <option>58 mm</option>
                    </select>
                </label>
            </div>
            <button
                class="btn primary full checkout"
                :disabled="!cart.length || busy"
                @click="checkout"
            >
                {{ busy ? "Processing…" : `Charge ₱${money(total)}` }}
            </button>
        </section>
    </div>
    </div>
</template>

<style scoped>
.pos-page { min-width: 0; box-sizing: border-box; }
.pos-header-actions { align-items: center; }
.pos-panel-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .45rem;
}
.cashier-fullscreen-button {
    min-height: 32px;
    padding: .35rem .65rem;
    color: var(--brand);
    border-color: #bfd8c8;
    background: #fff;
    white-space: nowrap;
}
.pos-page.pos-focus-active:not(:fullscreen) {
    position: fixed;
    inset: 0;
    z-index: 100;
    width: 100vw;
    height: 100dvh;
    padding: max(14px, env(safe-area-inset-top)) 14px
        max(14px, env(safe-area-inset-bottom));
    overflow: auto;
    background: var(--page);
}
.pos-page:fullscreen {
    width: 100%;
    height: 100dvh;
    padding: max(14px, env(safe-area-inset-top)) 14px 14px;
    overflow-y: auto;
    background: var(--page);
}
.pos-page:fullscreen > .page-header,
.pos-page.pos-focus-active > .page-header {
    flex: 0 0 auto;
    width: 100%;
    min-height: 64px;
    margin: 0 auto 12px;
    padding-top: 2px;
    align-items: center;
}
.pos-mobile-tabs { display: none; }
.pos-workstation {
    grid-template-columns: minmax(390px, .82fr) minmax(0, 1.28fr);
    align-items: stretch;
    gap: 14px;
}
.product-library,
.sale-ticket {
    min-height: max(620px, calc(100dvh - 150px));
}
.product-library { display: flex; flex-direction: column; }
.pos-panel-head h2 { margin: 0 0 .2rem; }
.register-state { padding: .35rem .65rem; border-radius: 999px; color: var(--brand); background: var(--soft); font-size: .72rem; font-weight: 800; }
.pos-scanner { grid-template-columns: minmax(0, 1fr) auto; align-items: end; padding-bottom: 8px; }
.scan-field { min-width: 0; }
.scan-label-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.scan-label-head small { color: var(--brand); font-size: .68rem; font-weight: 800; }
.pos-scanner .btn { min-width: 88px; padding-inline: .75rem; white-space: nowrap; }
.pos-search { margin: 0 12px 10px; }
.category-strip { display: flex; gap: .4rem; padding: 0 12px 10px; overflow-x: auto; }
.category-strip button { flex: 0 0 auto; padding: .48rem .72rem; border: 1px solid var(--line); border-radius: 999px; color: var(--muted); background: #fff; font-size: .74rem; font-weight: 750; }
.category-strip button.active { border-color: var(--brand); color: #fff; background: var(--brand); }
.pos-keys {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    grid-auto-rows: minmax(168px, auto);
    align-content: start;
    flex: 1;
    min-height: 0;
    max-height: none;
    overscroll-behavior: contain;
}
.pos-keys button {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    min-width: 0;
    min-height: 168px;
    padding: 13px;
    overflow: hidden;
}
.pos-keys button strong,
.pos-keys button small {
    display: block;
    flex: 0 0 auto;
    min-width: 0;
    overflow-wrap: anywhere;
    line-height: 1.35;
}
.pos-keys button b {
    display: block;
    flex: 0 0 auto;
    width: 100%;
    margin-top: auto;
    padding-top: 7px;
    color: var(--brand);
    font-size: .95rem;
    line-height: 1.25;
    white-space: nowrap;
}
.pos-keys button:hover:not(:disabled) { border-color: #91bca2; background: #f4faf6; }
.product-tile-category { flex: 0 0 auto; color: var(--muted); font-size: .64rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
.product-empty { grid-column: 1 / -1; }
.sale-ticket { display: flex; flex-direction: column; min-width: 0; }
.ticket-head { padding: 17px 20px; }
.ticket-head-actions { display: flex; align-items: center; gap: .55rem; }
.clear-ticket { padding: .35rem .6rem; border: 1px solid rgba(255,255,255,.35); border-radius: 7px; color: #fff; background: transparent; font-size: .72rem; font-weight: 750; }
.ticket-column-head,
.ticket-line { grid-template-columns: minmax(0, 1fr) clamp(92px, 18%, 116px) minmax(88px, 110px); }
.ticket-column-head { display: grid; gap: 12px; padding: 10px 18px; border-bottom: 1px solid var(--line); color: var(--muted); background: #f7faf8; font-size: .66rem; font-weight: 850; text-transform: uppercase; letter-spacing: .05em; }
.ticket-column-head span:nth-child(2) { text-align: center; }
.ticket-column-head span:last-child { text-align: right; }
.ticket-lines { flex: 1; min-height: 130px; max-height: none; overflow-y: auto; overscroll-behavior: contain; }
.ticket-line > div:first-child,
.ticket-line strong,
.ticket-line small { min-width: 0; overflow-wrap: anywhere; }
.ticket-empty { display: grid; place-content: center; gap: .35rem; min-height: 180px; }
.ticket-empty strong { color: var(--ink); font-size: 1rem; }
.ticket-discount { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.ticket-discount > span:last-child { display: flex; align-items: center; gap: .35rem; color: var(--ink); font-weight: 700; }
.ticket-discount input { width: 82px; min-height: 34px; padding: .35rem .5rem; text-align: right; }
.ticket-total { margin-top: 0; }
.tender-section { display: grid; gap: .55rem; margin: 14px 18px 0; }
.tender-section > span { color: var(--muted); font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
.tender-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: .45rem; }
.tender-grid button { min-height: 38px; border: 1px solid var(--line); border-radius: 8px; color: var(--ink); background: #fff; font-weight: 750; }
.tender-grid button.active { border-color: var(--brand); color: var(--brand); background: var(--soft); box-shadow: inset 0 0 0 1px var(--brand); }
.tender-note { color: var(--muted); line-height: 1.4; }
.cashier-pos .product-library,
.cashier-pos .sale-ticket {
    border: 1px solid #dbe7df;
    border-radius: 18px;
    box-shadow: 0 16px 38px rgba(18, 64, 42, .1);
}
.cashier-pos .product-library { background: rgba(255, 255, 255, .92); }
.cashier-pos .pos-panel-head {
    min-height: 66px;
    padding: 14px 16px;
    border-bottom: 1px solid #e4eee8;
    background: linear-gradient(135deg, #fbfefc, #eff8f2);
}
.cashier-pos .pos-panel-head h2 { color: #123c29; font-size: 1.05rem; }
.cashier-pos .register-state {
    display: inline-flex;
    align-items: center;
    gap: .38rem;
    border: 1px solid #b8ddc5;
    color: #17623d;
    background: #eaf8ef;
}
.cashier-pos .register-state::before {
    width: .48rem;
    height: .48rem;
    border-radius: 50%;
    background: currentColor;
    box-shadow: 0 0 0 4px rgba(23, 98, 61, .1);
    content: "";
}
.cashier-pos .pos-scanner {
    margin: 10px 12px 8px;
    padding: 10px;
    border: 1px solid #d8e7dd;
    border-radius: 13px;
    background: #f7fbf8;
}
.cashier-pos .pos-scanner input,
.cashier-pos .pos-search input {
    border-color: #cfe0d5;
    background: #fff;
    box-shadow: none;
}
.cashier-pos .pos-scanner input:focus,
.cashier-pos .pos-search input:focus,
.cashier-pos .ticket-discount input:focus {
    border-color: #278154;
    box-shadow: 0 0 0 3px rgba(39, 129, 84, .13);
}
.cashier-pos .category-strip {
    padding-bottom: 12px;
    border-bottom: 1px solid #edf3ef;
}
.pos-page .category-strip button {
    border-color: #dce8e0;
    color: #537062;
    background: #fbfdfc;
    transition: transform .16s ease, border-color .16s ease, background .16s ease, box-shadow .16s ease, color .16s ease;
}
.pos-page .category-strip button:hover {
    transform: translateY(-1px);
    border-color: #9bcbb0;
    color: #17623d;
    background: #f0f8f3;
    box-shadow: 0 6px 14px rgba(25, 112, 68, .12);
}
.pos-page .category-strip button.active {
    border-color: transparent;
    background: linear-gradient(135deg, #12663d, #248457);
    box-shadow: 0 5px 12px rgba(25, 112, 68, .2);
}
.pos-page .category-strip button:not(.category-tone--all)::before {
    width: .42rem;
    height: .42rem;
    border-radius: 50%;
    background: var(--category-color, #668274);
    content: "";
}
.pos-page .category-strip button.active { color: #fff; font-weight: 800; }
.pos-page .category-strip .category-tone--aggregates { --category-color: #C07A1E; }
.pos-page .category-strip .category-tone--materials { --category-color: #3D6FA8; }
.pos-page .category-strip .category-tone--finishing { --category-color: #8A5A3C; }
.pos-page .category-strip .category-tone--tools { --category-color: #B8452F; }
.pos-page .category-strip .category-tone--safety { --category-color: #C4292F; }
.pos-page .category-strip button:not(.category-tone--all):hover {
    border-color: var(--category-color);
    color: var(--category-color);
    background: color-mix(in srgb, var(--category-color) 12%, #fff);
    box-shadow: 0 6px 14px color-mix(in srgb, var(--category-color) 18%, transparent);
}
.pos-page .category-strip button:not(.category-tone--all):hover::before {
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--category-color) 16%, transparent);
}
.pos-page .category-strip button.active:hover { color: #fff; }
.pos-page .category-strip button:not(.category-tone--all).active {
    border-color: var(--category-color);
    background: var(--category-color);
    box-shadow: 0 5px 12px color-mix(in srgb, var(--category-color) 26%, transparent);
}
.pos-page .category-strip button:not(.category-tone--all).active::before { display: none; }
.cashier-pos .pos-keys { gap: 11px; padding: 13px; background: #f6faf7; }
.cashier-pos .pos-keys button {
    --tile-color: #32825a;
    --tile-tint: #eaf5ed;
    border: 1px solid #dbe6df;
    border-top: 4px solid var(--tile-color);
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 3px 10px rgba(28, 75, 50, .06);
    transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}
.cashier-pos .pos-keys button:hover:not(:disabled) {
    transform: translateY(-2px);
    border-color: var(--tile-color);
    background: #fff;
    box-shadow: 0 10px 18px rgba(25, 91, 54, .13);
}
.cashier-pos .pos-keys button strong { color: var(--ink); }
.cashier-pos .pos-keys button b { color: var(--ink); font-size: 1.05rem; }
.cashier-pos .product-tile-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; margin-bottom: .65rem; }
.cashier-pos .product-tile-icon {
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    color: var(--tile-color);
    background: var(--tile-tint);
    font-size: 1.05rem;
    line-height: 1;
}
.cashier-pos .product-tile-category {
    display: inline-flex;
    align-self: flex-start;
    padding: .24rem .5rem;
    border-radius: 999px;
    color: var(--tile-color);
    background: var(--tile-tint);
}
.cashier-pos .pos-keys .product-tile--aggregates { --tile-color: #C07A1E; --tile-tint: #fff1dc; }
.cashier-pos .pos-keys .product-tile--materials { --tile-color: #3D6FA8; --tile-tint: #e8f0fb; }
.cashier-pos .pos-keys .product-tile--finishing { --tile-color: #8A5A3C; --tile-tint: #f6ebe5; }
.cashier-pos .pos-keys .product-tile--tools { --tile-color: #B8452F; --tile-tint: #fbe9e5; }
.cashier-pos .pos-keys .product-tile--safety { --tile-color: #C4292F; --tile-tint: #fbe8e9; }
.cashier-pos .pos-keys button small::before {
    display: inline-block;
    width: .43rem;
    height: .43rem;
    margin: 0 .35rem .04rem 0;
    border-radius: 50%;
    background: var(--tile-color);
    content: "";
}
.cashier-pos .sale-ticket { background: #fff; }
.cashier-pos .ticket-head {
    background: linear-gradient(115deg, #0c3c27, #17623d 70%, #257a50);
    box-shadow: inset 0 -1px rgba(255, 255, 255, .12);
}
.cashier-pos .ticket-head small { color: #b9ddc7; font-weight: 800; letter-spacing: .08em; }
.cashier-pos .ticket-head h2 { letter-spacing: -.02em; }
.cashier-pos .ticket-head-actions > span {
    padding: .35rem .55rem;
    border-radius: 999px;
    color: #e5f7ec;
    background: rgba(255, 255, 255, .12);
    font-size: .72rem;
    font-weight: 800;
}
.cashier-pos .ticket-column-head { background: #f3f8f5; color: #547164; }
.cashier-pos .ticket-empty { position: relative; padding: 42px 20px 20px; color: #698376; }
.cashier-pos .ticket-empty::before {
    position: absolute;
    top: 14px;
    left: 50%;
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    color: #24734a;
    background: #e7f5eb;
    font-size: 1.2rem;
    content: "▤";
    transform: translateX(-50%);
}
.cashier-pos .ticket-summary {
    margin-inline: 18px;
    padding: 13px 0;
    border-top: 1px solid #e5eee8;
}
.cashier-pos .ticket-summary > span,
.cashier-pos .ticket-discount { padding: .22rem 0; }
.cashier-pos .ticket-discount input { border-color: #cddfd3; border-radius: 8px; background: #f7fbf8; }
.cashier-pos .ticket-total {
    margin-inline: 18px;
    padding: 15px 17px;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(110deg, #0d4b2f, #197146);
    box-shadow: 0 10px 18px rgba(16, 91, 52, .2);
}
.cashier-pos .ticket-total strong { font-size: 1.38rem; letter-spacing: -.02em; }
.cashier-pos .tender-section { margin: 15px 18px 0; padding-top: 13px; border-top: 1px solid #e5eee8; }
.cashier-pos .tender-grid button {
    min-height: 44px;
    border-color: #d9e6de;
    border-radius: 10px;
    background: #fbfdfc;
    transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease;
}
.cashier-pos .tender-grid button:hover { transform: translateY(-1px); border-color: #9bcbb0; }
.cashier-pos .tender-grid button.active {
    border-color: #36915e;
    color: #125d38;
    background: #ecf8f0;
    box-shadow: inset 0 0 0 1px #36915e, 0 3px 8px rgba(27, 112, 66, .1);
}
.cashier-pos .payment-details {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(132px, auto);
    gap: 8px;
    margin: 10px 18px 0;
    align-items: end;
}
.cashier-pos .cash-received,
.cashier-pos .receipt-toggle,
.cashier-pos .paper-size {
    color: #62766d;
    font-size: .72rem;
    font-weight: 800;
}
.cashier-pos .cash-received {
    display: grid;
    gap: 6px;
}
.cashier-pos .money-input {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    min-height: 40px;
    padding: 0 11px;
    border: 1px solid #d5e2da;
    border-radius: 9px;
    background: #fff;
}
.cashier-pos .money-input span {
    color: #17623d;
    font-weight: 900;
}
.cashier-pos .money-input input {
    min-height: 38px;
    border: 0;
    background: transparent;
    text-align: right;
    font-weight: 800;
    box-shadow: none;
}
.cashier-pos .money-input input:focus {
    outline: none;
}
.cashier-pos .exact-cash,
.cashier-pos .change-due {
    min-height: 40px;
    border-radius: 9px;
    font-weight: 800;
}
.cashier-pos .exact-cash {
    padding: 0 13px;
    border: 1px solid #dbe6df;
    color: #213229;
    background: #fbfdfc;
}
.cashier-pos .exact-cash:hover:not(:disabled) {
    border-color: #9bcbb0;
    color: #17623d;
    background: #f0f8f3;
}
.cashier-pos .change-due {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 14px;
    color: #6a7e74;
    background: #e4f2eb;
}
.cashier-pos .change-due b {
    color: #1e8655;
    white-space: nowrap;
}
.cashier-pos .receipt-options {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 10px 18px 0;
}
.cashier-pos .receipt-toggle,
.cashier-pos .paper-size {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.cashier-pos .receipt-toggle input {
    width: 16px;
    height: 16px;
    accent-color: #248457;
}
.cashier-pos .paper-size select {
    min-height: 34px;
    min-width: 98px;
    padding: 0 10px;
    border: 1px solid #bfd7c9;
    border-radius: 9px;
    color: #213229;
    background: #f8fcfa;
    font-weight: 800;
}
.cashier-pos .checkout {
    min-height: 50px;
    margin-top: 14px;
    border: 0;
    border-radius: 12px;
    background: linear-gradient(110deg, #0c5b36, #1f8b57);
    box-shadow: 0 10px 20px rgba(15, 96, 54, .2);
    font-size: .98rem;
    letter-spacing: .01em;
}
.cashier-pos .checkout:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 13px 24px rgba(15, 96, 54, .26); }
@media (min-width: 1241px) {
    .pos-page.pos-focus-active {
        display: flex;
        flex-direction: column;
        width: 100%;
        height: 100dvh;
        min-height: 0;
        padding: max(14px, env(safe-area-inset-top)) 14px 14px;
        overflow: hidden;
        box-sizing: border-box;
    }
    .pos-page.pos-focus-active > .page-header,
    .pos-page.pos-focus-active > .notice {
        flex: 0 0 auto;
    }
    .pos-page.pos-focus-active > .notice {
        margin-bottom: 8px;
        padding-block: 8px;
    }
    .pos-page.pos-focus-active .pos-workstation {
        flex: 1 1 auto;
        width: 100%;
        min-height: 0;
        overflow: hidden;
    }
    .pos-page.pos-focus-active .product-library,
    .pos-page.pos-focus-active .sale-ticket {
        height: 100%;
        min-height: 0;
        max-height: none;
        margin-bottom: 0;
        overflow: hidden;
    }
    .pos-page.cashier-pos {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }
    .pos-page.cashier-pos > .page-header {
        display: none;
    }
    .pos-page.cashier-pos > .notice {
        flex: 0 0 auto;
        margin-bottom: 8px;
        padding-block: 8px;
    }
    .cashier-pos .pos-workstation {
        flex: 1 1 auto;
        width: 100%;
        min-height: 0;
        overflow: hidden;
    }
    .cashier-pos .product-library,
    .cashier-pos .sale-ticket {
        height: 100%;
        min-height: 0;
        max-height: none;
        margin-bottom: 0;
        overflow: hidden;
    }
    .cashier-pos .ticket-lines {
        min-height: 150px;
    }
    .cashier-pos .ticket-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.35rem 1rem;
        margin-inline: 14px;
        padding: 9px 0;
    }
    .cashier-pos .ticket-summary > span,
    .cashier-pos .ticket-discount {
        min-width: 0;
        font-size: 0.72rem;
    }
    .cashier-pos .ticket-summary > span {
        gap: 0.5rem;
    }
    .cashier-pos .ticket-summary b {
        white-space: nowrap;
    }
    .cashier-pos .ticket-total {
        margin-inline: 14px;
        padding: 11px 14px;
    }
    .cashier-pos .tender-section {
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        margin: 9px 14px 0;
    }
    .cashier-pos .tender-section > span {
        margin-right: 0.4rem;
    }
    .cashier-pos .tender-note {
        grid-column: 1 / -1;
    }
    .cashier-pos .payment-details,
    .cashier-pos .receipt-options {
        margin-inline: 14px;
        margin-top: 8px;
    }
    .cashier-pos .checkout {
        width: calc(100% - 28px);
        margin-inline: 14px;
    }
}
@media (min-width: 1241px) and (max-height: 760px) {
    .cashier-pos .pos-panel-head {
        min-height: 48px;
        padding: 9px 12px;
    }
    .cashier-pos .pos-scanner {
        padding: 8px 10px 6px;
    }
    .cashier-pos .pos-search {
        margin: 0 10px 7px;
    }
    .cashier-pos .pos-scanner input,
    .cashier-pos .pos-search input {
        min-height: 36px;
        padding-block: 0.45rem;
    }
    .cashier-pos .category-strip {
        padding: 0 10px 7px;
    }
    .cashier-pos .category-strip button {
        padding: 0.38rem 0.62rem;
    }
    .cashier-pos .pos-keys {
        grid-auto-rows: minmax(148px, auto);
        gap: 7px;
        padding: 9px;
    }
    .cashier-pos .pos-keys button {
        min-height: 148px;
        padding: 10px;
    }
    .cashier-pos .ticket-head {
        min-height: 52px;
        padding: 10px 16px;
    }
    .cashier-pos .ticket-head h2 {
        margin: 0.1rem 0;
        font-size: 1rem;
    }
    .cashier-pos .ticket-column-head {
        padding-block: 7px;
    }
    .cashier-pos .ticket-lines {
        min-height: 58px;
    }
    .cashier-pos .ticket-line {
        padding-block: 8px;
    }
    .cashier-pos .ticket-empty {
        min-height: 90px;
        padding: 16px;
    }
    .cashier-pos .ticket-summary {
        gap: 0.3rem 0.75rem;
        padding: 8px 0;
    }
    .cashier-pos .ticket-summary span,
    .cashier-pos .ticket-discount {
        font-size: 0.72rem;
    }
    .cashier-pos .ticket-discount input {
        width: 70px;
        min-height: 30px;
    }
    .cashier-pos .ticket-total {
        padding: 11px 14px;
        font-size: 1rem;
    }
    .cashier-pos .tender-section {
        gap: 0.35rem;
        margin-top: 8px;
    }
    .cashier-pos .tender-grid button {
        min-height: 32px;
    }
    .cashier-pos .payment-details {
        gap: 6px;
        margin-top: 7px;
    }
    .cashier-pos .money-input,
    .cashier-pos .exact-cash,
    .cashier-pos .change-due {
        min-height: 34px;
    }
    .cashier-pos .money-input input {
        min-height: 32px;
    }
    .cashier-pos .receipt-options {
        margin-top: 7px;
    }
    .cashier-pos .paper-size select {
        min-height: 30px;
    }
    .cashier-pos .checkout {
        min-height: 38px;
        margin-top: 8px;
        margin-bottom: 10px;
    }
}
@media (max-width: 1240px) {
    .cashier-fullscreen-button {
        display: none;
    }
    .pos-mobile-tabs {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
        margin: 0 auto 10px;
        padding: 5px;
        width: min(520px, 100%);
        border: 1px solid var(--line);
        border-radius: 12px;
        background: #fff;
        box-shadow: var(--shadow);
    }
    .pos-mobile-tabs button {
        min-height: 40px;
        border: 0;
        border-radius: 8px;
        color: var(--muted);
        background: transparent;
        font-weight: 800;
    }
    .pos-mobile-tabs button.active {
        color: #fff;
        background: var(--brand);
    }
    .pos-mobile-tabs b {
        display: inline-grid;
        place-items: center;
        min-width: 22px;
        height: 22px;
        margin-left: 0.3rem;
        border-radius: 999px;
        color: var(--dark);
        background: #fff;
        font-size: 0.72rem;
    }
    .pos-workstation { grid-template-columns: minmax(0, 1fr); }
    .product-library,
    .sale-ticket { min-height: 0; }
    .product-library { max-height: min(680px, 78dvh); }
    .sale-ticket { min-height: 620px; }
    .pos-keys { grid-template-columns: repeat(3,minmax(0,1fr)); max-height: 430px; }
    .mobile-panel-products .sale-ticket,
    .mobile-panel-cart .product-library { display: none; }
    .mobile-panel-cart .sale-ticket {
        min-height: min(680px, calc(100dvh - 190px));
    }
}
@media (max-width: 900px) {
    .pos-keys { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 700px) {
    .pos-scanner { grid-template-columns: 1fr 1fr; }
    .scan-field { grid-column: 1 / -1; }
    .pos-keys { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .ticket-column-head { display: none; }
    .ticket-line { grid-template-columns: minmax(0, 1fr); }
    .ticket-line > b { text-align: left; }
    .tender-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .cashier-pos .payment-details {
        grid-template-columns: 1fr;
    }
    .cashier-pos .receipt-options {
        align-items: flex-start;
        flex-direction: column;
    }
}
@media (max-width: 430px) {
    .pos-keys { grid-template-columns: 1fr; }
}
</style>
