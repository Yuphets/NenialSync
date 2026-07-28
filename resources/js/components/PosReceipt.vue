<script setup>
import { computed, ref } from "vue";

const props = defineProps({
    sale: { type: Object, default: null },
    paperSize: { type: String, default: "80" },
});

const receipt = ref(null);
const profile = computed(() => props.sale?.receipt_profile || {});
const paymentLabel = computed(
    () =>
        ({
            cash: "Cash",
            card: "Card",
            gcash: "GCash",
            paymaya: "Maya",
        })[props.sale?.payment_method] || props.sale?.payment_method || "",
);
const completedAt = computed(() => {
    if (!props.sale?.completed_at) return "";
    return new Intl.DateTimeFormat("en-US", {
        timeZone: "Asia/Manila",
        month: "2-digit",
        day: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    }).format(new Date(props.sale.completed_at));
});
const vatPercent = computed(() =>
    Math.round(Number(props.sale?.vat_rate || 0.12) * 100),
);
const money = (value) =>
    Number(value || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

function print() {
    if (!props.sale || !receipt.value) return false;

    const frame = document.createElement("iframe");
    frame.setAttribute("title", "Nenial receipt print");
    frame.setAttribute("aria-hidden", "true");
    Object.assign(frame.style, {
        position: "fixed",
        right: "0",
        bottom: "0",
        width: "0",
        height: "0",
        border: "0",
        opacity: "0",
        pointerEvents: "none",
    });
    document.body.appendChild(frame);

    const width = props.paperSize === "58" ? "58mm" : "80mm";
    const documentRef = frame.contentDocument;
    const printWindow = frame.contentWindow;
    if (!documentRef || !printWindow) {
        frame.remove();
        return false;
    }
    documentRef.open();
    documentRef.write(`<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>${props.sale.reference || "Nenial receipt"}</title>
<style>
@page { size: auto; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: ${width}; background: #fff; color: #000; }
body { font-family: "Courier New", ui-monospace, monospace; font-size: ${props.paperSize === "58" ? "9px" : "10px"}; line-height: 1.3; }
.pos-receipt { width: ${width}; padding: 3mm; }
.receipt-business { text-align: center; }
.receipt-business h1 { margin: 0 0 2px; font-size: ${props.paperSize === "58" ? "15px" : "17px"}; line-height: 1.15; }
.receipt-business p, .receipt-footer p { margin: 1px 0; }
.receipt-rule { border: 0; border-top: 1px dashed #000; margin: 7px 0; }
.receipt-meta { display: grid; grid-template-columns: max-content 1fr; gap: 1px 6px; }
.receipt-meta b { text-align: right; overflow-wrap: anywhere; }
.receipt-items { width: 100%; border-collapse: collapse; table-layout: fixed; }
.receipt-items th { text-align: left; border-bottom: 1px solid #000; padding: 0 0 3px; }
.receipt-items th:last-child, .receipt-items td:last-child { text-align: right; }
.receipt-items td { vertical-align: top; padding: 4px 0; }
.receipt-items .receipt-item-main td { padding-bottom: 0; }
.receipt-items .receipt-item-detail td { padding-top: 0; color: #222; }
.receipt-items .item-description { width: 70%; overflow-wrap: anywhere; }
.receipt-items .item-amount { width: 30%; white-space: nowrap; }
.receipt-summary { display: grid; grid-template-columns: 1fr max-content; gap: 2px 8px; }
.receipt-summary b { text-align: right; white-space: nowrap; }
.receipt-total { font-size: 1.25em; font-weight: 700; padding: 3px 0; }
.receipt-footer { text-align: center; margin-top: 8px; }
.receipt-legal { font-size: .85em; margin-top: 5px !important; }
</style>
</head>
<body>${receipt.value.outerHTML}</body>
</html>`);
    documentRef.close();

    const removeFrame = () => {
        window.setTimeout(() => frame.remove(), 500);
    };
    printWindow.addEventListener("afterprint", removeFrame, {
        once: true,
    });
    window.setTimeout(() => {
        printWindow.focus();
        printWindow.print();
        window.setTimeout(removeFrame, 30000);
    }, 100);

    return true;
}

defineExpose({ print });
</script>

<template>
    <article
        v-if="sale"
        ref="receipt"
        class="pos-receipt"
        :data-paper-size="paperSize"
    >
        <header class="receipt-business">
            <h1>{{ profile.business_name || "Nenial" }}</h1>
            <p v-if="profile.address">{{ profile.address }}</p>
            <p v-if="profile.contact">Contact: {{ profile.contact }}</p>
            <p v-if="profile.tin">TIN: {{ profile.tin }}</p>
        </header>

        <hr class="receipt-rule" />
        <section class="receipt-meta">
            <span>SALE</span><b>{{ sale.reference }}</b>
            <span>DATE</span><b>{{ completedAt }}</b>
            <span>CASHIER</span><b>{{ sale.cashier?.name || "Cashier" }}</b>
            <span>REGISTER</span><b>Register 01</b>
        </section>
        <hr class="receipt-rule" />

        <table class="receipt-items">
            <thead>
                <tr>
                    <th class="item-description">ITEM</th>
                    <th class="item-amount">AMOUNT</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="item in sale.items || []" :key="item.id">
                    <tr class="receipt-item-main">
                        <td class="item-description">
                            <strong>{{ item.product_name }}</strong>
                        </td>
                        <td class="item-amount">
                            PHP {{ money(item.line_total) }}
                        </td>
                    </tr>
                    <tr class="receipt-item-detail">
                        <td colspan="2">
                            {{ item.quantity }} x PHP
                            {{ money(item.unit_price) }}
                            <template v-if="Number(item.discount_percent)">
                                ({{ Number(item.discount_percent) }}% off)
                            </template>
                            <span v-if="item.sku"> - {{ item.sku }}</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <hr class="receipt-rule" />
        <section class="receipt-summary">
            <span>Subtotal</span><b>PHP {{ money(sale.subtotal) }}</b>
            <span>Discount</span
            ><b>-PHP {{ money(sale.discount_total) }}</b>
            <span>VATable sales</span
            ><b>PHP {{ money(sale.vatable_sales) }}</b>
            <span>VAT ({{ vatPercent }}%, included)</span
            ><b>PHP {{ money(sale.vat_amount) }}</b>
            <span class="receipt-total">TOTAL</span
            ><b class="receipt-total">PHP {{ money(sale.total) }}</b>
        </section>

        <hr class="receipt-rule" />
        <section class="receipt-summary">
            <span>Payment</span><b>{{ paymentLabel }}</b>
            <template v-if="sale.payment_method === 'cash'">
                <span>Cash received</span
                ><b>PHP {{ money(sale.amount_tendered) }}</b>
                <span>Change</span
                ><b>PHP {{ money(sale.change_due) }}</b>
            </template>
        </section>

        <footer class="receipt-footer">
            <hr class="receipt-rule" />
            <p>{{ profile.footer || "Thank you for your purchase." }}</p>
            <p>Please keep this receipt.</p>
            <p class="receipt-legal" v-if="profile.legal_note">
                {{ profile.legal_note }}
            </p>
        </footer>
    </article>
</template>

<style scoped>
.pos-receipt {
    display: none;
}
</style>
