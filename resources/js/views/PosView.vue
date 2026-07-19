<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import axios from "axios";
import { BrowserMultiFormatReader } from "@zxing/browser";
import PageHeader from "../components/PageHeader.vue";
import { useInventoryStore } from "../stores/inventory";

const VAT_RATE = 0.12;
const inventory = useInventoryStore();
const search = ref("");
const barcode = ref("");
const selectedCategory = ref("All");
const cart = ref([]);
const message = ref("");
const busy = ref(false);
const showCamera = ref(false);
const video = ref(null);
const saleDiscountPercent = ref(0);
const paymentMethod = ref("cash");
const reader = new BrowserMultiFormatReader();
let scannerControls = null;
let checkoutKey = crypto.randomUUID();

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
const money = (value) =>
    Number(value).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

onMounted(async () => {
    await inventory.load();
    inventory.start();
});
onBeforeUnmount(() => {
    inventory.stop();
    scannerControls?.stop();
});

function add(product) {
    const item = cart.value.find((value) => value.id === product.id);
    const quantity = (item?.quantity || 0) + 1;
    if (quantity > product.available_quantity) {
        message.value = "Insufficient available stock.";
        return;
    }
    item ? item.quantity++ : cart.value.push({ ...product, quantity: 1 });
    barcode.value = "";
    message.value = "";
}

function scan() {
    const code = barcode.value.trim();
    const product = inventory.products.find(
        (item) =>
            item.barcode === code ||
            item.sku.toLowerCase() === code.toLowerCase(),
    );
    product ? add(product) : (message.value = "Barcode or SKU not found.");
}

function clearTicket() {
    if (!cart.value.length || confirm("Clear every item from this sale?")) {
        cart.value = [];
        saleDiscountPercent.value = 0;
    }
}

function closeCamera() {
    scannerControls?.stop();
    scannerControls = null;
    showCamera.value = false;
}

async function camera() {
    showCamera.value = true;
    await new Promise((resolve) => setTimeout(resolve));
    try {
        scannerControls = await reader.decodeFromVideoDevice(
            undefined,
            video.value,
            (result) => {
                if (result) {
                    barcode.value = result.getText();
                    closeCamera();
                    scan();
                }
            },
        );
    } catch {
        closeCamera();
        message.value =
            "Camera access failed. Use a USB scanner or grant camera permission.";
    }
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
    <PageHeader
        title="POS Terminal"
        subtitle="Fast counter checkout with transaction-safe stock deduction"
        ><span class="live">● Inventory live</span></PageHeader
    >
    <p v-if="message" class="notice">{{ message }}</p>
    <div class="pos-layout pos-workstation">
        <section class="panel compact-products product-library">
            <div class="panel-head pos-panel-head">
                <div>
                    <h2>Product library</h2>
                    <small>{{ products.length }} products available</small>
                </div>
                <span class="register-state">Register open</span>
            </div>
            <div class="scanner pos-scanner">
                <label class="scan-field"
                    >Barcode or SKU<input
                        v-model="barcode"
                        autofocus
                        placeholder="Scan or type product code"
                        @keyup.enter="scan"
                /></label>
                <button class="btn primary" @click="scan">Add item</button>
                <button class="btn" @click="camera">Camera</button>
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
                    :class="{ active: selectedCategory === category }"
                    @click="selectedCategory = category"
                >
                    {{ category }}
                </button>
            </nav>
            <div class="pos-keys">
                <button
                    v-for="product in products"
                    :key="product.id"
                    :disabled="!product.available_quantity"
                    @click="add(product)"
                >
                    <span class="product-tile-category">{{ product.category }}</span>
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
            <button
                class="btn primary full checkout"
                :disabled="!cart.length || busy"
                @click="checkout"
            >
                {{ busy ? "Processing…" : `Charge ₱${money(total)}` }}
            </button>
        </section>
    </div>
    <div v-if="showCamera" class="modal">
        <div class="modal-card">
            <div class="panel-head">
                <h2>Camera barcode scanner</h2>
                <button class="btn ghost" @click="closeCamera">Close</button>
            </div>
            <video ref="video"></video>
            <p>Position the barcode inside the camera view.</p>
        </div>
    </div>
</template>

<style scoped>
.pos-workstation { align-items: stretch; }
.product-library { display: flex; flex-direction: column; min-height: 680px; }
.pos-panel-head h2 { margin: 0 0 .2rem; }
.register-state { padding: .35rem .65rem; border-radius: 999px; color: var(--brand); background: var(--soft); font-size: .72rem; font-weight: 800; }
.pos-scanner { grid-template-columns: minmax(0, 1fr) auto auto; align-items: end; padding-bottom: 8px; }
.scan-field { min-width: 0; }
.pos-search { margin: 0 12px 10px; }
.category-strip { display: flex; gap: .4rem; padding: 0 12px 10px; overflow-x: auto; }
.category-strip button { flex: 0 0 auto; padding: .48rem .72rem; border: 1px solid var(--line); border-radius: 999px; color: var(--muted); background: #fff; font-size: .74rem; font-weight: 750; }
.category-strip button.active { border-color: var(--brand); color: #fff; background: var(--brand); }
.pos-keys { grid-template-columns: repeat(2, minmax(0, 1fr)); align-content: start; flex: 1; max-height: 520px; }
.pos-keys button { position: relative; align-content: start; min-height: 118px; padding: 13px; }
.pos-keys button:hover:not(:disabled) { border-color: #91bca2; background: #f4faf6; }
.product-tile-category { color: var(--muted); font-size: .64rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
.product-empty { grid-column: 1 / -1; }
.sale-ticket { display: flex; flex-direction: column; min-height: 680px; }
.ticket-head { padding: 17px 20px; }
.ticket-head-actions { display: flex; align-items: center; gap: .55rem; }
.clear-ticket { padding: .35rem .6rem; border: 1px solid rgba(255,255,255,.35); border-radius: 7px; color: #fff; background: transparent; font-size: .72rem; font-weight: 750; }
.ticket-column-head { display: grid; grid-template-columns: minmax(0,1fr) 116px 110px; gap: 12px; padding: 10px 18px; border-bottom: 1px solid var(--line); color: var(--muted); background: #f7faf8; font-size: .66rem; font-weight: 850; text-transform: uppercase; letter-spacing: .05em; }
.ticket-column-head span:nth-child(2) { text-align: center; }
.ticket-column-head span:last-child { text-align: right; }
.ticket-lines { flex: 1; min-height: 190px; max-height: 340px; overflow-y: auto; }
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
@media (max-width: 1280px) {
    .pos-workstation { grid-template-columns: minmax(320px,.78fr) minmax(430px,1.22fr); }
}
@media (max-width: 1050px) {
    .product-library, .sale-ticket { min-height: 0; }
    .pos-keys { grid-template-columns: repeat(3,minmax(0,1fr)); max-height: 430px; }
}
@media (max-width: 700px) {
    .pos-scanner { grid-template-columns: 1fr 1fr; }
    .scan-field { grid-column: 1 / -1; }
    .pos-keys { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .ticket-column-head { display: none; }
    .tender-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 430px) {
    .pos-keys { grid-template-columns: 1fr; }
}
</style>
