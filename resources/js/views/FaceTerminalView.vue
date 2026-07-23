<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from "vue";
import axios from "axios";
import * as faceapi from "@vladmandic/face-api";
import { onBeforeRouteLeave } from "vue-router";

const video = ref(null);
const canvas = ref(null);
const token = ref(localStorage.getItem("nenial-face-device-token") || "");
const employees = ref([]);
const selectedSubject = ref("");
const status = ref("Enter the facial device token to begin.");
const connected = ref(false);
const running = ref(false);
const busy = ref(false);
const terminalMode = ref("preview");
const lastResult = ref(null);
const enrolled = ref([]);
const livenessUi = ref({
    visible: false,
    title: "Ready",
    instruction: "Position one face inside the frame.",
    progress: 0,
});
let stream = null;
let timer = null;
let employeeTimer = null;
let modelsReady = false;
let liveness = null;
let missedFrames = 0;
let detectedFaceCount = 0;
let lastDetectionIssue = "No face detected. Center one face inside the frame.";
let identityCandidate = null;
const qualityCanvas = document.createElement("canvas");
const lastSubmitted = new Map();
const manifestLink = document.querySelector('link[rel="manifest"]');
const originalManifest = manifestLink?.getAttribute("href");
if (manifestLink)
    manifestLink.setAttribute("href", "/face-manifest.webmanifest");

const selectedEmployee = computed(() =>
    employees.value.find(
        (employee) => employee.face_subject_id === selectedSubject.value,
    ),
);
const MATCH_DISTANCE = 0.44;
const CONTINUING_DISTANCE = 0.46;
const AMBIGUITY_MARGIN = 0.065;
const options = () =>
    new faceapi.SsdMobilenetv1Options({
        minConfidence: 0.52,
        maxResults: 3,
    });

function db() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open("nenial-face-terminal", 1);
        request.onupgradeneeded = () =>
            request.result.createObjectStore("templates", {
                keyPath: "subject_id",
            });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function templates() {
    const database = await db();
    return new Promise((resolve, reject) => {
        const request = database
            .transaction("templates")
            .objectStore("templates")
            .getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function putTemplate(value) {
    const database = await db();
    return new Promise((resolve, reject) => {
        const request = database
            .transaction("templates", "readwrite")
            .objectStore("templates")
            .put(value);
        request.onsuccess = resolve;
        request.onerror = () => reject(request.error);
    });
}

async function removeTemplate(subjectId) {
    const database = await db();
    return new Promise((resolve, reject) => {
        const request = database
            .transaction("templates", "readwrite")
            .objectStore("templates")
            .delete(subjectId);
        request.onsuccess = resolve;
        request.onerror = () => reject(request.error);
    });
}

async function loadModels() {
    if (modelsReady) return;
    status.value = "Loading on-device recognition models…";
    await Promise.all([
        faceapi.nets.ssdMobilenetv1.loadFromUri("/face-models"),
        faceapi.nets.faceLandmark68Net.loadFromUri("/face-models"),
        faceapi.nets.faceRecognitionNet.loadFromUri("/face-models"),
    ]);
    modelsReady = true;
}

async function connect() {
    busy.value = true;
    try {
        const data = await refreshEmployees(true);
        status.value =
            "Device authenticated. Loading on-device recognition models…";
        await loadModels();
        employees.value = data;
        localStorage.setItem("nenial-face-device-token", token.value);
        enrolled.value = await loadEnrollments();
        connected.value = true;
        status.value = `${data.length} employees loaded. Start the camera or enroll a face.`;
        clearInterval(employeeTimer);
        employeeTimer = window.setInterval(async () => {
            await refreshEmployees(false);
            enrolled.value = await loadEnrollments(false);
        }, 30000);
    } catch (error) {
        status.value =
            error.response?.data?.message ||
            error.message ||
            "Unable to connect. Verify the device token and server.";
    } finally {
        busy.value = false;
    }
}

async function refreshEmployees(throwOnError = false) {
    try {
        const { data } = await axios.get("/api/device/employees", {
            headers: { Authorization: `Bearer ${token.value}` },
            params: { _: Date.now() },
        });
        employees.value = data;
        return data;
    } catch (error) {
        if (throwOnError) throw error;
        status.value = "Employee list refresh failed. The terminal will retry automatically.";
        return employees.value;
    }
}

async function serverEnrollments() {
    const { data } = await axios.get("/api/device/face-enrollments", {
        headers: { Authorization: `Bearer ${token.value}` },
        params: { _: Date.now() },
    });
    return Array.isArray(data) ? data : [];
}

async function saveEnrollment(profile) {
    const { data } = await axios.post(
        "/api/device/face-enrollments",
        profile,
        { headers: { Authorization: `Bearer ${token.value}` } },
    );
    await putTemplate(data);
    return data;
}

async function loadEnrollments(throwOnError = false) {
    try {
        const remote = await serverEnrollments();
        for (const profile of remote) await putTemplate(profile);

        const cached = await templates();
        for (const profile of cached.filter((item) => !remote.some((remoteItem) => remoteItem.subject_id === item.subject_id))) {
            if (employees.value.some((employee) => employee.face_subject_id === profile.subject_id)) {
                await saveEnrollment(profile);
            }
        }

        return await serverEnrollments();
    } catch (error) {
        if (throwOnError) throw error;
        status.value = "Shared enrollment refresh failed. Using cached templates on this terminal.";
        return await templates();
    }
}

async function startCamera() {
    if (!window.isSecureContext)
        return (status.value = "Camera access requires localhost or HTTPS.");
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: "user" },
                width: { ideal: 1280 },
                height: { ideal: 720 },
                frameRate: { ideal: 24, max: 30 },
            },
            audio: false,
        });
        await nextTick();
        video.value.srcObject = stream;
        await video.value.play();
        const settings = stream.getVideoTracks()[0]?.getSettings?.() || {};
        running.value = true;
        terminalMode.value = "preview";
        status.value = `Camera ready${settings.width ? ` at ${settings.width}×${settings.height}` : ""}. Choose enrollment or start attendance scanning.`;
        livenessUi.value = {
            visible: true,
            title: "Preview mode",
            instruction: "Attendance scanning is paused.",
            progress: 0,
        };
        loop();
    } catch {
        status.value =
            "Camera access failed. Check browser permission and camera availability.";
    }
}

function stopCamera() {
    clearTimeout(timer);
    timer = null;
    running.value = false;
    terminalMode.value = "preview";
    liveness = null;
    identityCandidate = null;
    missedFrames = 0;
    livenessUi.value = {
        visible: false,
        title: "Ready",
        instruction: "Position one face inside the frame.",
        progress: 0,
    };
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

async function startAttendance() {
    if (!running.value) await startCamera();
    if (!running.value) return;
    if (!enrolled.value.length) {
        status.value = "Enroll at least one employee before recording attendance.";
        return;
    }
    terminalMode.value = "attendance";
    identityCandidate = null;
    missedFrames = 0;
    status.value = "Attendance scanning active. Center one face inside the frame.";
    livenessUi.value = {
        visible: true,
        title: "Attendance mode",
        instruction: "Center one face and hold still.",
        progress: 0,
    };
}

function pauseAttendance() {
    terminalMode.value = "preview";
    identityCandidate = null;
    liveness = null;
    status.value = "Attendance scanning paused. The camera remains available for preview or enrollment.";
    livenessUi.value = {
        visible: true,
        title: "Preview mode",
        instruction: "Attendance will not be recorded.",
        progress: 0,
    };
}

function frameMetrics(box = null) {
    if (!video.value?.videoWidth || !video.value?.videoHeight) return null;
    const width = 160;
    const height = Math.max(
        90,
        Math.round((width * video.value.videoHeight) / video.value.videoWidth),
    );
    qualityCanvas.width = width;
    qualityCanvas.height = height;
    const context = qualityCanvas.getContext("2d", {
        willReadFrequently: true,
    });
    context.drawImage(video.value, 0, 0, width, height);
    const scaleX = width / video.value.videoWidth;
    const scaleY = height / video.value.videoHeight;
    const region = box
        ? {
              x: Math.max(0, Math.floor(box.x * scaleX)),
              y: Math.max(0, Math.floor(box.y * scaleY)),
              width: Math.max(
                  1,
                  Math.min(width, Math.ceil(box.width * scaleX)),
              ),
              height: Math.max(
                  1,
                  Math.min(height, Math.ceil(box.height * scaleY)),
              ),
          }
        : { x: 0, y: 0, width, height };
    region.width = Math.min(region.width, width - region.x);
    region.height = Math.min(region.height, height - region.y);
    const pixels = context.getImageData(
        region.x,
        region.y,
        region.width,
        region.height,
    ).data;
    const luminance = [];
    for (let index = 0; index < pixels.length; index += 4) {
        luminance.push(
            pixels[index] * 0.2126 +
                pixels[index + 1] * 0.7152 +
                pixels[index + 2] * 0.0722,
        );
    }
    const mean =
        luminance.reduce((sum, value) => sum + value, 0) /
        Math.max(1, luminance.length);
    const contrast = Math.sqrt(
        luminance.reduce(
            (sum, value) => sum + Math.pow(value - mean, 2),
            0,
        ) / Math.max(1, luminance.length),
    );
    let detail = 0;
    let comparisons = 0;
    for (let y = 0; y < region.height - 1; y++) {
        for (let x = 0; x < region.width - 1; x++) {
            const offset = y * region.width + x;
            detail += Math.abs(luminance[offset] - luminance[offset + 1]);
            detail += Math.abs(
                luminance[offset] - luminance[offset + region.width],
            );
            comparisons += 2;
        }
    }
    return {
        brightness: mean,
        contrast,
        detail: detail / Math.max(1, comparisons),
    };
}

function noFaceIssue() {
    const metrics = frameMetrics();
    if (metrics?.brightness < 28)
        return "The camera image is too dark. Add soft light in front of your face.";
    if (metrics?.brightness > 235)
        return "The camera image is overexposed. Move away from direct light.";
    return "No face detected. Center one face in the guide and move slightly closer.";
}

function faceQualityIssue(result, strict = false) {
    const box = result.detection.box;
    const widthRatio = box.width / video.value.videoWidth;
    const heightRatio = box.height / video.value.videoHeight;
    const centerX = (box.x + box.width / 2) / video.value.videoWidth;
    const centerY = (box.y + box.height / 2) / video.value.videoHeight;
    if (result.detection.score < (strict ? 0.66 : 0.56))
        return "Face detected, but the image is unstable. Hold still and let the camera focus.";
    if (widthRatio < (strict ? 0.17 : 0.12) || heightRatio < (strict ? 0.24 : 0.18))
        return "Face detected, but it is too small. Move closer to the camera.";
    if (centerX < 0.25 || centerX > 0.75 || centerY < 0.25 || centerY > 0.72)
        return "Face detected. Center your full face inside the guide.";
    const metrics = frameMetrics(box);
    if (metrics?.brightness < 35)
        return "Face detected, but it is too dark. Add soft front lighting.";
    if (metrics?.brightness > 225)
        return "Face detected, but it is overexposed. Avoid direct light behind or above you.";
    if (strict && metrics?.contrast < 11)
        return "Face detected, but facial detail is low. Use even front lighting and a plain background.";
    if (strict && metrics?.detail < 2.6)
        return "Face detected, but the image is blurred. Hold still and let the camera focus.";
    return null;
}

async function descriptor() {
    const results = await faceapi
        .detectAllFaces(video.value, options())
        .withFaceLandmarks()
        .withFaceDescriptors();
    detectedFaceCount = results.length;
    if (!results.length) lastDetectionIssue = noFaceIssue();
    else if (results.length > 1)
        lastDetectionIssue = "Multiple faces detected. Only one person may be inside the frame.";
    else lastDetectionIssue = faceQualityIssue(results[0], false) || "";
    return results.length === 1 ? results[0] : null;
}

async function enroll() {
    if (!selectedEmployee.value)
        return (status.value = "Select an employee first.");
    busy.value = true;
    terminalMode.value = "enrollment";
    identityCandidate = null;
    liveness = null;
    if (!running.value) await startCamera();
    if (!running.value) {
        busy.value = false;
        terminalMode.value = "preview";
        return;
    }
    terminalMode.value = "enrollment";
    const samples = [];
    const totalSamples = 7;
    let firstSide = 0;
    const stages = [
        {
            title: "Face forward",
            instruction: "Look directly at the camera and hold still.",
            count: 3,
            accepts: (turn) => Math.abs(turn) <= 0.1,
        },
        {
            title: "Turn to one side",
            instruction: "Slowly turn toward either shoulder and hold.",
            count: 2,
            accepts: (turn) => Math.abs(turn) >= 0.1 && Math.abs(turn) <= 0.34,
            rememberSide: true,
        },
        {
            title: "Turn to the other side",
            instruction: "Slowly turn toward the opposite shoulder and hold.",
            count: 2,
            accepts: (turn) =>
                firstSide !== 0 &&
                Math.sign(turn) !== firstSide &&
                Math.abs(turn) >= 0.1 &&
                Math.abs(turn) <= 0.34,
        },
    ];
    try {
        for (const stage of stages) {
            const deadline = Date.now() + 20000;
            let stableFrames = 0;
            let capturedForStage = 0;
            while (capturedForStage < stage.count) {
                if (Date.now() > deadline)
                    throw new Error(
                        `${stage.title} could not be verified. Re-center your face and restart enrollment.`,
                    );
                const nextNumber = samples.length + 1;
                status.value = `Enrollment ${nextNumber}/${totalSamples}: ${stage.instruction}`;
                livenessUi.value = {
                    visible: true,
                    title: `${stage.title} · ${nextNumber}/${totalSamples}`,
                    instruction:
                        stableFrames > 0
                            ? "Good. Keep that position briefly."
                            : stage.instruction,
                    progress: Math.round(
                        (samples.length / totalSamples) * 100,
                    ),
                };
                await new Promise((resolve) => setTimeout(resolve, 450));
                const result = await descriptor();
                if (!result) {
                    stableFrames = 0;
                    status.value = lastDetectionIssue;
                    continue;
                }
                const qualityIssue = faceQualityIssue(result, true);
                if (qualityIssue) {
                    stableFrames = 0;
                    status.value = qualityIssue;
                    draw(result);
                    continue;
                }
                const turn = headTurnRatio(result.landmarks);
                if (!stage.accepts(turn)) {
                    stableFrames = 0;
                    status.value = stage.instruction;
                    draw(result);
                    continue;
                }
                stableFrames += 1;
                draw(result);
                if (stableFrames < 2) continue;

                const sample = Array.from(result.descriptor);
                if (
                    samples.length &&
                    faceapi.euclideanDistance(
                        new Float32Array(samples[samples.length - 1]),
                        result.descriptor,
                    ) > 0.4
                ) {
                    stableFrames = 0;
                    status.value =
                        "The face changed between frames. Keep only the selected employee in view.";
                    continue;
                }
                samples.push(sample);
                capturedForStage += 1;
                if (stage.rememberSide && firstSide === 0)
                    firstSide = Math.sign(turn);
                stableFrames = 0;
                livenessUi.value = {
                    visible: true,
                    title: `Sample ${samples.length}/${totalSamples} captured`,
                    instruction:
                        capturedForStage < stage.count
                            ? "Hold the same position for one more sample."
                            : "Good. Follow the next position.",
                    progress: Math.round(
                        (samples.length / totalSamples) * 100,
                    ),
                };
                await new Promise((resolve) => setTimeout(resolve, 700));
            }
        }
        const withinProfile = samples.flatMap((sample, index) =>
            samples
                .slice(index + 1)
                .map((other) =>
                    faceapi.euclideanDistance(
                        new Float32Array(sample),
                        new Float32Array(other),
                    ),
                ),
        );
        if (Math.max(...withinProfile) > 0.48)
            throw new Error(
                "The enrollment samples were inconsistent. Keep only the selected employee in frame and repeat enrollment.",
            );
        for (const profile of enrolled.value.filter(
            (item) =>
                item.subject_id !== selectedEmployee.value.face_subject_id,
        )) {
            const duplicateDistance = Math.min(
                ...samples.flatMap((sample) =>
                    profile.descriptors.map((other) =>
                        faceapi.euclideanDistance(
                            new Float32Array(sample),
                            new Float32Array(other),
                        ),
                    ),
                ),
            );
            if (duplicateDistance < 0.4)
                throw new Error(
                    `This face appears to already be enrolled as ${profile.employee_name}. Remove the incorrect enrollment first.`,
                );
        }
        await saveEnrollment({
            subject_id: selectedEmployee.value.face_subject_id,
            employee_name: selectedEmployee.value.name,
            descriptors: samples,
            enrolled_at: new Date().toISOString(),
        });
        enrolled.value = await loadEnrollments(false);
        liveness = null;
        livenessUi.value = {
            visible: true,
            title: "Enrollment complete",
            instruction:
                "Seven verified samples saved. Attendance remains paused until Start attendance is selected.",
            progress: 100,
        };
        status.value = `${selectedEmployee.value.name} enrolled successfully. Attendance was not recorded.`;
    } catch (error) {
        status.value = error.message;
    } finally {
        busy.value = false;
        terminalMode.value = "preview";
    }
}

function eyeAspectRatio(points) {
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    return (
        (distance(points[1], points[5]) + distance(points[2], points[4])) /
        (2 * distance(points[0], points[3]))
    );
}

function headTurnRatio(landmarks) {
    const leftEye = landmarks.getLeftEye();
    const rightEye = landmarks.getRightEye();
    const averageX = (points) =>
        points.reduce((sum, point) => sum + point.x, 0) / points.length;
    const leftX = averageX(leftEye);
    const rightX = averageX(rightEye);
    const eyeDistance = Math.abs(rightX - leftX);
    const nose = landmarks.getNose()[3];
    return eyeDistance > 0 ? (nose.x - (leftX + rightX) / 2) / eyeDistance : 0;
}

async function loop() {
    if (!running.value) return;
    if (terminalMode.value !== "attendance") {
        draw(null);
        timer = setTimeout(loop, 250);
        return;
    }
    try {
        const result = await descriptor();
        draw(result);
        if (
            terminalMode.value === "attendance" &&
            result &&
            enrolled.value.length &&
            !busy.value
        ) {
            missedFrames = 0;
            await recognize(result);
        } else if (
            terminalMode.value === "attendance" &&
            !result &&
            enrolled.value.length &&
            !busy.value &&
            ++missedFrames >= 3
        ) {
            liveness = null;
            identityCandidate = null;
            const multiple = detectedFaceCount > 1;
            livenessUi.value = {
                visible: true,
                title: multiple
                    ? "Multiple faces detected"
                    : "Looking for a face",
                instruction: multiple
                    ? "Only one employee may be inside the frame."
                    : lastDetectionIssue,
                progress: 0,
            };
            status.value = multiple
                ? "Multiple faces detected. Attendance was not recorded."
                : lastDetectionIssue;
        }
    } catch (error) {
        status.value = `Recognition paused: ${error.message}`;
    }
    timer = setTimeout(loop, 100);
}

function draw(result) {
    const context = canvas.value?.getContext("2d");
    if (!context || !video.value) return;
    canvas.value.width = video.value.videoWidth;
    canvas.value.height = video.value.videoHeight;
    context.clearRect(0, 0, canvas.value.width, canvas.value.height);
    if (!result) return;
    const box = result.detection.box;
    context.strokeStyle =
        liveness?.phase === "calibrating" ? "#f4c95d" : "#46dc8b";
    context.lineWidth = 4;
    context.strokeRect(box.x, box.y, box.width, box.height);
}

async function recognize(result) {
    const ranked = enrolled.value
        .map((profile) => {
            const distances = profile.descriptors
                .map((sample) =>
                    faceapi.euclideanDistance(
                        result.descriptor,
                        new Float32Array(sample),
                    ),
                )
                .sort((a, b) => a - b);
            const consensusSize = Math.min(3, distances.length);
            return {
                profile,
                best: distances[0],
                distance:
                    distances
                        .slice(0, consensusSize)
                        .reduce((sum, value) => sum + value, 0) / consensusSize,
                votes: distances.filter((value) => value <= MATCH_DISTANCE)
                    .length,
            };
        })
        .sort((a, b) => a.distance - b.distance);
    const match = ranked[0];
    const requiredVotes = Math.min(3, match?.profile.descriptors.length || 3);
    const ambiguous =
        ranked[1] && ranked[1].distance - match.distance < AMBIGUITY_MARGIN;
    const continuingLiveness =
        liveness &&
        match?.profile.subject_id === liveness.subject_id &&
        Date.now() - liveness.started <= 30000 &&
        match.distance <= CONTINUING_DISTANCE &&
        match.best <= MATCH_DISTANCE;
    if (
        !match ||
        ambiguous ||
        (!continuingLiveness &&
            (match.distance > MATCH_DISTANCE ||
                match.best > 0.41 ||
                match.votes < requiredVotes))
    ) {
        liveness = null;
        identityCandidate = null;
        status.value = ambiguous
            ? "Identity is ambiguous."
            : "Face not recognized.";
        livenessUi.value = {
            visible: true,
            title: ambiguous
                ? "Cannot confirm identity"
                : "Face not recognized",
            instruction: ambiguous
                ? "Face the camera directly and make sure only one employee is visible. Attendance was not recorded."
                : "Face forward, move slightly closer, and try again.",
            progress: 0,
        };
        return;
    }
    if (
        Date.now() - (lastSubmitted.get(match.profile.subject_id) || 0) <
        60000
    ) {
        status.value = `${match.profile.employee_name}: attendance already recorded.`;
        livenessUi.value = {
            visible: true,
            title: "Already recorded",
            instruction: "Attendance was already captured recently.",
            progress: 100,
        };
        return;
    }
    const confidence = Math.max(
        0,
        Math.min(100, ((0.65 - match.distance) / 0.35) * 100),
    );
    if (identityCandidate?.subject_id === match.profile.subject_id)
        identityCandidate.frames += 1;
    else
        identityCandidate = {
            subject_id: match.profile.subject_id,
            frames: 1,
        };
    if (identityCandidate.frames < 5) {
        status.value = `Confirming ${match.profile.employee_name} (${identityCandidate.frames}/5).`;
        livenessUi.value = {
            visible: true,
            title: "Confirming identity",
            instruction: "Face forward and hold still.",
            progress: identityCandidate.frames * 18,
        };
        return;
    }

    status.value = `${match.profile.employee_name}: identity confirmed. Recording attendance...`;
    livenessUi.value = {
        visible: true,
        title: "Verified",
        instruction: "Recording attendance...",
        progress: 100,
    };
    await submitAttendance(match.profile, confidence);
    return;

    const leftEar = eyeAspectRatio(result.landmarks.getLeftEye());
    const rightEar = eyeAspectRatio(result.landmarks.getRightEye());
    const ear = (leftEar + rightEar) / 2;
    const headTurn = headTurnRatio(result.landmarks);
    if (!Number.isFinite(ear)) return;
    if (
        !liveness ||
        liveness.subject_id !== match.profile.subject_id ||
        Date.now() - liveness.started > 30000
    ) {
        if (identityCandidate?.subject_id === match.profile.subject_id)
            identityCandidate.frames += 1;
        else
            identityCandidate = {
                subject_id: match.profile.subject_id,
                frames: 1,
            };
        if (identityCandidate.frames < 3) {
            status.value = `Confirming ${match.profile.employee_name} (${identityCandidate.frames}/3).`;
            livenessUi.value = {
                visible: true,
                title: "Confirming identity",
                instruction: "Face forward and hold still.",
                progress: identityCandidate.frames * 8,
            };
            return;
        }
        liveness = {
            subject_id: match.profile.subject_id,
            started: Date.now(),
            phase: "calibrating",
            samples: [],
            headSamples: [],
            closedFrames: 0,
            reopenedFrames: 0,
            turnFrames: 0,
            centerFrames: 0,
            baseline: null,
            headBaseline: null,
        };
    }

    if (liveness.phase === "calibrating") {
        if (ear >= 0.08 && ear <= 0.5) {
            liveness.samples.push(ear);
            liveness.headSamples.push(headTurn);
        }
        const calibrationCount = Math.min(liveness.samples.length, 4);
        status.value = `${match.profile.employee_name}: hold still while the camera calibrates (${calibrationCount}/4).`;
        livenessUi.value = {
            visible: true,
            title: `Hello, ${match.profile.employee_name}`,
            instruction: "Face forward and hold still for a moment.",
            progress: calibrationCount * 6,
        };
        if (liveness.samples.length >= 4) {
            const strongest = [...liveness.samples]
                .sort((a, b) => b - a)
                .slice(0, 3);
            liveness.baseline =
                strongest.reduce((sum, value) => sum + value, 0) /
                strongest.length;
            liveness.headBaseline =
                liveness.headSamples.reduce((sum, value) => sum + value, 0) /
                liveness.headSamples.length;
            liveness.phase = "waiting_for_gesture";
            status.value = `${match.profile.employee_name}: blink once or slowly turn your head to either side.`;
            livenessUi.value = {
                visible: true,
                title: "Liveness check",
                instruction:
                    "Blink once, or slowly turn your head to either side.",
                progress: 30,
            };
        }
        return;
    }

    const closeThreshold = Math.max(0.07, liveness.baseline * 0.78);
    const reopenThreshold = Math.max(
        closeThreshold + 0.015,
        liveness.baseline * 0.88,
    );
    if (liveness.phase === "waiting_for_gesture") {
        liveness.baseline = Math.max(liveness.baseline * 0.995, ear);
        if (ear < closeThreshold) liveness.closedFrames += 1;
        else liveness.closedFrames = 0;
        if (Math.abs(headTurn - liveness.headBaseline) >= 0.12)
            liveness.turnFrames += 1;
        else liveness.turnFrames = 0;
        status.value = `${match.profile.employee_name}: blink once or slowly turn your head to either side.`;
        livenessUi.value = {
            visible: true,
            title: "Liveness check",
            instruction: "Blink once, or slowly turn your head to either side.",
            progress: 35,
        };
        if (liveness.closedFrames >= 2) {
            liveness.phase = "waiting_for_reopen";
            status.value = `${match.profile.employee_name}: blink detected. Open both eyes.`;
            livenessUi.value = {
                visible: true,
                title: "Blink detected",
                instruction: "Open both eyes to finish.",
                progress: 72,
            };
        } else if (liveness.turnFrames >= 2) {
            liveness.phase = "waiting_for_center";
            status.value = `${match.profile.employee_name}: head turn detected. Face forward again.`;
            livenessUi.value = {
                visible: true,
                title: "Movement detected",
                instruction: "Face forward again to finish.",
                progress: 72,
            };
        }
        return;
    }

    if (liveness.phase === "waiting_for_center") {
        if (Math.abs(headTurn - liveness.headBaseline) <= 0.08)
            liveness.centerFrames += 1;
        else liveness.centerFrames = 0;
        status.value = liveness.centerFrames
            ? "Liveness confirmed. Recording attendance…"
            : `${match.profile.employee_name}: face forward to finish.`;
        livenessUi.value = {
            visible: true,
            title: liveness.centerFrames ? "Verified" : "Face forward",
            instruction: liveness.centerFrames
                ? "Recording attendance…"
                : "Return to the center position.",
            progress: liveness.centerFrames ? 100 : 78,
        };
        if (liveness.centerFrames >= 2)
            await submitAttendance(match.profile, confidence);
        return;
    }

    if (ear > reopenThreshold) liveness.reopenedFrames += 1;
    else liveness.reopenedFrames = 0;
    status.value = liveness.reopenedFrames
        ? "Liveness confirmed. Recording attendance…"
        : `${match.profile.employee_name}: open both eyes to finish.`;
    livenessUi.value = {
        visible: true,
        title: liveness.reopenedFrames ? "Verified" : "Open your eyes",
        instruction: liveness.reopenedFrames
            ? "Recording attendance…"
            : "Look at the camera to finish.",
        progress: liveness.reopenedFrames ? 100 : 78,
    };
    if (liveness.reopenedFrames >= 2)
        await submitAttendance(match.profile, confidence);
}

async function submitAttendance(profile, confidence) {
    busy.value = true;
    try {
        const eventId = crypto.randomUUID();
        const { data } = await axios.post(
            "/api/device/attendance",
            {
                subject_id: profile.subject_id,
                event_id: eventId,
                recognized_at: new Date().toISOString(),
                confidence: Number(confidence.toFixed(2)),
                status: "present",
            },
            { headers: { Authorization: `Bearer ${token.value}` } },
        );
        lastResult.value = {
            name: profile.employee_name,
            time: new Date(data.recognized_at).toLocaleString("en-US", {
                timeZone: "Asia/Manila",
            }),
            confidence: Number(data.match_confidence || confidence).toFixed(1),
        };
        lastSubmitted.set(profile.subject_id, Date.now());
        status.value = data.already_recorded
            ? `${profile.employee_name} already timed in today.`
            : `Attendance recorded for ${profile.employee_name}.`;
        livenessUi.value = {
            visible: true,
            title: data.already_recorded
                ? "Already timed in"
                : "Attendance recorded",
            instruction: `${profile.employee_name} may step away.`,
            progress: 100,
        };
        liveness = null;
        identityCandidate = null;
        await new Promise((resolve) => setTimeout(resolve, 3000));
    } catch (error) {
        status.value =
            error.response?.data?.message ||
            "Attendance submission failed; the terminal will keep running.";
    } finally {
        busy.value = false;
    }
}

async function forget(profile) {
    if (
        confirm(
            `Remove the shared facial template for ${profile.employee_name}?`,
        )
    ) {
        try {
            await axios.delete(
                `/api/device/face-enrollments/${encodeURIComponent(profile.subject_id)}`,
                { headers: { Authorization: `Bearer ${token.value}` } },
            );
        } catch (error) {
            status.value =
                error.response?.data?.message ||
                "Shared enrollment removal failed. Removing local cache only.";
        }
        await removeTemplate(profile.subject_id);
        enrolled.value = await loadEnrollments(false);
    }
}

onBeforeUnmount(() => {
    stopCamera();
    clearInterval(employeeTimer);
    if (manifestLink && originalManifest)
        manifestLink.setAttribute("href", originalManifest);
});
onBeforeRouteLeave((to) => (to.path === "/" ? "/app/dashboard" : true));
</script>

<template>
    <main class="face-terminal">
        <header>
            <div>
                <span class="eyebrow">Nenial Attendance</span>
                <h1>Facial Recognition Terminal</h1>
            </div>
            <RouterLink class="btn" to="/">Exit terminal</RouterLink>
        </header>
        <p class="terminal-status" :class="{ connected }" aria-live="polite">
            {{ status }}
        </p>
        <section v-if="!connected" class="terminal-connect">
            <label
                >Facial device token<input
                    v-model="token"
                    type="password"
                    autocomplete="off"
                    placeholder="Paste the one-time device token" /></label
            ><button
                class="btn primary"
                :disabled="busy || !token"
                @click="connect"
            >
                {{ busy ? "Loading…" : "Connect terminal" }}</button
            ><small
                >Use this page on <b>localhost</b> or behind HTTPS. The token
                and facial descriptors synchronize with Nenial servers for authorized terminals.</small
            >
        </section>
        <template v-else
            ><div class="terminal-grid">
                <section class="camera-stage">
                    <video ref="video" class="mirrored-camera" muted playsinline></video
                    ><canvas ref="canvas" class="mirrored-camera"></canvas>
                    <div
                        v-if="running"
                        class="face-position-guide"
                        aria-hidden="true"
                    ></div>
                    <div
                        v-if="running && livenessUi.visible"
                        class="liveness-guide"
                        role="status"
                        aria-live="polite"
                    >
                        <strong>{{ livenessUi.title }}</strong
                        ><span>{{ livenessUi.instruction }}</span>
                        <div
                            class="liveness-progress"
                            role="progressbar"
                            aria-label="Liveness progress"
                            :aria-valuenow="livenessUi.progress"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <i
                                :style="{ width: `${livenessUi.progress}%` }"
                            ></i>
                        </div>
                    </div>
                    <div v-if="!running" class="camera-placeholder">
                        Camera is off
                    </div>
                    <div class="camera-controls">
                        <button
                            v-if="!running"
                            class="btn primary"
                            @click="startCamera"
                        >
                            Start camera
                        </button>
                        <template v-else>
                            <button
                                v-if="terminalMode !== 'attendance'"
                                class="btn primary"
                                :disabled="busy || !enrolled.length"
                                @click="startAttendance"
                            >
                                Start attendance
                            </button>
                            <button
                                v-else
                                class="btn light"
                                @click="pauseAttendance"
                            >
                                Pause attendance
                            </button>
                            <button class="btn" :disabled="busy" @click="stopCamera">
                                Stop camera
                            </button>
                        </template>
                    </div>
                </section>
                <aside>
                    <section class="terminal-card">
                        <div class="enrollment-head"><h2>Enroll employee</h2><button class="btn tiny" :disabled="busy" @click="refreshEmployees(false)">Refresh list</button></div>
                        <label
                            >Employee<select v-model="selectedSubject">
                                <option value="">Choose employee</option>
                                <option
                                    v-for="employee in employees"
                                    :key="employee.face_subject_id"
                                    :value="employee.face_subject_id"
                                >
                                    {{ employee.name }} ·
                                    {{ employee.employee_number }}
                                </option>
                            </select></label
                        ><button
                            class="btn primary full"
                            :disabled="busy || !selectedSubject"
                            @click="enroll"
                        >
                            Start guided enrollment</button
                        ><small
                            >Obtain employee consent. Enrollment stores
                            seven verified numerical descriptors across forward and side angles; photos are not stored. Allow about 15–30 seconds and follow each camera instruction. Attendance remains paused after enrollment.</small
                        >
                    </section>
                    <section v-if="lastResult" class="terminal-card success">
                        <h2>Attendance recorded</h2>
                        <strong>{{ lastResult.name }}</strong
                        ><span>{{ lastResult.time }}</span
                        ><small
                            >Match confidence
                            {{ lastResult.confidence }}%</small
                        >
                    </section>
                    <section class="terminal-card">
                        <h2>Shared enrollments</h2>
                        <div v-if="!enrolled.length" class="empty">
                            No employees enrolled yet.
                        </div>
                        <div
                            v-for="profile in enrolled"
                            :key="profile.subject_id"
                            class="enrollment"
                        >
                            <span
                                ><strong>{{ profile.employee_name }}</strong
                                ><small
                                    >{{
                                        profile.descriptors.length
                                    }}
                                    samples</small
                                ></span
                            ><button
                                class="btn tiny danger"
                                @click="forget(profile)"
                            >
                                Remove
                            </button>
                        </div>
                    </section>
                </aside>
            </div></template
        >
    </main>
</template>
<style scoped>
.enrollment-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.mirrored-camera { transform: scaleX(-1); }
.face-position-guide {
    position: absolute;
    z-index: 1;
    width: min(42%, 320px);
    aspect-ratio: 3 / 4;
    border: 2px dashed rgba(255, 255, 255, 0.62);
    border-radius: 48% 48% 44% 44%;
    box-shadow: 0 0 0 999px rgba(0, 0, 0, 0.08);
    pointer-events: none;
}
.liveness-guide {
    position: absolute;
    z-index: 3;
    top: 18px;
    left: 50%;
    display: grid;
    gap: 0.35rem;
    width: min(520px, calc(100% - 32px));
    padding: 12px 14px;
    transform: translateX(-50%);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 12px;
    color: #fff;
    background: rgba(5, 31, 19, 0.84);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
    backdrop-filter: blur(10px);
    text-align: center;
}
.liveness-guide span { color: #d5e8dc; font-size: 0.82rem; }
.liveness-progress {
    height: 6px;
    overflow: hidden;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.2);
}
.liveness-progress i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #52d88d;
    transition: width 0.25s ease;
}
.camera-controls {
    position: absolute;
    z-index: 4;
    bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.6rem;
}
.camera-stage .camera-controls .btn { position: static; }
</style>
