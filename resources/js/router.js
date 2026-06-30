import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';
import AppShell from './components/AppShell.vue';
const StorefrontView=()=>import('./views/StorefrontView.vue'),LoginView=()=>import('./views/LoginView.vue'),DashboardView=()=>import('./views/DashboardView.vue'),PosView=()=>import('./views/PosView.vue'),InventoryView=()=>import('./views/InventoryView.vue'),OrdersView=()=>import('./views/OrdersView.vue'),WorkforceView=()=>import('./views/WorkforceView.vue'),ReportsView=()=>import('./views/ReportsView.vue'),UsersView=()=>import('./views/UsersView.vue'),DevicesView=()=>import('./views/DevicesView.vue'),SettingsView=()=>import('./views/SettingsView.vue');
const routes=[
 {path:'/',component:StorefrontView},{path:'/login',component:LoginView},
 {path:'/app',component:AppShell,meta:{auth:true},children:[
  {path:'',redirect:'/app/dashboard'},{path:'dashboard',component:DashboardView},{path:'pos',component:PosView,meta:{roles:['admin','cashier']}},{path:'inventory',component:InventoryView,meta:{roles:['admin','assistant','cashier']}},{path:'orders',component:OrdersView},{path:'workforce',component:WorkforceView,meta:{roles:['admin','assistant']}},{path:'reports',component:ReportsView,meta:{roles:['admin','assistant']}},{path:'users',component:UsersView,meta:{roles:['admin']}},{path:'devices',component:DevicesView,meta:{roles:['admin']}},{path:'settings',component:SettingsView}
 ]}
];
const router=createRouter({history:createWebHistory(),routes});
router.beforeEach(async to=>{const auth=useAuthStore();if(!auth.ready)await auth.hydrate();if(to.meta.auth&&!auth.authenticated)return '/login';const roles=to.meta.roles;if(roles&&!roles.includes(auth.role))return '/app/dashboard';if(to.path==='/login'&&auth.authenticated)return '/app/dashboard';});
export default router;
