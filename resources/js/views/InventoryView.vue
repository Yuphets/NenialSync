<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import { useAuthStore } from "../stores/auth";
import { useInventoryStore } from "../stores/inventory";
const auth = useAuthStore();
const inventory = useInventoryStore();
const message = ref("");
const editing = ref(null);
const showForm = ref(false);
const search = ref("");
const page = ref(1);
const pageSize = ref(5);
const empty = {
    name: "",
    sku: "",
    barcode: "",
    category: "Materials",
    supplier: "",
    unit: "pcs",
    price: 0,
    stock_quantity: 0,
    safety_stock: 0,
    reorder_level: 10,
    discount_percent: 0,
};
const form = reactive({ ...empty });
onMounted(async () => {
    await inventory.load();
    inventory.start();
});
onBeforeUnmount(() => inventory.stop());
function open(product = null) {
    editing.value = product;
    Object.assign(form, product || empty);
    showForm.value = true;
}
async function save() {
    try {
        editing.value
            ? await axios.put(`/api/products/${editing.value.id}`, form)
            : await axios.post("/api/products", form);
        showForm.value = false;
        message.value = "Product saved.";
        await inventory.load();
    } catch (error) {
        message.value =
            error.response?.data?.message || "Unable to save product.";
    }
}
async function adjust(product) {
    const value = prompt(
        `Stock change for ${product.name}. Use a negative number to deduct.`,
    );
    if (!value || Number(value) === 0) return;
    const reason = prompt("Reason for adjustment:");
    if (!reason) return;
    try {
        await axios.post(`/api/products/${product.id}/adjust`, {
            quantity_delta: Number(value),
            reason,
        });
        message.value = "Stock adjusted and logged.";
        await inventory.load();
    } catch (error) {
        message.value = error.response?.data?.message || "Adjustment failed.";
    }
}
const visibleProducts = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return inventory.products;

    return inventory.products.filter((product) =>
        [
            product.name,
            product.sku,
            product.barcode,
            product.category,
            product.supplier,
            product.unit,
            product.is_low_stock ? "reorder low stock" : "healthy",
        ]
            .join(" ")
            .toLowerCase()
            .includes(needle),
    );
});
const pagedProducts = computed(() =>
    visibleProducts.value.slice(
        (page.value - 1) * pageSize.value,
        page.value * pageSize.value,
    ),
);
</script>

<template>
    <PageHeader
        title="Inventory"
        subtitle="Exact on-hand, reserved, and sellable quantities"
        ><button
            v-if="auth.role === 'admin'"
            class="btn primary"
            @click="open()"
        >
            Add product
        </button></PageHeader
    >
    <p v-if="message" class="notice">{{ message }}</p>
    <section class="panel filters inline-filters">
        <label>Search inventory<input v-model="search" placeholder="Product, SKU, barcode, supplier, category"></label>
        <button v-if="search" class="btn" @click="search = ''">Clear search</button>
        <small>{{ visibleProducts.length }} of {{ inventory.products.length }} products shown</small>
    </section>
    <section class="panel table-wrap">
        <TablePager
            v-model:page="page"
            v-model:page-size="pageSize"
            :total="visibleProducts.length"
            label="products"
        />
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU / barcode</th>
                    <th>On hand</th>
                    <th>Reserved</th>
                    <th>Available</th>
                    <th>Price</th>
                    <th>Discount</th>
                    <th>Status</th>
                    <th v-if="auth.role === 'admin'">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="product in pagedProducts" :key="product.id">
                    <td data-label="Product">
                        <strong>{{ product.name }}</strong
                        ><small
                            >{{ product.category }} ·
                            {{ product.supplier }}</small
                        >
                    </td>
                    <td data-label="SKU / barcode">
                        {{ product.sku }}<small>{{ product.barcode }}</small>
                    </td>
                    <td data-label="On hand">
                        {{ product.stock_quantity }} {{ product.unit }}
                    </td>
                    <td data-label="Reserved">
                        {{ product.reserved_quantity }}
                    </td>
                    <td data-label="Available">
                        <strong>{{ product.available_quantity }}</strong>
                    </td>
                    <td data-label="Price">
                        ₱{{ Number(product.price).toLocaleString() }}
                    </td>
                    <td data-label="Discount">
                        <span class="tag" :class="{ warn: Number(product.discount_percent) > 0 }">{{ Number(product.discount_percent || 0).toLocaleString() }}%</span>
                        <small v-if="Number(product.discount_percent) > 0">Sale price ₱{{ (Number(product.price) * (1 - Number(product.discount_percent) / 100)).toLocaleString(undefined, { maximumFractionDigits: 2 }) }}</small>
                    </td>
                    <td data-label="Status">
                        <span
                            class="tag"
                            :class="{ warn: product.is_low_stock }"
                            >{{
                                product.is_low_stock ? "Reorder" : "Healthy"
                            }}</span
                        >
                    </td>
                    <td v-if="auth.role === 'admin'" data-label="Actions">
                        <div class="actions">
                            <button class="btn tiny" @click="open(product)">
                                Edit</button
                            ><button class="btn tiny" @click="adjust(product)">
                                Adjust
                            </button>
                        </div>
                    </td>
                </tr>
                <tr v-if="!visibleProducts.length" class="empty-row"><td :colspan="auth.role === 'admin' ? 9 : 8"><div class="empty">No products match your search.</div></td></tr>
            </tbody>
        </table>
    </section>
    <div v-if="showForm" class="modal">
        <form class="modal-card wide" @submit.prevent="save">
            <div class="panel-head">
                <h2>{{ editing ? "Edit" : "Add" }} product</h2>
                <button
                    type="button"
                    class="btn ghost"
                    @click="showForm = false"
                >
                    Close
                </button>
            </div>
            <div class="form-grid">
                <label>Name<input v-model="form.name" required /></label
                ><label>SKU<input v-model="form.sku" required /></label
                ><label>Barcode<input v-model="form.barcode" required /></label
                ><label
                    >Category<input v-model="form.category" required /></label
                ><label>Supplier<input v-model="form.supplier" /></label
                ><label>Unit<input v-model="form.unit" required /></label
                ><label
                    >Price<input
                        v-model.number="form.price"
                        type="number"
                        min=".01"
                        step=".01"
                        required /></label
                ><label v-if="!editing"
                    >Opening stock<input
                        v-model.number="form.stock_quantity"
                        type="number"
                        min="0" /></label
                ><label
                    >Safety stock<input
                        v-model.number="form.safety_stock"
                        type="number"
                        min="0" /></label
                ><label
                    >Reorder level<input
                        v-model.number="form.reorder_level"
                        type="number"
                        min="0" /></label
                ><label
                    >Discount %<input
                        v-model.number="form.discount_percent"
                        type="number"
                        min="0"
                        max="100"
                /></label>
            </div>
            <button class="btn primary">Save product</button>
        </form>
    </div>
</template>
