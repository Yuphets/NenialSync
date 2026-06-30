<script setup>
import { computed } from 'vue';import { RouterLink,RouterView,useRouter } from 'vue-router';import { useAuthStore } from '../stores/auth';
const auth=useAuthStore(),router=useRouter();
const links=computed(()=>[
 ['Dashboard','/app/dashboard','▦'],['POS Terminal','/app/pos','▣'],['Inventory','/app/inventory','▤'],['Orders','/app/orders','▥'],['Workforce','/app/workforce','◉'],['Reports','/app/reports','▧'],['Users','/app/users','◎'],['Devices','/app/devices','⌁'],['Settings','/app/settings','⚙']
].filter(([name])=>({admin:true,assistant:!['POS Terminal','Users','Devices'].includes(name),cashier:['Dashboard','POS Terminal','Inventory','Settings'].includes(name),user:['Dashboard','Orders','Settings'].includes(name)}[auth.role])));
async function logout(){await auth.logout();router.push('/');}
</script>
<template><div class="shell"><aside class="sidebar"><RouterLink class="brand" to="/"><img src="/media/Nenial.jpg"><span>Nenial<small>Operations</small></span></RouterLink><div class="identity"><strong>{{auth.user.name}}</strong><span>{{auth.user.email}}</span><b>{{auth.role}}</b></div><nav><RouterLink v-for="l in links" :key="l[1]" :to="l[1]"><i>{{l[2]}}</i>{{l[0]}}</RouterLink></nav><button class="btn ghost logout" @click="logout">Sign out</button></aside><main class="workspace"><RouterView /></main></div></template>
