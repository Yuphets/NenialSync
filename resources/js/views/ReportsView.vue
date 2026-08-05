<script setup>
import { computed, onMounted, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const data = ref({});
const showBackup = ref(false);
const backupPassword = ref("");
const backupBusy = ref(false);
const backupError = ref("");
const manilaDateKey = (value = new Date()) => {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return "";
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US", {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            timeZone: "Asia/Manila",
        })
            .formatToParts(date)
            .map(({ type, value: part }) => [type, part]),
    );

    return `${parts.year}-${parts.month}-${parts.day}`;
};
const today = manilaDateKey();
const from = ref(`${today.slice(0, 7)}-01`);
const to = ref(today);
const payrollSearch = ref("");
const payrollFrom = ref("");
const payrollTo = ref("");
const inventorySearch = ref("");
const inventoryFrom = ref("");
const inventoryTo = ref("");
const payrollPage = ref(1);
const payrollPageSize = ref(5);
const inventoryPage = ref(1);
const inventoryPageSize = ref(5);
const inDateRange = (value, fromValue, toValue) => {
    if (!fromValue && !toValue) return true;
    if (!value) return false;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return false;
    const day = manilaDateKey(date);

    return (!fromValue || day >= fromValue) && (!toValue || day <= toValue);
};
const filteredPayrollRuns = computed(() => {
    const needle = payrollSearch.value.trim().toLowerCase();

    return (data.value.payroll?.runs || []).filter((run) => {
        const matchesSearch =
            !needle ||
            [
                run.reference,
                run.status,
                run.created_by_name || run.creator?.name,
                run.period_start,
                run.period_end,
                run.items_count,
                run.gross_pay,
                run.net_pay,
            ]
                .join(" ")
                .toLowerCase()
                .includes(needle);
        const dateValue = run.finalized_at || run.period_end || run.updated_at;

        return matchesSearch && inDateRange(dateValue, payrollFrom.value, payrollTo.value);
    });
});
const filteredInventoryStats = computed(() => {
    const needle = inventorySearch.value.trim().toLowerCase();

    return (data.value.inventory || []).filter((product) => {
        const matchesSearch =
            !needle ||
            [
                product.name,
                product.sku,
                product.category,
                product.supplier,
                product.available_quantity,
                product.stock_quantity,
                product.reserved_quantity,
            ]
                .join(" ")
                .toLowerCase()
                .includes(needle);
        const dateValue = product.updated_at || product.created_at;

        return matchesSearch && inDateRange(dateValue, inventoryFrom.value, inventoryTo.value);
    });
});
const pagedPayrollRuns = computed(() =>
    filteredPayrollRuns.value.slice(
        (payrollPage.value - 1) * payrollPageSize.value,
        payrollPage.value * payrollPageSize.value,
    ),
);
const pagedInventoryStats = computed(() =>
    filteredInventoryStats.value.slice(
        (inventoryPage.value - 1) * inventoryPageSize.value,
        inventoryPage.value * inventoryPageSize.value,
    ),
);
const money = (value) =>
    Number(value || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
const dateTime = (value) =>
    value
        ? new Date(value).toLocaleString("en-US", { timeZone: "Asia/Manila" })
        : "—";
async function load() {
    data.value = (
        await axios.get("/api/reports", {
            params: { from: from.value, to: to.value },
        })
    ).data;
}
onMounted(load);
function csv() {
    const rows = [
        ["NENIAL COMPANY REPORT"],
        ["From", from.value],
        ["To", to.value],
        [],
        ["Sales total", data.value.sales?.total],
        ["VATable sales", data.value.sales?.vatable_sales],
        ["VAT collected", data.value.sales?.vat_amount],
        ["Transactions", data.value.sales?.count],
        ["Orders", data.value.orders_summary?.count],
        ["Open orders", data.value.orders_summary?.pending],
        ["Attendance records", data.value.attendance_summary?.records],
        ["Employees represented", data.value.attendance_summary?.employees],
        ["Active employees", data.value.employees?.active],
        ["Finalized payroll net", data.value.payroll?.net_total],
        ["Inventory value", data.value.inventory_summary?.value],
        ["Low-stock products", data.value.inventory_summary?.low_stock],
        [],
        ["ORDER STATUS", "Count", "Value"],
        ...(data.value.orders || []).map((row) => [
            row.status,
            row.count,
            row.total,
        ]),
        [],
        ["ATTENDANCE STATUS", "Count"],
        ...(data.value.attendance || []).map((row) => [row.status, row.count]),
        [],
        [
            "PAYROLL RUN",
            "Period start",
            "Period end",
            "Employees",
            "Gross",
            "Net",
            "Finalized",
        ],
        ...(data.value.payroll?.runs || []).map((run) => [
            run.reference,
            run.period_start,
            run.period_end,
            run.items_count,
            run.gross_pay,
            run.net_pay,
            run.finalized_at,
        ]),
        [],
        ["PRODUCT", "SKU", "On hand", "Reserved", "Available", "Value"],
        ...filteredInventoryStats.value.map((product) => [
            product.name,
            product.sku,
            product.stock_quantity,
            product.reserved_quantity,
            product.available_quantity,
            product.stock_quantity * product.price,
        ]),
    ];
    const blob = new Blob(
        [
            rows
                .map((row) =>
                    row
                        .map(
                            (value) =>
                                `"${String(value ?? "").replaceAll('"', '""')}"`,
                        )
                        .join(","),
                )
                .join("\n"),
        ],
        { type: "text/csv" },
    );
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `nenial-company-report-${from.value}-to-${to.value}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

async function downloadBackup() {
    backupBusy.value = true;
    backupError.value = "";
    try {
        const response = await axios.post("/api/admin/backup", { current_password: backupPassword.value }, { responseType: "blob" });
        const disposition = response.headers["content-disposition"] || "";
        const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || `nenial-company-backup-${new Date().toISOString().slice(0, 10)}.json`;
        const url = URL.createObjectURL(response.data);
        const link = document.createElement("a");
        link.href = url; link.download = filename; link.click();
        URL.revokeObjectURL(url);
        showBackup.value = false; backupPassword.value = "";
    } catch (exception) {
        if (exception.response?.data instanceof Blob) {
            try { backupError.value = JSON.parse(await exception.response.data.text()).message; } catch { backupError.value = "Backup authorization failed."; }
        } else backupError.value = exception.response?.data?.message || "Backup could not be prepared.";
    } finally { backupBusy.value = false; }
}
</script>

<template>
    <PageHeader
        title="Company reports"
        subtitle="Sales, orders, attendance, payroll, workforce, and inventory in one report"
        ><div class="actions report-actions"><button class="btn report-action" @click="csv">Download report CSV</button><button v-if="auth.role === 'admin'" class="btn primary report-action" @click="showBackup = true">Backup all company data</button></div></PageHeader
    >
    <section class="panel filters report-period-filter">
        <label>From<input v-model="from" type="date" /></label
        ><label>To<input v-model="to" type="date" /></label
        ><button class="btn primary report-period-button" @click="load">Apply period</button>
    </section>
    <div class="stat-grid report-stats">
        <article class="stat">
            <span>Sales</span><strong>₱{{ money(data.sales?.total) }}</strong
            ><small
                >{{ data.sales?.count || 0 }} transactions · VAT ₱{{
                    money(data.sales?.vat_amount)
                }}</small
            >
        </article>
        <article class="stat">
            <span>Orders</span
            ><strong>{{ data.orders_summary?.count || 0 }}</strong
            ><small
                >{{ data.orders_summary?.pending || 0 }} open · ₱{{
                    money(data.orders_summary?.value)
                }}</small
            >
        </article>
        <article class="stat">
            <span>Attendance</span
            ><strong>{{ data.attendance_summary?.records || 0 }}</strong
            ><small
                >{{ data.attendance_summary?.employees || 0 }} employees
                represented</small
            >
        </article>
        <article class="stat">
            <span>Finalized payroll</span
            ><strong>₱{{ money(data.payroll?.net_total) }}</strong
            ><small
                >{{ data.payroll?.runs?.length || 0 }} payroll snapshots</small
            >
        </article>
        <article class="stat">
            <span>Inventory value</span
            ><strong>₱{{ money(data.inventory_summary?.value) }}</strong
            ><small
                >{{ data.inventory_summary?.low_stock || 0 }} low-stock
                products</small
            >
        </article>
        <article class="stat">
            <span>Active workforce</span
            ><strong>{{ data.employees?.active || 0 }}</strong
            ><small>Current employees</small>
        </article>
    </div>
    <div class="two-col report-columns">
        <section class="panel table-wrap compact-table">
            <div class="panel-head"><h2>Order statistics</h2></div>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Orders</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in data.orders || []" :key="row.status">
                        <td data-label="Status">
                            <span class="tag">{{ row.status }}</span>
                        </td>
                        <td data-label="Orders">{{ row.count }}</td>
                        <td data-label="Value">₱{{ money(row.total) }}</td>
                    </tr>
                    <tr v-if="!data.orders?.length" class="empty-row">
                        <td colspan="3">No orders in this period.</td>
                    </tr>
                </tbody>
            </table>
        </section>
        <section class="panel table-wrap compact-table">
            <div class="panel-head"><h2>Attendance statistics</h2></div>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Records</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in data.attendance || []" :key="row.status">
                        <td data-label="Status">
                            <span class="tag">{{ row.status }}</span>
                        </td>
                        <td data-label="Records">{{ row.count }}</td>
                    </tr>
                    <tr v-if="!data.attendance?.length" class="empty-row">
                        <td colspan="2">No attendance in this period.</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
    <section class="panel table-wrap report-detail-panel">
        <div class="panel-head">
            <div>
                <h2>Finalized payroll snapshots</h2>
                <small
                    >These are the records created by Finalize Payroll
                    Run.</small
                >
            </div>
        </div>
        <div class="filters inline-filters">
            <label>Search payroll<input v-model="payrollSearch" placeholder="Reference, approver, period, amount" /></label>
            <label>Finalized from<input v-model="payrollFrom" type="date" /></label>
            <label>Finalized to<input v-model="payrollTo" type="date" /></label>
            <button v-if="payrollSearch || payrollFrom || payrollTo" class="btn report-clear" @click="payrollSearch = ''; payrollFrom = ''; payrollTo = ''">Clear payroll filters</button>
            <small>{{ filteredPayrollRuns.length }} of {{ data.payroll?.runs?.length || 0 }} snapshots shown</small>
        </div>
        <TablePager
            v-model:page="payrollPage"
            v-model:page-size="payrollPageSize"
            :total="filteredPayrollRuns.length"
            label="payroll snapshots"
        />
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Period</th>
                    <th>Employees</th>
                    <th>Gross</th>
                    <th>Net</th>
                    <th>Finalized by</th>
                    <th>Finalized</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in pagedPayrollRuns" :key="run.id">
                    <td data-label="Reference">
                        <strong>{{ run.reference }}</strong>
                    </td>
                    <td data-label="Period">
                        {{ run.period_start }} – {{ run.period_end }}
                    </td>
                    <td data-label="Employees">{{ run.items_count }}</td>
                    <td data-label="Gross">₱{{ money(run.gross_pay) }}</td>
                    <td data-label="Net">₱{{ money(run.net_pay) }}</td>
                    <td data-label="Finalized by">{{ run.created_by_name || run.creator?.name }}</td>
                    <td data-label="Finalized">
                        {{ dateTime(run.finalized_at) }}
                    </td>
                </tr>
                <tr v-if="!filteredPayrollRuns.length" class="empty-row">
                    <td colspan="7">
                        {{ data.payroll?.runs?.length ? 'No finalized payroll snapshots match your filters.' : 'No finalized payroll runs in this period.' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
    <section class="panel table-wrap report-detail-panel">
        <div class="panel-head">
            <div>
                <h2>Inventory statistics</h2>
                <small>{{ filteredInventoryStats.length }} matching products</small>
            </div>
        </div>
        <div class="filters inline-filters">
            <label>Search inventory<input v-model="inventorySearch" placeholder="Product, SKU, category, supplier" /></label>
            <label>Updated from<input v-model="inventoryFrom" type="date" /></label>
            <label>Updated to<input v-model="inventoryTo" type="date" /></label>
            <button v-if="inventorySearch || inventoryFrom || inventoryTo" class="btn report-clear" @click="inventorySearch = ''; inventoryFrom = ''; inventoryTo = ''">Clear inventory filters</button>
            <small>{{ filteredInventoryStats.length }} of {{ data.inventory?.length || 0 }} products shown</small>
        </div>
        <TablePager
            v-model:page="inventoryPage"
            v-model:page-size="inventoryPageSize"
            :total="filteredInventoryStats.length"
            label="products"
        />
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>On hand</th>
                    <th>Reserved</th>
                    <th>Available</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="product in pagedInventoryStats" :key="product.id">
                    <td data-label="Product">
                        <strong>{{ product.name }}</strong
                        ><small
                            >{{ product.sku }} · {{ product.category }}</small
                        >
                    </td>
                    <td data-label="On hand">{{ product.stock_quantity }}</td>
                    <td data-label="Reserved">
                        {{ product.reserved_quantity }}
                    </td>
                    <td data-label="Available">
                        {{ product.available_quantity }}
                    </td>
                    <td data-label="Value">
                        ₱{{ money(product.stock_quantity * product.price) }}
                    </td>
                </tr>
                <tr v-if="!filteredInventoryStats.length" class="empty-row"><td colspan="5">No inventory records match your filters.</td></tr>
            </tbody>
        </table>
    </section>
    <div v-if="showBackup" class="modal"><form class="modal-card" @submit.prevent="downloadBackup"><div class="panel-head"><div><h2>Company data backup</h2><small>Protected JSON export of operational records</small></div><button type="button" class="btn ghost" @click="showBackup = false">Close</button></div><p>The backup excludes passwords, tokens, OAuth identifiers, and facial descriptors. Store the downloaded file securely.</p><label>Your administrator password<input v-model="backupPassword" type="password" autocomplete="current-password" required></label><p v-if="backupError" class="error">{{ backupError }}</p><button class="btn primary full" :disabled="backupBusy">{{ backupBusy ? 'Preparing backup…' : 'Authorize and download backup' }}</button></form></div>
</template>

<style scoped>
.report-actions{gap:8px}.report-action,.report-period-button,.report-clear{transition:background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease,transform .16s ease}.report-action:hover,.report-period-button:hover,.report-clear:hover{transform:translateY(-1px);box-shadow:0 6px 15px rgba(18,55,36,.1)}.report-action:active,.report-period-button:active,.report-clear:active{transform:translateY(0);box-shadow:none}.report-action:not(.primary):hover{color:var(--brand);border-color:#a9d3b8;background:#f2faf5}.report-action.primary:hover,.report-period-button:hover{border-color:#0e5f39;background:linear-gradient(135deg,#176b43,#0d8a50)}.report-period-button{min-width:118px}.report-clear:hover{color:var(--brand);border-color:#a9d3b8;background:#f2faf5}.report-stats .stat{transition:transform .16s ease,box-shadow .16s ease}.report-stats .stat:hover{transform:translateY(-2px);box-shadow:0 20px 42px rgba(18,55,36,.13)}
.report-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.report-stats .stat{position:relative;min-height:132px;padding:18px 18px 18px 68px;border:1px solid #d5e9dc;border-top:3px solid var(--brand);background:linear-gradient(145deg,#fff 18%,#eef8f2);box-shadow:0 12px 28px rgba(13,50,33,.13),0 2px 6px rgba(13,50,33,.05)!important}.report-stats .stat::before{position:absolute;top:18px;left:18px;display:grid;width:34px;height:34px;place-items:center;border-radius:10px;color:var(--brand);background:#dff2e6;font-size:1.15rem;font-weight:800;box-shadow:inset 0 0 0 1px rgba(23,107,67,.06)}.report-stats .stat:nth-child(1)::before{content:"↗";color:#fff;background:var(--brand)}.report-stats .stat:nth-child(2)::before{content:"◌"}.report-stats .stat:nth-child(3)::before{content:"◷"}.report-stats .stat:nth-child(4)::before{content:"▤"}.report-stats .stat:nth-child(5)::before{content:"▥"}.report-stats .stat:nth-child(6)::before{content:"♧"}.report-stats .stat>span{font-size:.78rem}.report-stats .stat>strong{font-size:clamp(1.45rem,2.1vw,1.9rem);letter-spacing:-.04em}.report-stats .stat>small{line-height:1.35}.report-stats .stat:hover{transform:translateY(-2px);box-shadow:0 18px 34px rgba(13,50,33,.16),0 3px 8px rgba(13,50,33,.06)!important}
@media(max-width:1050px){.report-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.report-stats{grid-template-columns:1fr}.report-stats .stat{min-height:112px}}
html[data-theme="dark"] .report-action:not(.primary):hover,html[data-theme="dark"] .report-clear:hover{color:#ccebd7;border-color:#4b8763;background:#203e2e}html[data-theme="dark"] .report-stats .stat{border-color:var(--line);background:linear-gradient(145deg,#193124,#162b20)}
</style>
