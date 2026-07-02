<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const mode = ref('login');
const busy = ref(false);
const error = ref('');
const result = ref('');
const form = reactive({ name: '', email: '', password: '', password_confirmation: '', reason: '' });

async function submit() {
    busy.value = true;
    error.value = '';
    result.value = '';
    try {
        if (mode.value === 'ticket') {
            const { data } = await axios.post('/api/auth/password-tickets', { email: form.email, reason: form.reason });
            result.value = `${data.message} Ticket: ${data.ticket_number}`;
            return;
        }
        mode.value === 'login' ? await auth.login(form) : await auth.register(form);
        router.push(auth.user.must_change_password ? '/app/settings' : (route.query.redirect || '/app/dashboard'));
    } catch (exception) {
        error.value = exception.response?.data?.message || Object.values(exception.response?.data?.errors || {})[0]?.[0] || 'Unable to continue.';
    } finally {
        busy.value = false;
    }
}
</script>

<template><div class="auth-page"><section class="auth-art"><img src="/media/Nenial.jpg"><h1>Build, sell, and operate with confidence.</h1><p>One secure workspace for storefront, POS, inventory, workforce, and fulfillment.</p></section><section class="auth-panel"><RouterLink to="/" class="back">← Back to store</RouterLink><form class="auth-card" @submit.prevent="submit"><span class="eyebrow">{{ mode === 'ticket' ? 'Password assistance' : mode }}</span><h2>{{ mode === 'login' ? 'Welcome back' : mode === 'register' ? 'Create customer account' : 'Request a reset ticket' }}</h2><label v-if="mode === 'register'">Full name<input v-model="form.name" required></label><label>Email<input v-model="form.email" type="email" required></label><template v-if="mode !== 'ticket'"><label>Password<input v-model="form.password" type="password" required minlength="8"></label><label v-if="mode === 'register'">Confirm password<input v-model="form.password_confirmation" type="password" required></label></template><label v-else>What happened?<textarea v-model="form.reason" rows="3" placeholder="Briefly explain why you cannot sign in"></textarea></label><p v-if="error" class="error">{{ error }}</p><p v-if="result" class="notice">{{ result }}</p><button class="btn primary" :disabled="busy">{{ busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : mode === 'register' ? 'Create account' : 'Submit ticket' }}</button><button v-if="mode === 'login'" type="button" class="text-button" @click="mode = 'ticket'">Forgot password? Request admin assistance</button><button type="button" class="text-button" @click="mode = mode === 'login' ? 'register' : 'login'">{{ mode === 'login' ? 'Need a customer account? Register' : 'Back to sign in' }}</button><small>Demo customer: demo.user@nenial.test / UserDemo2026!</small></form></section></div></template>
