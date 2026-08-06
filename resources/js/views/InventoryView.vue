<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import UiIcon from "../components/UiIcon.vue";
import { useAuthStore } from "../stores/auth";
import { useInventoryStore } from "../stores/inventory";

const auth = useAuthStore();
const inventory = useInventoryStore();
const message = ref("");
const editing = ref(null);
const removingId = ref(null);
const showForm = ref(false);
const stockAdjustment = ref(0);
const adjustmentReason = ref("");
const search = ref("");
const page = ref(1);
const pageSize = ref(5);
const productArtwork = { "CON-001": "/media/portland-cement-40kg.png", "CON-002": "/media/steel-bar.jpg", "CON-003": "/media/plywood.png", "AGG-001": "/media/washed-sand.webp", "AGG-002": "/media/crushed-stones.webp", "AGG-003": "/media/filling-sand.avif", "CON-005": "/media/safety-helmet-with-chin-strap.png", "CON-006": "/media/masonry-tool-set.png" };
const empty = { name: "", sku: "", barcode: "", category: "Materials", supplier: "", unit: "pcs", price: 0, stock_quantity: 0, safety_stock: 0, reorder_level: 10, discount_percent: 0 };
const form = reactive({ ...empty });

onMounted(async () => { await inventory.load(); inventory.start(); });
onBeforeUnmount(() => inventory.stop());
function open(product = null) {
    editing.value = product;
    Object.assign(form, product || empty);
    stockAdjustment.value = 0;
    adjustmentReason.value = "";
    showForm.value = true;
}
async function save() {
    const adjustment = Number(stockAdjustment.value || 0);
    if (editing.value && adjustment !== 0 && !adjustmentReason.value.trim()) {
        message.value = "Enter a reason for the stock adjustment.";
        return;
    }

    try {
        if (editing.value) {
            await axios.put(`/api/products/${editing.value.id}`, form);
            if (adjustment !== 0) {
                await axios.post(`/api/products/${editing.value.id}/adjust`, {
                    quantity_delta: adjustment,
                    reason: adjustmentReason.value.trim(),
                });
            }
        } else {
            await axios.post("/api/products", form);
        }
        showForm.value = false;
        message.value = adjustment !== 0
            ? "Product details and stock balance updated."
            : "Product saved.";
        await inventory.load();
    } catch (error) { message.value = error.response?.data?.message || "Unable to save product."; }
}
async function removeProduct(product) {
    const stockNote = Number(product.stock_quantity) > 0
        ? ` It currently has ${product.stock_quantity} ${product.unit} on hand.`
        : "";
    if (!confirm(`Remove "${product.name}" from Inventory?${stockNote}\n\nIt will no longer appear in the storefront or POS. Historical sales and inventory records will be retained.`)) return;

    removingId.value = product.id;
    try {
        await axios.delete(`/api/products/${product.id}`);
        message.value = `${product.name} was removed from Inventory.`;
        await inventory.load();
    } catch (error) {
        message.value = error.response?.data?.message || "Unable to remove this product.";
    } finally {
        removingId.value = null;
    }
}
const visibleProducts = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return inventory.products;
    return inventory.products.filter((product) => [product.name, product.sku, product.barcode, product.category, product.supplier, product.unit, product.is_low_stock ? "reorder low stock" : "healthy"].join(" ").toLowerCase().includes(needle));
});
const pagedProducts = computed(() => visibleProducts.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value));
const productImage = (product) => {
    const configured = String(product.image_url || "").trim();
    return configured && !configured.endsWith("/Background.jpg") ? configured : productArtwork[product.sku] || configured || "/media/Background.jpg";
};
</script>

<template>
    <PageHeader title="Inventory" subtitle="Exact on-hand, reserved, and sellable quantities"><button v-if="auth.role === 'admin'" class="btn primary inventory-add" @click="open()"><UiIcon name="plus" :size="17" /> Add product</button></PageHeader>
    <p v-if="message" class="notice">{{ message }}</p>
    <section class="panel inventory-search-panel">
        <label class="inventory-search"><span class="sr-only">Search inventory</span><UiIcon name="search" :size="18" /><input v-model="search" placeholder="Product, SKU, barcode, supplier, category"></label>
        <button v-if="search" class="btn tiny clear-search" @click="search = ''">Clear</button>
        <small>{{ visibleProducts.length }} of {{ inventory.products.length }} products shown</small>
    </section>
    <section class="panel table-wrap inventory-table">
        <TablePager v-model:page="page" v-model:page-size="pageSize" :total="visibleProducts.length" label="products" />
        <table>
            <thead><tr><th>Product</th><th>SKU / barcode</th><th>On hand</th><th>Reserved</th><th>Available</th><th>Price</th><th>Discount</th><th>Status</th><th v-if="auth.role === 'admin'">Actions</th></tr></thead>
            <tbody>
                <tr v-for="product in pagedProducts" :key="product.id">
                    <td data-label="Product"><div class="inventory-product"><img :src="productImage(product)" alt="" @error="$event.target.src = '/media/Background.jpg'"><div><strong>{{ product.name }}</strong><small>{{ product.category }} &middot; {{ product.supplier || 'No supplier' }}</small></div></div></td>
                    <td data-label="SKU / barcode">{{ product.sku }}<small>{{ product.barcode }}</small></td>
                    <td data-label="On hand">{{ product.stock_quantity }} {{ product.unit }}</td>
                    <td data-label="Reserved">{{ product.reserved_quantity }}</td>
                    <td data-label="Available"><strong class="available-quantity">{{ product.available_quantity }}</strong></td>
                    <td data-label="Price">₱{{ Number(product.price).toLocaleString() }}</td>
                    <td data-label="Discount"><span class="tag discount-tag" :class="{ warn: Number(product.discount_percent) > 0 }">{{ Number(product.discount_percent || 0).toLocaleString() }}%</span><small v-if="Number(product.discount_percent) > 0">Sale price ₱{{ (Number(product.price) * (1 - Number(product.discount_percent) / 100)).toLocaleString(undefined, { maximumFractionDigits: 2 }) }}</small></td>
                    <td data-label="Status"><span class="tag inventory-status" :class="{ warn: product.is_low_stock }"><UiIcon :name="product.is_low_stock ? 'clock' : 'shield'" :size="14" />{{ product.is_low_stock ? "Reorder" : "Healthy" }}</span></td>
                    <td v-if="auth.role === 'admin'" data-label="Actions"><div class="actions"><button class="btn tiny inventory-action" @click="open(product)"><UiIcon name="edit" :size="15" /> Edit</button><button class="btn tiny danger" :disabled="removingId === product.id" @click="removeProduct(product)"><UiIcon name="trash" :size="15" />{{ removingId === product.id ? "Removing…" : "Remove" }}</button></div></td>
                </tr>
                <tr v-if="!visibleProducts.length" class="empty-row"><td :colspan="auth.role === 'admin' ? 9 : 8"><div class="empty">No products match your search.</div></td></tr>
            </tbody>
        </table>
    </section>
    <div v-if="showForm" class="modal"><form class="modal-card wide" @submit.prevent="save"><div class="panel-head"><div><h2>{{ editing ? "Edit product" : "Add product" }}</h2><small v-if="editing">Update product information and stock from one place.</small></div><button type="button" class="btn ghost" @click="showForm = false">Close</button></div><div class="form-grid"><label>Name<input v-model="form.name" required></label><label>SKU<input v-model="form.sku" required></label><label>Barcode<input v-model="form.barcode" required></label><label>Category<input v-model="form.category" required></label><label>Supplier<input v-model="form.supplier"></label><label>Unit<input v-model="form.unit" required></label><label>Price<input v-model.number="form.price" type="number" min=".01" step=".01" required></label><label v-if="!editing">Opening stock<input v-model.number="form.stock_quantity" type="number" min="0"></label><label>Safety stock<input v-model.number="form.safety_stock" type="number" min="0"></label><label>Reorder level<input v-model.number="form.reorder_level" type="number" min="0"></label><label>Discount %<input v-model.number="form.discount_percent" type="number" min="0" max="100"></label><fieldset v-if="editing" class="stock-adjustment-fields"><legend>Stock adjustment <small>Current on hand: {{ editing.stock_quantity }} {{ editing.unit }}</small></legend><label>Quantity change<input v-model.number="stockAdjustment" type="number" step="1" placeholder="Use + to add or − to deduct"></label><label>Adjustment reason<input v-model="adjustmentReason" :required="Number(stockAdjustment) !== 0" maxlength="255" placeholder="Delivery received, damaged stock, physical count…"></label><p>Leave the quantity at 0 when only editing product information. Every stock change is recorded in inventory activity.</p></fieldset></div><button class="btn primary">{{ editing ? "Save product changes" : "Add product" }}</button></form></div>
</template>
