import { defineStore } from 'pinia';
import axios from 'axios';
export const useInventoryStore = defineStore('inventory', {
  state:()=>({products:[],lastSync:null,poll:null}),
  actions:{
    async load(params={}){this.products=(await axios.get('/api/products',{params})).data.data;this.lastSync=new Date().toISOString();},
    start(){this.stop();this.poll=setInterval(async()=>{try{const {data}=await axios.get('/api/inventory/changes',{params:{since:this.lastSync}});for(const p of data.products){const i=this.products.findIndex(x=>x.id===p.id);if(i>=0)this.products[i]=p;else this.products.push(p);}this.lastSync=data.server_time;}catch{}},3000);},
    stop(){if(this.poll)clearInterval(this.poll);this.poll=null;}
  }
});
