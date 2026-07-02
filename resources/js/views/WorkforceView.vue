<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import PageHeader from '../components/PageHeader.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const tab = ref('payroll');
const employees = ref([]);
const preview = ref([]);
const attendance = ref([]);
const message = ref('');
const show = ref(false);
const saving = ref(null);
const exporting = ref(false);
const deductionDrafts = reactive({});
const deductions = [{ code: 'sss', label: 'SSS' }, { code: 'pagibig', label: 'Pag-IBIG' }, { code: 'philhealth', label: 'PhilHealth' }];
const form = reactive({ employee_number: '', name: '', job_title: '', weekly_salary: 0, incentive: 0, overtime_hourly_rate: 0, overtime_hours: 0, deduction_plan: deductions.map(item => item.code), face_subject_id: '' });

async function loadPayroll() {
    const [employeeResponse, previewResponse] = await Promise.all([
        axios.get('/api/employees', { params: { _: Date.now() } }),
        axios.get('/api/payroll/preview', { params: { _: Date.now() } }),
    ]);
    employees.value = employeeResponse.data;
    preview.value = previewResponse.data;
    for (const employee of employees.value) deductionDrafts[employee.id] = [...(employee.deduction_plan ?? deductions.map(item => item.code))];
}

async function load() {
    await Promise.all([loadPayroll(), axios.get('/api/attendance').then(response => { attendance.value = response.data.data; })]);
}

async function save() {
    await axios.post('/api/employees', form);
    show.value = false;
    message.value = 'Employee added.';
    await loadPayroll();
}

async function saveDeductions(row, event) {
    const employee = row.employee;
    const selected = [...(deductionDrafts[employee.id] || [])];
    saving.value = employee.id;
    try {
        const { data: updated } = await axios.put(`/api/employees/${employee.id}`, {
            employee_number: employee.employee_number, name: employee.name, job_title: employee.job_title,
            weekly_salary: employee.weekly_salary, incentive: employee.incentive,
            overtime_hourly_rate: employee.overtime_hourly_rate, overtime_hours: employee.overtime_hours,
            deduction_plan: selected, face_subject_id: employee.face_subject_id,
        });
        row.employee = updated;
        const refreshed = (await axios.get('/api/payroll/preview', { params: { _: Date.now() } })).data.find(item => item.employee.id === employee.id);
        if (refreshed) Object.assign(row, refreshed);
        deductionDrafts[employee.id] = selected;
        message.value = `Payroll deductions updated for ${employee.name}.`;
        event.currentTarget.closest('details')?.removeAttribute('open');
    } catch (error) {
        message.value = error.response?.data?.message || 'Unable to update deductions.';
    } finally {
        saving.value = null;
    }
}

function deductionLabel(id) {
    const selected = deductionDrafts[id] || [];
    return selected.length ? deductions.filter(item => selected.includes(item.code)).map(item => item.label).join(', ') : 'No deductions';
}

async function remove(employee) {
    if (confirm(`Remove ${employee.name}?`)) { await axios.delete(`/api/employees/${employee.id}`); await loadPayroll(); }
}

function period() {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 6);
    return { period_start: start.toISOString().slice(0, 10), period_end: end.toISOString().slice(0, 10) };
}

async function run() {
    await axios.post('/api/payroll/runs', period());
    message.value = 'Payroll finalized.';
}

async function downloadPayroll() {
    exporting.value = true;
    try {
        const response = await axios.get('/api/payroll/export', { params: period(), responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `nenial-payroll-${period().period_end}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } finally { exporting.value = false; }
}

async function mark(employee) {
    await axios.post('/api/attendance', { employee_id: employee.id, attendance_date: new Date().toISOString().slice(0, 10), status: 'present', recognized_at: new Date().toISOString(), match_confidence: 100 });
    await load();
}

onMounted(load);
</script>

<template>
    <PageHeader title="Workforce" subtitle="Payroll and facial-recognition attendance"><div class="actions"><button class="btn ghost" :class="{ active: tab === 'payroll' }" @click="tab = 'payroll'">Payroll</button><button class="btn ghost" :class="{ active: tab === 'attendance' }" @click="tab = 'attendance'">Attendance</button><button class="btn primary" @click="show = true">Add employee</button></div></PageHeader>
    <p v-if="message" class="notice">{{ message }}</p>
    <section v-if="tab === 'payroll'" class="panel table-wrap"><div class="panel-head"><div><h2>Weekly payroll preview</h2><small>Select statutory deductions for each employee. Values recalculate immediately.</small></div><div class="actions"><button class="btn" :disabled="exporting" @click="downloadPayroll">{{ exporting ? 'Preparing…' : 'Download payroll CSV' }}</button><button class="btn primary" @click="run">Finalize payroll run</button></div></div><table><thead><tr><th>Employee</th><th>Deduction plan</th><th>Gross</th><th>SSS</th><th>Pag-IBIG</th><th>PhilHealth</th><th>Net</th><th v-if="auth.role === 'admin'">Action</th></tr></thead><tbody><tr v-for="row in preview" :key="row.employee.id"><td data-label="Employee"><strong>{{ row.employee.name }}</strong><small>{{ row.employee.job_title }}</small></td><td data-label="Deduction plan"><details class="deduction-dropdown"><summary>{{ deductionLabel(row.employee.id) }}</summary><div class="deduction-options"><label v-for="deduction in deductions" :key="deduction.code"><input v-model="deductionDrafts[row.employee.id]" type="checkbox" :value="deduction.code"><span>{{ deduction.label }}</span></label><button class="btn tiny primary" :disabled="saving === row.employee.id" @click.prevent="saveDeductions(row, $event)">{{ saving === row.employee.id ? 'Saving…' : 'Apply deductions' }}</button></div></details></td><td data-label="Gross">₱{{ row.calculation.gross_pay }}</td><td data-label="SSS">₱{{ row.calculation.sss }}</td><td data-label="Pag-IBIG">₱{{ row.calculation.pagibig }}</td><td data-label="PhilHealth">₱{{ row.calculation.philhealth }}</td><td data-label="Net"><strong>₱{{ row.calculation.net_pay }}</strong></td><td v-if="auth.role === 'admin'" data-label="Action"><button class="btn tiny danger" @click="remove(row.employee)">Remove</button></td></tr></tbody></table></section>
    <section v-else class="panel table-wrap"><div class="panel-head"><h2>Facial attendance</h2><span class="live">● Device ready</span></div><table><thead><tr><th>Employee</th><th>Date</th><th>Status</th><th>Confidence</th><th v-if="auth.role === 'admin'">Manual fallback</th></tr></thead><tbody><tr v-for="record in attendance" :key="record.id"><td data-label="Employee">{{ record.employee?.name }}</td><td data-label="Date">{{ record.attendance_date }}</td><td data-label="Status">{{ record.status }}</td><td data-label="Confidence">{{ record.match_confidence || '—' }}%</td><td v-if="auth.role === 'admin'" data-label="Manual fallback"><button class="btn tiny" @click="mark(record.employee)">Mark present</button></td></tr></tbody></table></section>
    <div v-if="show" class="modal"><form class="modal-card wide" @submit.prevent="save"><div class="panel-head"><h2>Add employee</h2><button type="button" class="btn ghost" @click="show = false">Close</button></div><div class="form-grid"><label>Employee number<input v-model="form.employee_number" required></label><label>Name<input v-model="form.name" required></label><label>Job title<input v-model="form.job_title" required></label><label>Weekly salary<input v-model.number="form.weekly_salary" type="number" required></label><label>Incentive<input v-model.number="form.incentive" type="number"></label><label>OT hourly rate<input v-model.number="form.overtime_hourly_rate" type="number"></label><label>Face subject ID<input v-model="form.face_subject_id"></label><fieldset class="deduction-picker"><legend>Payroll deductions</legend><label v-for="deduction in deductions" :key="deduction.code"><input v-model="form.deduction_plan" type="checkbox" :value="deduction.code"> {{ deduction.label }}</label></fieldset></div><button class="btn primary">Add employee</button></form></div>
</template>
