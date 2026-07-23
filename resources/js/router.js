import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';
import AppShell from './components/AppShell.vue';
const StorefrontView=()=>import('./views/StorefrontView.vue'),LoginView=()=>import('./views/LoginView.vue'),FaceTerminalView=()=>import('./views/FaceTerminalView.vue'),DashboardView=()=>import('./views/DashboardView.vue'),PosView=()=>import('./views/PosView.vue'),InventoryView=()=>import('./views/InventoryView.vue'),OrdersView=()=>import('./views/OrdersView.vue'),WorkforceView=()=>import('./views/WorkforceView.vue'),ReportsView=()=>import('./views/ReportsView.vue'),UsersView=()=>import('./views/UsersView.vue'),DevicesView=()=>import('./views/DevicesView.vue'),SettingsView=()=>import('./views/SettingsView.vue');
const routes=[
 {path:'/',component:StorefrontView},{path:'/login',component:LoginView},{path:'/face-terminal',component:FaceTerminalView},
 {path:'/app',component:AppShell,meta:{auth:true},children:[
  {path:'',redirect:'/app/dashboard'},{path:'dashboard',component:DashboardView,meta:{roles:['admin','assistant','user']}},{path:'pos',component:PosView,meta:{roles:['admin','cashier']}},{path:'inventory',component:InventoryView,meta:{roles:['admin','assistant']}},{path:'orders',component:OrdersView},{path:'workforce',component:WorkforceView,meta:{roles:['admin','assistant']}},{path:'reports',component:ReportsView,meta:{roles:['admin','assistant']}},{path:'users',component:UsersView,meta:{roles:['admin']}},{path:'devices',component:DevicesView,meta:{roles:['admin']}},{path:'settings',component:SettingsView}
 ]}
];
const router=createRouter({history:createWebHistory(),routes});
router.beforeEach(async to=>{const auth=useAuthStore();if(!auth.ready)await auth.hydrate();if(to.meta.auth&&!auth.authenticated)return '/login';if(auth.authenticated&&auth.user.must_change_password&&to.path!=='/app/settings')return '/app/settings';const roleHome=auth.role==='cashier'?'/app/pos':(auth.role==='user'?'/':'/app/dashboard');const roles=to.meta.roles;if(roles&&!roles.includes(auth.role))return roleHome;if(to.path==='/login'&&auth.authenticated)return auth.user.must_change_password?'/app/settings':roleHome;});
export default router;
