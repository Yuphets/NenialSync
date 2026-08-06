<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import UiIcon from "../components/UiIcon.vue";
import { useAuthStore } from "../stores/auth";
import { downloadPayrollWorkbook } from "../utils/payrollWorkbook";

const auth = useAuthStore();
const tab = ref("payroll");
const employees = ref([]);
const preview = ref([]);
const attendance = ref([]);
const message = ref("");
const show = ref(false);
const saving = ref(null);
const exporting = ref(false);
const checkingStandards = ref(false);
const statutoryStatus = ref(null);
const search = ref("");
const payrollPage = ref(1);
const payrollPageSize = ref(5);
const attendancePage = ref(1);
const attendancePageSize = ref(5);
const deductionDrafts = reactive({});
const incentiveDrafts = reactive({});
const deductions = [
    { code: "sss", label: "SSS" },
    { code: "pagibig", label: "Pag-IBIG" },
    { code: "philhealth", label: "PhilHealth" },
];
const form = reactive({
    employee_number: "",
    name: "",
    job_title: "",
    weekly_salary: 0,
    incentive: 0,
    overtime_hourly_rate: 0,
    overtime_hours: 0,
    deduction_plan: deductions.map((item) => item.code),
    face_subject_id: "",
});
let attendanceTimer;
const manilaDateKey = (value = new Date()) => {
    const date = value instanceof Date ? value : new Date(value);
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
const matchesSearch = (employee) => `${employee?.name || ""} ${employee?.employee_number || ""} ${employee?.job_title || ""}`.toLowerCase().includes(search.value.trim().toLowerCase());
const filteredPreview = computed(() => preview.value.filter((row) => matchesSearch(row.employee)));
const filteredAttendance = computed(() => attendance.value.filter((record) => matchesSearch(record.employee)));
const pagedPreview = computed(() =>
    filteredPreview.value.slice(
        (payrollPage.value - 1) * payrollPageSize.value,
        payrollPage.value * payrollPageSize.value,
    ),
);
const pagedAttendance = computed(() =>
    filteredAttendance.value.slice(
        (attendancePage.value - 1) * attendancePageSize.value,
        attendancePage.value * attendancePageSize.value,
    ),
);

async function loadPayroll() {
    const [employeeResponse, previewResponse, statutoryResponse] = await Promise.all([
        axios.get("/api/employees", { params: { _: Date.now() } }),
        axios.get("/api/payroll/preview", { params: { _: Date.now() } }),
        axios.get("/api/statutory-rates", { params: { _: Date.now() } }),
    ]);
    employees.value = employeeResponse.data;
    preview.value = previewResponse.data;
    statutoryStatus.value = statutoryResponse.data;
    for (const employee of employees.value) {
        deductionDrafts[employee.id] = [
            ...(employee.deduction_plan ?? deductions.map((item) => item.code)),
        ];
        incentiveDrafts[employee.id] = Number(employee.incentive || 0);
    }
}

async function checkStandards() {
    checkingStandards.value = true;
    try {
        statutoryStatus.value = (
            await axios.post("/api/admin/statutory-rates/check")
        ).data;
        message.value = statutoryStatus.value.monitor?.review_required
            ? "An official source changed. Review the new publication before finalizing payroll."
            : "Official payroll sources checked. No source change was detected.";
    } catch (error) {
        message.value =
            error.response?.data?.message ||
            "The official sources could not be checked right now. Existing approved rates were not changed.";
    } finally {
        checkingStandards.value = false;
    }
}

function rateSummary(rate) {
    const rules = rate.rules || {};
    if (rate.code === "sss")
        return `${Number(rules.employee_rate * 100)}% employee share · ₱${Number(rules.min_credit).toLocaleString("en-PH")}–₱${Number(rules.max_credit).toLocaleString("en-PH")} MSC`;
    if (rate.code === "pagibig")
        return `${Number(rules.low_income_rate * 100)}% / ${Number(rules.employee_rate * 100)}% employee share · ₱${Number(rules.max_salary).toLocaleString("en-PH")} cap`;
    return `${Number(rules.total_rate * 100)}% total, equally shared · ₱${Number(rules.min_salary).toLocaleString("en-PH")}–₱${Number(rules.max_salary).toLocaleString("en-PH")} salary base`;
}

function dateLabel(value) {
    if (!value) return "Not recorded";
    const date = new Date(value.includes?.("T") ? value : `${value}T00:00:00+08:00`);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString("en-US", {
              month: "short",
              day: "numeric",
              year: "numeric",
              timeZone: "Asia/Manila",
          });
}

async function load() {
    await Promise.all([loadPayroll(), loadAttendance()]);
}

async function loadAttendance() {
    try {
        attendance.value = (
            await axios.get("/api/attendance", { params: { _: Date.now() } })
        ).data.data;
    } catch {
        /* Preserve the last good list during a brief outage. */
    }
}

async function save() {
    await axios.post("/api/employees", form);
    show.value = false;
    message.value = "Employee added.";
    await loadPayroll();
}

async function saveDeductions(row, event) {
    const employee = row.employee;
    const selected = [...(deductionDrafts[employee.id] || [])];
    saving.value = employee.id;
    try {
        const { data: updated } = await axios.put(
            `/api/employees/${employee.id}`,
            {
                employee_number: employee.employee_number,
                name: employee.name,
                job_title: employee.job_title,
                weekly_salary: employee.weekly_salary,
                incentive: employee.incentive,
                overtime_hourly_rate: employee.overtime_hourly_rate,
                overtime_hours: employee.overtime_hours,
                deduction_plan: selected,
                face_subject_id: employee.face_subject_id,
            },
        );
        row.employee = updated;
        const refreshed = (
            await axios.get("/api/payroll/preview", {
                params: { _: Date.now() },
            })
        ).data.find((item) => item.employee.id === employee.id);
        if (refreshed) Object.assign(row, refreshed);
        deductionDrafts[employee.id] = selected;
        message.value = `Payroll deductions updated for ${employee.name}.`;
        event.currentTarget.closest("details")?.removeAttribute("open");
    } catch (error) {
        message.value =
            error.response?.data?.message || "Unable to update deductions.";
    } finally {
        saving.value = null;
    }
}

async function saveIncentive(row) {
    const employee = row.employee;
    saving.value = `incentive-${employee.id}`;
    try {
        const { data: updated } = await axios.put(
            `/api/employees/${employee.id}`,
            {
                employee_number: employee.employee_number,
                name: employee.name,
                job_title: employee.job_title,
                weekly_salary: employee.weekly_salary,
                incentive: Number(incentiveDrafts[employee.id] || 0),
                overtime_hourly_rate: employee.overtime_hourly_rate,
                overtime_hours: employee.overtime_hours,
                deduction_plan: employee.deduction_plan || [],
                face_subject_id: employee.face_subject_id,
            },
        );
        row.employee = updated;
        const refreshed = (
            await axios.get("/api/payroll/preview", {
                params: { _: Date.now() },
            })
        ).data.find((item) => item.employee.id === employee.id);
        if (refreshed) Object.assign(row, refreshed);
        message.value = `Incentive updated for ${employee.name}.`;
    } catch (error) {
        message.value =
            error.response?.data?.message || "Unable to update incentive.";
    } finally {
        saving.value = null;
    }
}

function deductionLabel(id) {
    const selected = deductionDrafts[id] || [];
    return selected.length
        ? deductions
              .filter((item) => selected.includes(item.code))
              .map((item) => item.label)
              .join(", ")
        : "No deductions";
}

function formatAttendanceDate(record) {
    const value = record.recognized_at || `${record.attendance_date}T00:00:00`;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return record.attendance_date;
    return date.toLocaleString("en-US", {
        month: "2-digit",
        day: "2-digit",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
        timeZone: "Asia/Manila",
    });
}

async function remove(employee) {
    if (confirm(`Remove ${employee.name}?`)) {
        await axios.delete(`/api/employees/${employee.id}`);
        await loadPayroll();
    }
}

function period() {
    const end = new Date();
    const start = new Date(end.getTime() - 6 * 24 * 60 * 60 * 1000);
    return {
        period_start: manilaDateKey(start),
        period_end: manilaDateKey(end),
    };
}

async function run() {
    if (
        !confirm(
            "Finalize this payroll period? This saves an immutable snapshot in Reports and prevents another run for the same dates.",
        )
    )
        return;
    try {
        await axios.post("/api/payroll/runs", period());
        message.value =
            "Payroll finalized. The snapshot is now available in Reports.";
    } catch (error) {
        message.value =
            error.response?.data?.message ||
            "Unable to finalize this payroll period.";
    }
}

async function downloadPayroll() {
    exporting.value = true;
    try {
        const payrollPeriod = period();
        const { data } = await axios.get("/api/payroll/export-data", {
            params: payrollPeriod,
        });
        await downloadPayrollWorkbook(data.rows, payrollPeriod, {
            source: data.source,
            reference: data.reference,
            finalizedAt: data.finalized_at,
        });
        message.value = data.finalized
            ? "Finalized payroll snapshot downloaded."
            : "Current payroll preview downloaded.";
    } catch (error) {
        console.error("Payroll workbook export failed", error);
        message.value =
            "Unable to prepare the payroll workbook. Please try again.";
    } finally {
        exporting.value = false;
    }
}

async function mark(employee) {
    await axios.post("/api/attendance", {
        employee_id: employee.id,
        attendance_date: manilaDateKey(),
        status: "present",
        recognized_at: new Date().toISOString(),
        match_confidence: 100,
    });
    await load();
}

onMounted(() => {
    load();
    attendanceTimer = window.setInterval(() => {
        if (tab.value === "attendance") loadAttendance();
    }, 3000);
});
onBeforeUnmount(() => window.clearInterval(attendanceTimer));
</script>

<template>
    <PageHeader
        title="Workforce"
        subtitle="Payroll and facial-recognition attendance"
        ><div class="actions">
            <input v-model="search" class="workforce-search" type="search" placeholder="Search employee, ID, or job" aria-label="Search workforce">
            <button
                class="btn ghost workforce-tab"
                :class="{ active: tab === 'payroll' }"
                @click="tab = 'payroll'"
            >
                Payroll</button
            ><button
                class="btn ghost workforce-tab"
                :class="{ active: tab === 'attendance' }"
                @click="tab = 'attendance'"
            >
                Attendance</button
            ><button class="btn primary" @click="show = true">
                <UiIcon name="plus" :size="17" /> Add employee
            </button>
        </div></PageHeader
    >
    <p v-if="message" class="notice">{{ message }}</p>
    <section v-if="tab === 'payroll'" class="panel table-wrap">
        <div class="panel-head">
            <div>
                <h2>Weekly payroll preview</h2>
                <small
                    >Select statutory deductions and manage employee incentives.
                    Values recalculate immediately.</small
                >
            </div>
            <div class="actions">
                <button
                    class="btn"
                    :disabled="exporting"
                    @click="downloadPayroll"
                >
                    {{
                        exporting ? "Preparing…" : "Download payroll Excel"
                    }}</button
                ><button class="btn primary" @click="run">
                    Finalize payroll run
                </button>
            </div>
        </div>
        <TablePager
            v-model:page="payrollPage"
            v-model:page-size="payrollPageSize"
            :total="filteredPreview.length"
            label="employees"
        />
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Deduction plan</th>
                    <th>Incentive</th>
                    <th>Gross</th>
                    <th>Statutory deductions</th>
                    <th>Net</th>
                    <th v-if="auth.role === 'admin'">Action</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in pagedPreview" :key="row.employee.id">
                    <td data-label="Employee">
                        <div>
                            <strong>{{ row.employee.name }}</strong
                            ><small>{{ row.employee.job_title }}</small>
                        </div>
                    </td>
                    <td data-label="Deduction plan">
                        <details class="deduction-dropdown">
                            <summary>
                                {{ deductionLabel(row.employee.id) }}
                            </summary>
                            <div class="deduction-options">
                                <label
                                    v-for="deduction in deductions"
                                    :key="deduction.code"
                                    ><input
                                        v-model="
                                            deductionDrafts[row.employee.id]
                                        "
                                        type="checkbox"
                                        :value="deduction.code"
                                    /><span>{{ deduction.label }}</span></label
                                ><button
                                    class="btn tiny primary"
                                    :disabled="saving === row.employee.id"
                                    @click.prevent="saveDeductions(row, $event)"
                                >
                                    {{
                                        saving === row.employee.id
                                            ? "Saving…"
                                            : "Apply deductions"
                                    }}
                                </button>
                            </div>
                        </details>
                    </td>
                    <td data-label="Incentive">
                        <div
                            v-if="auth.role === 'admin'"
                            class="incentive-control"
                        >
                            <input
                                v-model.number="
                                    incentiveDrafts[row.employee.id]
                                "
                                type="number"
                                min="0"
                                step="0.01"
                            /><button
                                class="btn tiny"
                                :disabled="
                                    saving === `incentive-${row.employee.id}`
                                "
                                @click="saveIncentive(row)"
                            >
                                {{
                                    saving === `incentive-${row.employee.id}`
                                        ? "Saving…"
                                        : "Apply"
                                }}
                            </button>
                        </div>
                        <span v-else>₱{{ row.calculation.incentive }}</span>
                    </td>
                    <td data-label="Gross">₱{{ row.calculation.gross_pay }}</td>
                    <td data-label="Statutory deductions">
                        <div class="deduction-breakdown">
                            <span>SSS <b>₱{{ row.calculation.sss }}</b></span>
                            <span>Pag-IBIG <b>₱{{ row.calculation.pagibig }}</b></span>
                            <span>PhilHealth <b>₱{{ row.calculation.philhealth }}</b></span>
                        </div>
                    </td>
                    <td data-label="Net">
                        <strong>₱{{ row.calculation.net_pay }}</strong>
                    </td>
                    <td v-if="auth.role === 'admin'" data-label="Action">
                        <button
                            class="btn tiny danger"
                            @click="remove(row.employee)"
                        >
                            Remove
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
    <section v-else class="panel table-wrap">
        <div class="panel-head">
            <h2>Facial attendance</h2>
            <span class="live">● Device ready</span>
        </div>
        <TablePager
            v-model:page="attendancePage"
            v-model:page-size="attendancePageSize"
            :total="filteredAttendance.length"
            label="attendance records"
        />
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date and time</th>
                    <th>Status</th>
                    <th>Confidence</th>
                    <th v-if="auth.role === 'admin'">Manual fallback</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="record in pagedAttendance" :key="record.id">
                    <td data-label="Employee">{{ record.employee?.name }}</td>
                    <td data-label="Date and time">
                        {{ formatAttendanceDate(record) }}
                    </td>
                    <td data-label="Status">{{ record.status }}</td>
                    <td data-label="Confidence">
                        {{ record.match_confidence || "—" }}%
                    </td>
                    <td
                        v-if="auth.role === 'admin'"
                        data-label="Manual fallback"
                    >
                        <button class="btn tiny" @click="mark(record.employee)">
                            Mark present
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
    <section v-if="tab === 'payroll' && statutoryStatus" class="panel statutory-panel">
        <div class="panel-head">
            <div>
                <span class="eyebrow">Effective-dated payroll standards</span>
                <h2>Government contribution schedule</h2>
                <small>Approved standards activate by payroll period and synchronize to the store-local server.</small>
            </div>
            <button
                v-if="auth.role === 'admin'"
                class="btn tiny"
                type="button"
                :disabled="checkingStandards"
                @click="checkStandards"
            >
                {{ checkingStandards ? "Checking official sources…" : "Check official sources" }}
            </button>
        </div>
        <div class="statutory-grid">
            <article v-for="rate in statutoryStatus.rates" :key="rate.code">
                <div>
                    <strong>{{ rate.label }}</strong>
                    <span class="tag">Current</span>
                </div>
                <p>{{ rateSummary(rate) }}</p>
                <small>{{ rate.revision }} · Effective {{ dateLabel(rate.effective_from) }}</small>
                <a :href="rate.source_url" target="_blank" rel="noopener noreferrer">View official publication</a>
            </article>
        </div>
        <p
            class="statutory-monitor"
            :class="{ warning: statutoryStatus.monitor?.review_required }"
            role="status"
        >
            <strong>{{
                statutoryStatus.monitor?.review_required
                    ? "Official-source change detected — review required."
                    : statutoryStatus.monitor?.automatic_monitoring
                      ? "Daily source monitoring is enabled."
                      : "Manual source checking is available."
            }}</strong>
            {{
                statutoryStatus.monitor?.last_checked_at
                    ? ` Last checked ${dateLabel(statutoryStatus.monitor.last_checked_at)}.`
                    : " The first automated check will establish the monitoring baseline."
            }}
            Approved calculations are never changed silently, and finalized payroll snapshots remain unchanged.
        </p>
    </section>
    <div v-if="show" class="modal">
        <form class="modal-card wide" @submit.prevent="save">
            <div class="panel-head">
                <h2>Add employee</h2>
                <button type="button" class="btn ghost" @click="show = false">
                    Close
                </button>
            </div>
            <div class="form-grid">
                <label
                    >Employee number<input
                        v-model="form.employee_number"
                        required /></label
                ><label>Name<input v-model="form.name" required /></label
                ><label
                    >Job title<input v-model="form.job_title" required /></label
                ><label
                    >Weekly salary<input
                        v-model.number="form.weekly_salary"
                        type="number"
                        required /></label
                ><label
                    >Incentive<input
                        v-model.number="form.incentive"
                        type="number" /></label
                ><label
                    >OT hourly rate<input
                        v-model.number="form.overtime_hourly_rate"
                        type="number" /></label
                ><label
                    >Face subject ID<input v-model="form.face_subject_id"
                /></label>
                <fieldset class="deduction-picker">
                    <legend>Payroll deductions</legend>
                    <label v-for="deduction in deductions" :key="deduction.code"
                        ><input
                            v-model="form.deduction_plan"
                            type="checkbox"
                            :value="deduction.code"
                        />
                        {{ deduction.label }}</label
                    >
                </fieldset>
            </div>
            <button class="btn primary">Add employee</button>
        </form>
    </div>
</template>
<style scoped>
.workforce-search { width: min(280px, 70vw); }
.incentive-control { display: grid; grid-template-columns: minmax(90px, 1fr) auto; gap: .4rem; min-width: 155px; }
.deduction-breakdown { display: grid; gap: .3rem; min-width: 145px; }
.deduction-breakdown span { display: flex; justify-content: space-between; gap: 1rem; color: var(--muted); font-size: .74rem; }
.deduction-breakdown b { color: var(--ink); }
.statutory-panel { overflow: hidden; }
.statutory-panel .panel-head > div { display: grid; gap: .25rem; }
.statutory-panel .panel-head small { color: var(--muted); line-height: 1.45; }
.statutory-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 16px 18px; }
.statutory-grid article { display: grid; gap: .5rem; min-width: 0; padding: 14px; border: 1px solid var(--line); border-radius: 12px; color: var(--ink); background: var(--surface-soft); }
.statutory-grid article > div { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.statutory-grid article strong, .statutory-grid article p { color: var(--ink); }
.statutory-grid article p { margin: 0; line-height: 1.45; }
.statutory-grid article small { color: var(--muted); }
.statutory-grid article a { width: max-content; max-width: 100%; color: var(--brand); font-size: .76rem; font-weight: 750; overflow-wrap: anywhere; }
.statutory-monitor { margin: 0; padding: 12px 18px; border-top: 1px solid var(--line); color: var(--muted); background: var(--surface-soft); font-size: .76rem; line-height: 1.5; }
.statutory-monitor strong { color: var(--brand); }
.statutory-monitor.warning { color: #654700; background: #fff5d8; }
.statutory-monitor.warning strong { color: #8a5c00; }
@media (max-width: 1000px) { .statutory-grid { grid-template-columns: 1fr; } }
@media (max-width: 700px) {
    .workforce-search { width: 100%; }
    .statutory-panel .panel-head { align-items: stretch; flex-direction: column; }
    .statutory-panel .panel-head .btn { width: 100%; }
    .statutory-grid { padding: 12px; }
}
.workforce-tab.active{color:var(--dark)!important;border-color:#a8d8b8!important;background:linear-gradient(135deg,#f0fbf3 0%,#c4ecd2 52%,#9bd8b1 100%)!important;box-shadow:0 5px 14px rgba(23,107,67,.12)}
</style>
