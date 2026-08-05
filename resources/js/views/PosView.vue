<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import PosReceipt from "../components/PosReceipt.vue";
import UiIcon from "../components/UiIcon.vue";
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
const lastReceipt = ref(null);
const receiptPrint = ref(null);
const readPreference = (key, fallback) => {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
};
const receiptPaper = ref(readPreference("nenial-receipt-paper", "80"));
const autoPrintReceipt = ref(
    readPreference("nenial-receipt-auto-print", "true") !== "false",
);
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
const cashReceivedAmount = computed(() => Number(cashReceived.value) || 0);
const changeDue = computed(() =>
    Math.max(0, cashReceivedAmount.value - total.value),
);
const tenderIsValid = computed(
    () =>
        paymentMethod.value !== "cash" ||
        cashReceivedAmount.value + 0.001 >= total.value,
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
const categoryTone = (category) =>
    category === "All"
        ? "category-tone--all"
        : productTone(category).replace("product-tile--", "category-tone--");
const productIcon = (category) =>
    ({
        Aggregates: "inventory",
        Materials: "package",
        Finishing: "orders",
        Tools: "adjust",
        Safety: "shield",
    })[category] || "package";

onMounted(async () => {
    document.addEventListener("keydown", captureScannerKey, true);
    document.addEventListener("fullscreenchange", syncFullscreenState);
    syncFullscreenState();
    restoreLastReceipt();
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
    }
}

function exactCash() {
    cashReceived.value = total.value.toFixed(2);
}

function saveReceiptPreferences() {
    try {
        localStorage.setItem("nenial-receipt-paper", receiptPaper.value);
        localStorage.setItem(
            "nenial-receipt-auto-print",
            String(autoPrintReceipt.value),
        );
    } catch {
        // Printing still works when browser storage is unavailable.
    }
}

function restoreLastReceipt() {
    try {
        const stored = sessionStorage.getItem("nenial-last-pos-receipt");
        if (stored) lastReceipt.value = JSON.parse(stored);
    } catch {
        try {
            sessionStorage.removeItem("nenial-last-pos-receipt");
        } catch {
            // Reprint persistence is optional.
        }
    }
}

async function printLastReceipt() {
    if (!lastReceipt.value) return;
    await nextTick();
    if (!receiptPrint.value?.print()) {
        message.value = "The receipt could not be opened for printing.";
    }
}

async function checkout() {
    if (!tenderIsValid.value) {
        message.value =
            "Enter cash received equal to or greater than the amount due.";
        return;
    }
    busy.value = true;
    try {
        const { data } = await axios.post("/api/pos/checkout", {
            items: cart.value.map((item) => ({
                product_id: item.id,
                quantity: item.quantity,
            })),
            payment_method: paymentMethod.value,
            discount_percent: Number(saleDiscountPercent.value) || 0,
            amount_tendered:
                paymentMethod.value === "cash"
                    ? cashReceivedAmount.value
                    : total.value,
            idempotency_key: checkoutKey,
        });
        lastReceipt.value = data;
        try {
            sessionStorage.setItem(
                "nenial-last-pos-receipt",
                JSON.stringify(data),
            );
        } catch {
            // The current receipt remains available even without session storage.
        }
        message.value = `Sale ${data.reference} completed · ₱${money(data.total)} (VAT ₱${money(data.vat_amount)})`;
        cart.value = [];
        saleDiscountPercent.value = 0;
        paymentMethod.value = "cash";
        cashReceived.value = "";
        mobilePanel.value = "products";
        checkoutKey = crypto.randomUUID();
        await inventory.load();
        if (autoPrintReceipt.value) await printLastReceipt();
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
                <UiIcon :name="isFullscreen ? 'minimize' : 'fullscreen'" :size="18" />
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
                        <UiIcon :name="isFullscreen ? 'minimize' : 'fullscreen'" :size="16" />
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
                    :class="[
                        categoryTone(category),
                        { active: selectedCategory === category },
                    ]"
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
                        <span class="product-tile-icon" aria-hidden="true"><UiIcon :name="productIcon(product.category)" :size="18" /></span>
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
                <div v-if="paymentMethod === 'cash'" class="cash-tender-entry">
                    <label>
                        <span>Cash received</span>
                        <span class="money-input"
                            ><b>₱</b
                            ><input
                                v-model="cashReceived"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="0.00"
                                @keyup.enter="checkout"
                        /></span>
                    </label>
                    <button class="btn tiny" type="button" @click="exactCash">
                        Exact cash
                    </button>
                    <span class="change-preview"
                        >Change <strong>₱{{ money(changeDue) }}</strong></span
                    >
                </div>
                <div class="receipt-controls">
                    <label class="receipt-auto-print">
                        <input
                            v-model="autoPrintReceipt"
                            type="checkbox"
                            @change="saveReceiptPreferences"
                        />
                        Print receipt automatically
                    </label>
                    <label class="receipt-paper">
                        Paper
                        <select
                            v-model="receiptPaper"
                            @change="saveReceiptPreferences"
                        >
                            <option value="80">80 mm</option>
                            <option value="58">58 mm</option>
                        </select>
                    </label>
                    <button
                        v-if="lastReceipt"
                        class="btn tiny"
                        type="button"
                        @click="printLastReceipt"
                    >
                        Reprint last
                    </button>
                </div>
            </div>
            <button
                class="btn primary full checkout"
                :disabled="!cart.length || busy || !tenderIsValid"
                @click="checkout"
            >
                {{ busy ? "Processing…" : `Charge ₱${money(total)}` }}
            </button>
        </section>
    </div>
    <PosReceipt
        ref="receiptPrint"
        :sale="lastReceipt"
        :paper-size="receiptPaper"
    />
    </div>
</template>

<style scoped>
.pos-page { min-width: 0; box-sizing: border-box; }
.pos-header-actions { align-items: center; flex-wrap: wrap; }
.pos-header-actions .btn { display: inline-flex; align-items: center; gap: .45rem; white-space: nowrap; }
.pos-panel-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .45rem;
}
.cashier-fullscreen-button {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
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
.cash-tender-entry {
    display: grid;
    grid-template-columns: minmax(170px, 1fr) auto minmax(145px, auto);
    align-items: end;
    gap: .5rem;
}
.cash-tender-entry label {
    display: grid;
    gap: .25rem;
    color: var(--muted);
    font-size: .72rem;
    font-weight: 750;
}
.money-input {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    min-height: 38px;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #fff;
}
.money-input:focus-within {
    border-color: var(--brand);
    box-shadow: 0 0 0 2px rgba(23, 120, 74, .12);
}
.money-input b {
    padding-left: .7rem;
    color: var(--brand);
}
.money-input input {
    width: 100%;
    min-height: 36px;
    border: 0;
    border-radius: 0;
    text-align: right;
    box-shadow: none;
}
.change-preview {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .65rem;
    min-height: 38px;
    padding: .45rem .7rem;
    border-radius: 8px;
    color: var(--muted);
    background: var(--soft);
    font-size: .76rem;
    font-weight: 700;
}
.change-preview strong {
    color: var(--brand);
    white-space: nowrap;
}
.receipt-controls {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .45rem .75rem;
    color: var(--muted);
    font-size: .7rem;
}
.receipt-auto-print,
.receipt-paper {
    display: flex;
    align-items: center;
    gap: .35rem;
    font-weight: 700;
}
.receipt-auto-print input {
    width: 16px;
    min-height: 16px;
    accent-color: var(--brand);
}
.receipt-paper select {
    width: auto;
    min-height: 30px;
    padding: .25rem 1.8rem .25rem .55rem;
    font-size: .72rem;
}

/* Cashier branch presentation layer. These selectors intentionally leave the
   scanner, checkout, payment, and receipt behavior above unchanged. */
.cashier-pos .product-library,
.cashier-pos .sale-ticket {
    border: 1px solid #dbe7df;
    border-radius: 18px;
    background: rgba(255, 255, 255, .96);
    box-shadow: 0 16px 38px rgba(18, 64, 42, .1);
}
.cashier-pos .pos-panel-head {
    min-height: 66px;
    padding: 14px 16px;
    border-bottom-color: #e4eee8;
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
.cashier-pos .category-strip { padding-bottom: 12px; border-bottom: 1px solid #edf3ef; }
.cashier-pos .category-strip button {
    --category-color: #668274;
    display: inline-flex;
    align-items: center;
    gap: .38rem;
    border-color: #dce8e0;
    color: #537062;
    background: #fbfdfc;
    transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease;
}
.cashier-pos .category-strip button:not(.category-tone--all)::before {
    width: .42rem;
    height: .42rem;
    border-radius: 50%;
    background: var(--category-color);
    content: "";
}
.cashier-pos .category-strip button:hover { transform: translateY(-1px); border-color: var(--category-color); }
.cashier-pos .category-strip button.active {
    border-color: var(--category-color);
    color: #fff;
    background: var(--category-color);
    box-shadow: 0 5px 12px rgba(25, 112, 68, .2);
}
.cashier-pos .category-strip button.active::before { display: none; }
.cashier-pos .category-tone--aggregates { --category-color: #b66c14; }
.cashier-pos .category-tone--materials { --category-color: #356b9f; }
.cashier-pos .category-tone--finishing { --category-color: #81543a; }
.cashier-pos .category-tone--tools { --category-color: #ad402d; }
.cashier-pos .category-tone--safety { --category-color: #b9252b; }
.cashier-pos .category-tone--all { --category-color: #176f45; }
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
.cashier-pos .product-tile-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .5rem;
    margin-bottom: .65rem;
}
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
}
.cashier-pos .product-tile-category {
    padding: .24rem .5rem;
    border-radius: 999px;
    color: var(--tile-color);
    background: var(--tile-tint);
}
.cashier-pos .product-tile--aggregates { --tile-color: #b66c14; --tile-tint: #fff1dc; }
.cashier-pos .product-tile--materials { --tile-color: #356b9f; --tile-tint: #e8f0fb; }
.cashier-pos .product-tile--finishing { --tile-color: #81543a; --tile-tint: #f6ebe5; }
.cashier-pos .product-tile--tools { --tile-color: #ad402d; --tile-tint: #fbe9e5; }
.cashier-pos .product-tile--safety { --tile-color: #b9252b; --tile-tint: #fbe8e9; }
.cashier-pos .sale-ticket { background: #fff; }
.cashier-pos .ticket-head {
    background: linear-gradient(115deg, #0c3c27, #17623d 70%, #257a50);
    box-shadow: inset 0 -1px rgba(255, 255, 255, .12);
}
.cashier-pos .ticket-head small { color: #b9ddc7; font-weight: 800; letter-spacing: .08em; }
.cashier-pos .ticket-head-actions > span {
    padding: .35rem .55rem;
    border-radius: 999px;
    color: #e5f7ec;
    background: rgba(255, 255, 255, .12);
    font-size: .72rem;
    font-weight: 800;
}
.cashier-pos .ticket-column-head { color: #547164; background: #f3f8f5; }
.cashier-pos .ticket-summary { margin-inline: 18px; padding: 13px 0; border-top: 1px solid #e5eee8; }
.cashier-pos .ticket-total {
    margin-inline: 18px;
    padding: 15px 17px;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(110deg, #0d4b2f, #197146);
    box-shadow: 0 10px 18px rgba(16, 91, 52, .2);
}
.cashier-pos .ticket-total strong { font-size: 1.38rem; }
.cashier-pos .tender-grid button { min-height: 44px; border-color: #d9e6de; border-radius: 10px; background: #fbfdfc; }
.cashier-pos .tender-grid button.active {
    border-color: #36915e;
    color: #125d38;
    background: #ecf8f0;
    box-shadow: inset 0 0 0 1px #36915e, 0 3px 8px rgba(27, 112, 66, .1);
}
.cashier-pos .cash-tender-entry { margin-top: 2px; }
.cashier-pos .money-input { border-color: #d5e2da; border-radius: 9px; }
.cashier-pos .change-preview { border-radius: 9px; background: #e4f2eb; }
.cashier-pos .receipt-controls { padding-top: 2px; }
.cashier-pos .checkout {
    min-height: 50px;
    margin-top: 14px;
    border: 0;
    border-radius: 12px;
    background: linear-gradient(110deg, #0c5b36, #1f8b57);
    box-shadow: 0 10px 20px rgba(15, 96, 54, .2);
    font-size: .98rem;
}
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
    .cashier-pos .cash-tender-entry,
    .cashier-pos .receipt-controls {
        grid-column: 1 / -1;
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
    .cashier-pos .cash-tender-entry {
        grid-template-columns: minmax(160px, 1fr) auto minmax(135px, auto);
    }
    .cashier-pos .money-input,
    .cashier-pos .change-preview {
        min-height: 32px;
    }
    .cashier-pos .money-input input {
        min-height: 30px;
        padding-block: .3rem;
    }
    .cashier-pos .receipt-controls {
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
    .cash-tender-entry { grid-template-columns: minmax(0, 1fr) auto; }
    .change-preview { grid-column: 1 / -1; }
}
@media (max-width: 430px) {
    .pos-keys { grid-template-columns: 1fr; }
}
</style>
