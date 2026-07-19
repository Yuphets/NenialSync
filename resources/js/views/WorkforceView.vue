<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import axios from "axios";
import PageHeader from "../components/PageHeader.vue";
import TablePager from "../components/TablePager.vue";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const tab = ref("payroll");
const employees = ref([]);
const preview = ref([]);
const attendance = ref([]);
const message = ref("");
const show = ref(false);
const saving = ref(null);
const exporting = ref(false);
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
    const [employeeResponse, previewResponse] = await Promise.all([
        axios.get("/api/employees", { params: { _: Date.now() } }),
        axios.get("/api/payroll/preview", { params: { _: Date.now() } }),
    ]);
    employees.value = employeeResponse.data;
    preview.value = previewResponse.data;
    for (const employee of employees.value) {
        deductionDrafts[employee.id] = [
            ...(employee.deduction_plan ?? deductions.map((item) => item.code)),
        ];
        incentiveDrafts[employee.id] = Number(employee.incentive || 0);
    }
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
    const start = new Date();
    start.setDate(end.getDate() - 6);
    return {
        period_start: start.toISOString().slice(0, 10),
        period_end: end.toISOString().slice(0, 10),
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
        const response = await axios.get("/api/payroll/export", {
            params: period(),
            responseType: "blob",
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement("a");
        link.href = url;
        link.download = `nenial-payroll-${period().period_end}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } finally {
        exporting.value = false;
    }
}

async function mark(employee) {
    await axios.post("/api/attendance", {
        employee_id: employee.id,
        attendance_date: new Date().toISOString().slice(0, 10),
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
                class="btn ghost"
                :class="{ active: tab === 'payroll' }"
                @click="tab = 'payroll'"
            >
                Payroll</button
            ><button
                class="btn ghost"
                :class="{ active: tab === 'attendance' }"
                @click="tab = 'attendance'"
            >
                Attendance</button
            ><button class="btn primary" @click="show = true">
                Add employee
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
                        exporting ? "Preparing…" : "Download payroll CSV"
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
@media (max-width: 700px) { .workforce-search { width: 100%; } }
</style>
