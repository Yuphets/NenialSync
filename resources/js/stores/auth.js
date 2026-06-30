import { defineStore } from 'pinia';
import axios from 'axios';
export const useAuthStore = defineStore('auth', {
  state: () => ({ user: null, ready: false }),
  getters: { authenticated: s => !!s.user, role: s => s.user?.role },
  actions: {
    async hydrate(){ try { this.user=(await axios.get('/api/auth/me')).data.user; } catch { this.user=null; } finally { this.ready=true; } },
    async login(credentials){ this.user=(await axios.post('/api/auth/login',credentials)).data.user; },
    async register(data){ this.user=(await axios.post('/api/auth/register',data)).data.user; },
    async logout(){ const {data}=await axios.post('/api/auth/logout'); axios.defaults.headers.common['X-CSRF-TOKEN']=data.csrf_token; this.user=null; }
  }
});
