<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from "vue";
import axios from "axios";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const mode = ref(route.query.mode === "register" ? "register" : "login");
const busy = ref(false);
const error = ref(route.query.oauth_error || "");
const result = ref("");
const temporaryPassword = ref("");
const ticketNumber = ref(localStorage.getItem("nenial-password-ticket") || "");
const verificationEmail = ref("");
const capabilities = ref({ email_delivery: true, google: false });
const form = reactive({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    reason: "",
    code: "",
});
let ticketTimer;

function destination() {
    if (auth.user.must_change_password) return "/app/settings";
    return auth.role === "user"
        ? "/"
        : route.query.redirect || "/app/dashboard";
}

async function submit() {
    busy.value = true;
    error.value = "";
    result.value = "";
    try {
        if (mode.value === "ticket") {
            const { data } = await axios.post("/api/auth/password-tickets", {
                email: form.email,
                reason: form.reason,
            });
            ticketNumber.value = data.ticket_number;
            localStorage.setItem("nenial-password-ticket", data.ticket_number);
            result.value = `${data.message} Ticket: ${data.ticket_number}`;
            startTicketPolling();
            return;
        }
        if (mode.value === "verify") {
            await auth.verifyEmail({
                email: verificationEmail.value || form.email,
                code: form.code,
            });
            router.push("/");
            return;
        }
        if (mode.value === "register") {
            const data = await auth.register(form);
            verificationEmail.value = data.email;
            form.email = data.email;
            mode.value = "verify";
            result.value = data.message;
            if (data.development_code) {
                form.code = data.development_code;
                result.value += ` Code: ${data.development_code}`;
            }
            return;
        }
        await auth.login(form);
        router.push(destination());
    } catch (exception) {
        const payload = exception.response?.data;
        if (payload?.verification_required) {
            verificationEmail.value = payload.email;
            mode.value = "verify";
        }
        error.value =
            payload?.message ||
            Object.values(payload?.errors || {})[0]?.[0] ||
            "Unable to continue.";
    } finally {
        busy.value = false;
    }
}

async function resendOtp() {
    try {
        result.value = (
            await axios.post("/api/auth/resend-otp", {
                email: verificationEmail.value || form.email,
            })
        ).data.message;
    } catch (exception) {
        error.value =
            exception.response?.data?.message || "Unable to resend the code.";
    }
}

async function checkTicket() {
    if (!ticketNumber.value || !form.email) return;
    try {
        const { data } = await axios.post("/api/auth/password-ticket-status", {
            email: form.email,
            ticket_number: ticketNumber.value,
        });
        result.value = data.message;
        if (data.temporary_password) {
            temporaryPassword.value = data.temporary_password;
            form.password = data.temporary_password;
            clearInterval(ticketTimer);
        }
    } catch {
        /* Keep polling without exposing whether another ticket exists. */
    }
}

function startTicketPolling() {
    clearInterval(ticketTimer);
    checkTicket();
    ticketTimer = window.setInterval(checkTicket, 5000);
}

function googleLogin() {
    window.location.assign("/auth/google/redirect");
}
onMounted(async () => {
    try {
        capabilities.value = (await axios.get("/api/auth/capabilities")).data;
    } catch {
        /* Keep password login available. */
    }
});
onBeforeUnmount(() => clearInterval(ticketTimer));
</script>

<template>
    <div class="auth-page">
        <section class="auth-art">
            <img src="/media/Nenial.jpg" />
            <h1>Build, sell, and operate with confidence.</h1>
            <p>
                One secure workspace for storefront, POS, inventory, workforce,
                and fulfillment.
            </p>
        </section>
        <section class="auth-panel">
            <RouterLink to="/" class="back">← Back to store</RouterLink>
            <form class="auth-card" @submit.prevent="submit">
                <span class="eyebrow">{{
                    mode === "ticket"
                        ? "Password assistance"
                        : mode === "verify"
                          ? "Email verification"
                          : mode
                }}</span>
                <h2>
                    {{
                        mode === "login"
                            ? "Welcome back"
                            : mode === "register"
                              ? "Create customer account"
                              : mode === "verify"
                                ? "Check your email"
                                : "Request a reset ticket"
                    }}
                </h2>
                <label v-if="mode === 'register'"
                    >Full name<input
                        v-model="form.name"
                        required
                        autocomplete="name" /></label
                ><label v-if="mode !== 'verify'"
                    >Email<input
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                /></label>
                <p v-else>
                    Enter the six-digit code sent to
                    <strong>{{ verificationEmail || form.email }}</strong
                    >.
                </p>
                <label v-if="mode === 'verify'"
                    >Verification code<input
                        v-model="form.code"
                        inputmode="numeric"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        autocomplete="one-time-code"
                        required /></label
                ><template v-if="['login', 'register'].includes(mode)"
                    ><label
                        >Password<input
                            v-model="form.password"
                            type="password"
                            required
                            minlength="8"
                            :autocomplete="
                                mode === 'login'
                                    ? 'current-password'
                                    : 'new-password'
                            " /></label
                    ><label v-if="mode === 'register'"
                        >Confirm password<input
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password" /></label></template
                ><label v-if="mode === 'ticket'"
                    >What happened?<textarea
                        v-model="form.reason"
                        rows="3"
                        placeholder="Briefly explain why you cannot sign in"
                    ></textarea>
                </label>
                <p v-if="error" class="error">{{ error }}</p>
                <p v-if="result" class="notice">{{ result }}</p>
                <div v-if="temporaryPassword" class="temporary-password">
                    <span>Temporary password</span
                    ><code>{{ temporaryPassword }}</code
                    ><button
                        type="button"
                        class="btn tiny"
                        @click="
                            navigator.clipboard.writeText(temporaryPassword)
                        "
                    >
                        Copy
                    </button>
                </div>
                <button class="btn primary" :disabled="busy">
                    {{
                        busy
                            ? "Please wait…"
                            : mode === "login"
                              ? "Sign in"
                              : mode === "register"
                                ? "Send verification code"
                                : mode === "verify"
                                  ? "Verify email"
                                  : "Submit ticket"
                    }}</button
                ><button
                    v-if="mode === 'verify'"
                    type="button"
                    class="text-button"
                    @click="resendOtp"
                >
                    Resend code</button
                ><template v-if="['login', 'register'].includes(mode)"
                    ><div class="auth-divider"><span>or</span></div>
                    <button
                        type="button"
                        class="btn google"
                        :disabled="!capabilities.google"
                        :title="capabilities.google ? 'Continue with Google' : 'Google sign-in has not been configured by the administrator'"
                        @click="googleLogin"
                    >
                        <b>G</b> {{ capabilities.google ? 'Continue with Google' : 'Google sign-in not configured' }}
                    </button></template
                ><button
                    v-if="mode === 'login'"
                    type="button"
                    class="text-button"
                    @click="
                        mode = 'ticket';
                        startTicketPolling();
                    "
                >
                    Forgot password? Request admin assistance</button
                ><button
                    v-if="mode === 'ticket' && ticketNumber"
                    type="button"
                    class="text-button"
                    @click="checkTicket"
                >
                    Check ticket status</button
                ><button
                    type="button"
                    class="text-button"
                    @click="mode = mode === 'login' ? 'register' : 'login'"
                >
                    {{
                        mode === "login"
                            ? "Need a customer account? Register"
                            : "Back to sign in"
                    }}
                </button>
            </form>
        </section>
    </div>
</template>
