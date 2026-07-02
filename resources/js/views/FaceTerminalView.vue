<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';
import * as faceapi from '@vladmandic/face-api';
import { onBeforeRouteLeave } from 'vue-router';

const video = ref(null);
const canvas = ref(null);
const token = ref(localStorage.getItem('nenial-face-device-token') || '');
const employees = ref([]);
const selectedSubject = ref('');
const status = ref('Enter the facial device token to begin.');
const connected = ref(false);
const running = ref(false);
const busy = ref(false);
const lastResult = ref(null);
const enrolled = ref([]);
const livenessUi = ref({ visible: false, title: 'Ready', instruction: 'Position one face inside the frame.', progress: 0 });
let stream = null;
let timer = null;
let modelsReady = false;
let liveness = null;
let missedFrames = 0;
const lastSubmitted = new Map();
const manifestLink = document.querySelector('link[rel="manifest"]');
const originalManifest = manifestLink?.getAttribute('href');
if (manifestLink) manifestLink.setAttribute('href', '/face-manifest.webmanifest');

const selectedEmployee = computed(() => employees.value.find(employee => employee.face_subject_id === selectedSubject.value));
const options = () => new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.6 });

function db() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('nenial-face-terminal', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('templates', { keyPath: 'subject_id' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function templates() {
    const database = await db();
    return new Promise((resolve, reject) => {
        const request = database.transaction('templates').objectStore('templates').getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function putTemplate(value) {
    const database = await db();
    return new Promise((resolve, reject) => {
        const request = database.transaction('templates', 'readwrite').objectStore('templates').put(value);
        request.onsuccess = resolve; request.onerror = () => reject(request.error);
    });
}

async function removeTemplate(subjectId) {
    const database = await db();
    return new Promise((resolve, reject) => {
        const request = database.transaction('templates', 'readwrite').objectStore('templates').delete(subjectId);
        request.onsuccess = resolve; request.onerror = () => reject(request.error);
    });
}

async function loadModels() {
    if (modelsReady) return;
    status.value = 'Loading on-device recognition models…';
    await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri('/face-models'),
        faceapi.nets.faceLandmark68TinyNet.loadFromUri('/face-models'),
        faceapi.nets.faceRecognitionNet.loadFromUri('/face-models'),
    ]);
    modelsReady = true;
}

async function connect() {
    busy.value = true;
    try {
        const { data } = await axios.get('/api/device/employees', { headers: { Authorization: `Bearer ${token.value}` } });
        status.value = 'Device authenticated. Loading on-device recognition modelsâ€¦';
        await loadModels();
        employees.value = data;
        localStorage.setItem('nenial-face-device-token', token.value);
        enrolled.value = await templates();
        connected.value = true;
        status.value = `${data.length} employees loaded. Start the camera or enroll a face.`;
    } catch (error) {
        status.value = error.response?.data?.message || error.message || 'Unable to connect. Verify the device token and server.';
    } finally { busy.value = false; }
}

async function startCamera() {
    if (!window.isSecureContext) return status.value = 'Camera access requires localhost or HTTPS.';
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
        await nextTick();
        video.value.srcObject = stream;
        await video.value.play();
        running.value = true;
        status.value = 'Camera ready. Face forward and blink when prompted.';
        livenessUi.value = enrolled.value.length
            ? { visible: true, title: 'Ready to scan', instruction: 'Center one face inside the frame.', progress: 0 }
            : { visible: true, title: 'Enrollment required', instruction: 'Enroll an employee before recording attendance.', progress: 0 };
        loop();
    } catch { status.value = 'Camera access failed. Check browser permission and camera availability.'; }
}

function stopCamera() {
    clearTimeout(timer); timer = null; running.value = false; liveness = null; missedFrames = 0;
    livenessUi.value = { visible: false, title: 'Ready', instruction: 'Position one face inside the frame.', progress: 0 };
    stream?.getTracks().forEach(track => track.stop()); stream = null;
}

async function descriptor() {
    return faceapi.detectSingleFace(video.value, options()).withFaceLandmarks(true).withFaceDescriptor();
}

async function enroll() {
    if (!selectedEmployee.value) return status.value = 'Select an employee first.';
    if (!running.value) await startCamera();
    if (!running.value) return;
    busy.value = true;
    const samples = [];
    try {
        for (let index = 0; index < 3; index++) {
            status.value = `Enrollment sample ${index + 1}/3: ${index === 0 ? 'face forward' : index === 1 ? 'turn slightly left' : 'turn slightly right'}.`;
            await new Promise(resolve => setTimeout(resolve, 1500));
            const result = await descriptor();
            if (!result) throw new Error('Face not clearly detected. Improve lighting and try again.');
            samples.push(Array.from(result.descriptor));
        }
        await putTemplate({ subject_id: selectedEmployee.value.face_subject_id, employee_name: selectedEmployee.value.name, descriptors: samples, enrolled_at: new Date().toISOString() });
        enrolled.value = await templates();
        liveness = null;
        livenessUi.value = { visible: true, title: 'Enrollment complete', instruction: 'Face forward when ready to record attendance.', progress: 0 };
        status.value = `${selectedEmployee.value.name} enrolled successfully. No photo was stored.`;
    } catch (error) { status.value = error.message; } finally { busy.value = false; }
}

function eyeAspectRatio(points) {
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    return (distance(points[1], points[5]) + distance(points[2], points[4])) / (2 * distance(points[0], points[3]));
}

function headTurnRatio(landmarks) {
    const leftEye = landmarks.getLeftEye();
    const rightEye = landmarks.getRightEye();
    const averageX = points => points.reduce((sum, point) => sum + point.x, 0) / points.length;
    const leftX = averageX(leftEye);
    const rightX = averageX(rightEye);
    const eyeDistance = Math.abs(rightX - leftX);
    const nose = landmarks.getNose()[3];
    return eyeDistance > 0 ? (nose.x - ((leftX + rightX) / 2)) / eyeDistance : 0;
}

async function loop() {
    if (!running.value) return;
    try {
        const result = await descriptor();
        draw(result);
        if (result && enrolled.value.length && !busy.value) {
            missedFrames = 0;
            await recognize(result);
        } else if (!result && enrolled.value.length && !busy.value && ++missedFrames >= 3) {
            liveness = null;
            livenessUi.value = { visible: true, title: 'Looking for a face', instruction: 'Center your face and use even front lighting.', progress: 0 };
        }
    } catch (error) { status.value = `Recognition paused: ${error.message}`; }
    timer = setTimeout(loop, 100);
}

function draw(result) {
    const context = canvas.value?.getContext('2d');
    if (!context || !video.value) return;
    canvas.value.width = video.value.videoWidth; canvas.value.height = video.value.videoHeight;
    context.clearRect(0, 0, canvas.value.width, canvas.value.height);
    if (!result) return;
    const box = result.detection.box;
    context.strokeStyle = liveness?.phase === 'calibrating' ? '#f4c95d' : '#46dc8b'; context.lineWidth = 4; context.strokeRect(box.x, box.y, box.width, box.height);
}

async function recognize(result) {
    let match = null;
    for (const profile of enrolled.value) {
        for (const sample of profile.descriptors) {
            const distance = faceapi.euclideanDistance(result.descriptor, new Float32Array(sample));
            if (!match || distance < match.distance) match = { profile, distance };
        }
    }
    const continuingLiveness = liveness
        && match?.profile.subject_id === liveness.subject_id
        && Date.now() - liveness.started <= 30000
        && match.distance <= 0.62;
    if (!match || (match.distance > 0.5 && !continuingLiveness)) {
        liveness = null;
        status.value = 'Face not recognized.';
        livenessUi.value = { visible: true, title: 'Face not recognized', instruction: 'Face forward, move slightly closer, and try again.', progress: 0 };
        return;
    }
    if (Date.now() - (lastSubmitted.get(match.profile.subject_id) || 0) < 60000) {
        status.value = `${match.profile.employee_name}: attendance already recorded.`;
        livenessUi.value = { visible: true, title: 'Already recorded', instruction: 'Attendance was already captured recently.', progress: 100 };
        return;
    }
    const confidence = Math.max(0, Math.min(100, (1 - match.distance) * 100));
    const leftEar = eyeAspectRatio(result.landmarks.getLeftEye());
    const rightEar = eyeAspectRatio(result.landmarks.getRightEye());
    const ear = (leftEar + rightEar) / 2;
    const headTurn = headTurnRatio(result.landmarks);
    if (!Number.isFinite(ear)) return;
    if (!liveness || liveness.subject_id !== match.profile.subject_id || Date.now() - liveness.started > 30000) {
        liveness = { subject_id: match.profile.subject_id, started: Date.now(), phase: 'calibrating', samples: [], headSamples: [], closedFrames: 0, reopenedFrames: 0, turnFrames: 0, centerFrames: 0, baseline: null, headBaseline: null };
    }

    if (liveness.phase === 'calibrating') {
        if (ear >= 0.08 && ear <= 0.5) {
            liveness.samples.push(ear);
            liveness.headSamples.push(headTurn);
        }
        const calibrationCount = Math.min(liveness.samples.length, 4);
        status.value = `${match.profile.employee_name}: hold still while the camera calibrates (${calibrationCount}/4).`;
        livenessUi.value = { visible: true, title: `Hello, ${match.profile.employee_name}`, instruction: 'Face forward and hold still for a moment.', progress: calibrationCount * 6 };
        if (liveness.samples.length >= 4) {
            const strongest = [...liveness.samples].sort((a, b) => b - a).slice(0, 3);
            liveness.baseline = strongest.reduce((sum, value) => sum + value, 0) / strongest.length;
            liveness.headBaseline = liveness.headSamples.reduce((sum, value) => sum + value, 0) / liveness.headSamples.length;
            liveness.phase = 'waiting_for_gesture';
            status.value = `${match.profile.employee_name}: blink once or slowly turn your head to either side.`;
            livenessUi.value = { visible: true, title: 'Liveness check', instruction: 'Blink once, or slowly turn your head to either side.', progress: 30 };
        }
        return;
    }

    const closeThreshold = Math.max(0.07, liveness.baseline * 0.78);
    const reopenThreshold = Math.max(closeThreshold + 0.015, liveness.baseline * 0.88);
    if (liveness.phase === 'waiting_for_gesture') {
        liveness.baseline = Math.max(liveness.baseline * 0.995, ear);
        if (ear < closeThreshold) liveness.closedFrames += 1;
        else liveness.closedFrames = 0;
        if (Math.abs(headTurn - liveness.headBaseline) >= 0.12) liveness.turnFrames += 1;
        else liveness.turnFrames = 0;
        status.value = `${match.profile.employee_name}: blink once or slowly turn your head to either side.`;
        livenessUi.value = { visible: true, title: 'Liveness check', instruction: 'Blink once, or slowly turn your head to either side.', progress: 35 };
        if (liveness.closedFrames >= 2) {
            liveness.phase = 'waiting_for_reopen';
            status.value = `${match.profile.employee_name}: blink detected. Open both eyes.`;
            livenessUi.value = { visible: true, title: 'Blink detected', instruction: 'Open both eyes to finish.', progress: 72 };
        } else if (liveness.turnFrames >= 2) {
            liveness.phase = 'waiting_for_center';
            status.value = `${match.profile.employee_name}: head turn detected. Face forward again.`;
            livenessUi.value = { visible: true, title: 'Movement detected', instruction: 'Face forward again to finish.', progress: 72 };
        }
        return;
    }

    if (liveness.phase === 'waiting_for_center') {
        if (Math.abs(headTurn - liveness.headBaseline) <= 0.08) liveness.centerFrames += 1;
        else liveness.centerFrames = 0;
        status.value = liveness.centerFrames ? 'Liveness confirmed. Recording attendance…' : `${match.profile.employee_name}: face forward to finish.`;
        livenessUi.value = { visible: true, title: liveness.centerFrames ? 'Verified' : 'Face forward', instruction: liveness.centerFrames ? 'Recording attendance…' : 'Return to the center position.', progress: liveness.centerFrames ? 100 : 78 };
        if (liveness.centerFrames >= 2) await submitAttendance(match.profile, confidence);
        return;
    }

    if (ear > reopenThreshold) liveness.reopenedFrames += 1;
    else liveness.reopenedFrames = 0;
    status.value = liveness.reopenedFrames ? 'Liveness confirmed. Recording attendance…' : `${match.profile.employee_name}: open both eyes to finish.`;
    livenessUi.value = { visible: true, title: liveness.reopenedFrames ? 'Verified' : 'Open your eyes', instruction: liveness.reopenedFrames ? 'Recording attendance…' : 'Look at the camera to finish.', progress: liveness.reopenedFrames ? 100 : 78 };
    if (liveness.reopenedFrames >= 2) await submitAttendance(match.profile, confidence);
}

async function submitAttendance(profile, confidence) {
    busy.value = true;
    try {
        const eventId = crypto.randomUUID();
        const { data } = await axios.post('/api/device/attendance', { subject_id: profile.subject_id, event_id: eventId, recognized_at: new Date().toISOString(), confidence: Number(confidence.toFixed(2)), status: 'present' }, { headers: { Authorization: `Bearer ${token.value}` } });
        lastResult.value = { name: profile.employee_name, time: new Date(data.recognized_at).toLocaleString('en-US', { timeZone: 'Asia/Manila' }), confidence: Number(data.match_confidence || confidence).toFixed(1) };
        lastSubmitted.set(profile.subject_id, Date.now());
        status.value = data.already_recorded ? `${profile.employee_name} already timed in today.` : `Attendance recorded for ${profile.employee_name}.`;
        livenessUi.value = { visible: true, title: data.already_recorded ? 'Already timed in' : 'Attendance recorded', instruction: `${profile.employee_name} may step away.`, progress: 100 };
        liveness = null;
        await new Promise(resolve => setTimeout(resolve, 3000));
    } catch (error) { status.value = error.response?.data?.message || 'Attendance submission failed; the terminal will keep running.'; } finally { busy.value = false; }
}

async function forget(profile) {
    if (confirm(`Remove the local facial template for ${profile.employee_name}?`)) { await removeTemplate(profile.subject_id); enrolled.value = await templates(); }
}

onBeforeUnmount(() => {
    stopCamera();
    if (manifestLink && originalManifest) manifestLink.setAttribute('href', originalManifest);
});
onBeforeRouteLeave(to => to.path === '/' ? '/app/dashboard' : true);
</script>

<template><main class="face-terminal"><header><div><span class="eyebrow">Nenial Attendance</span><h1>Facial Recognition Terminal</h1></div><RouterLink class="btn" to="/">Exit terminal</RouterLink></header><p class="terminal-status" :class="{ connected }" aria-live="polite">{{ status }}</p><section v-if="!connected" class="terminal-connect"><label>Facial device token<input v-model="token" type="password" autocomplete="off" placeholder="Paste the one-time device token"></label><button class="btn primary" :disabled="busy || !token" @click="connect">{{ busy ? 'Loading…' : 'Connect terminal' }}</button><small>Use this page on <b>localhost</b> or behind HTTPS. The token and facial descriptors remain on this terminal.</small></section><template v-else><div class="terminal-grid"><section class="camera-stage"><video ref="video" muted playsinline></video><canvas ref="canvas"></canvas><div v-if="running && livenessUi.visible" class="liveness-guide" role="status" aria-live="polite"><strong>{{ livenessUi.title }}</strong><span>{{ livenessUi.instruction }}</span><div class="liveness-progress" role="progressbar" aria-label="Liveness progress" :aria-valuenow="livenessUi.progress" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: `${livenessUi.progress}%` }"></i></div></div><div v-if="!running" class="camera-placeholder">Camera is off</div><button v-if="!running" class="btn primary" @click="startCamera">Start camera</button><button v-else class="btn" @click="stopCamera">Stop camera</button></section><aside><section class="terminal-card"><h2>Enroll employee</h2><label>Employee<select v-model="selectedSubject"><option value="">Choose employee</option><option v-for="employee in employees" :key="employee.face_subject_id" :value="employee.face_subject_id">{{ employee.name }} · {{ employee.employee_number }}</option></select></label><button class="btn primary full" :disabled="busy || !selectedSubject" @click="enroll">Capture three samples</button><small>Obtain employee consent. Enrollment stores numerical descriptors only in this browser.</small></section><section v-if="lastResult" class="terminal-card success"><h2>Attendance recorded</h2><strong>{{ lastResult.name }}</strong><span>{{ lastResult.time }}</span><small>Match confidence {{ lastResult.confidence }}%</small></section><section class="terminal-card"><h2>Local enrollments</h2><div v-if="!enrolled.length" class="empty">No employees enrolled on this terminal.</div><div v-for="profile in enrolled" :key="profile.subject_id" class="enrollment"><span><strong>{{ profile.employee_name }}</strong><small>{{ profile.descriptors.length }} samples</small></span><button class="btn tiny danger" @click="forget(profile)">Remove</button></div></section></aside></div></template></main></template>
