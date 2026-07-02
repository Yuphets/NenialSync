<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import PageHeader from '../components/PageHeader.vue';

const devices = ref([]);
const token = ref('');
const message = ref('');
const form = reactive({ name: 'Main Gate Face Terminal', type: 'facial', location: 'Main entrance', provider: 'Nenial Browser Terminal', external_id: '' });
async function load() { devices.value = (await axios.get('/api/devices')).data; }
onMounted(load);
async function create() { const { data } = await axios.post('/api/devices', form); token.value = data.token; message.value = 'Device registered. Copy its token now—it is shown only once.'; await load(); }
</script>

<template>
    <PageHeader title="Store devices" subtitle="Register and monitor barcode, POS, and facial-recognition hardware"><RouterLink class="btn primary" to="/face-terminal">Open face terminal</RouterLink></PageHeader>
    <p v-if="message" class="notice">{{ message }}</p>
    <div v-if="token" class="secret"><strong>One-time device token</strong><code>{{ token }}</code><button class="btn" @click="navigator.clipboard.writeText(token)">Copy token</button></div>
    <div class="two-col"><section class="panel"><div class="panel-head"><h2>Register hardware</h2></div><form class="stack" @submit.prevent="create"><label>Name<input v-model="form.name" required></label><label>Type<select v-model="form.type"><option value="facial">Facial recognition</option><option value="barcode">Barcode scanner</option><option value="pos">POS terminal</option></select></label><label>Location<input v-model="form.location"></label><label>Provider / model<input v-model="form.provider"></label><label>External device ID<input v-model="form.external_id"></label><button class="btn primary">Generate device token</button></form></section><section class="panel"><div class="panel-head"><h2>Connected devices</h2><span class="live">● Monitoring</span></div><div v-for="device in devices" :key="device.id" class="device"><div><strong>{{ device.name }}</strong><small>{{ device.type }} · {{ device.location }}</small></div><span class="tag" :class="{ warn: !device.last_seen_at }">{{ device.last_seen_at ? `Seen ${new Date(device.last_seen_at).toLocaleString()}` : 'Never connected' }}</span></div><div v-if="!devices.length" class="empty">No devices registered.</div></section></div>
    <section class="panel guide"><div class="panel-head"><h2>Hardware setup checklist</h2></div><div class="guide-grid"><article><h3>USB barcode scanner</h3><ol><li>Configure HID keyboard mode.</li><li>Enable Enter/CR suffix.</li><li>Match the scanner keyboard layout to the POS computer.</li><li>Scan ten products before opening the register.</li></ol></article><article><h3>Nenial face terminal</h3><ol><li>Assign every employee a unique Face Subject ID in Workforce.</li><li>Create a Facial device and copy its one-time token.</li><li>On the camera computer open <code>http://localhost:8080/face-terminal</code>.</li><li>Connect the token, start the camera, and capture three samples per consenting employee.</li><li>The employee must blink during recognition; only subject ID and confidence are submitted.</li><li>Use localhost or HTTPS because browsers block cameras on ordinary HTTP LAN addresses.</li></ol></article></div></section>
</template>
