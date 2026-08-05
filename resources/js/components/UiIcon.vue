<script setup>
defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 20 },
});
</script>

<template>
    <svg
        class="ui-icon"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <template v-if="name === 'dashboard'">
            <rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/>
        </template>
        <template v-else-if="name === 'pos'">
            <path d="M5 3h14v18H5z"/><path d="M8 7h8M8 11h2M14 11h2M8 15h2M14 15h2"/>
        </template>
        <template v-else-if="name === 'inventory'">
            <path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="m4 7 8 4 8-4M4 12l8 4 8-4M4 17l8 4 8-4"/>
        </template>
        <template v-else-if="name === 'package'">
            <path d="m4 7 8-4 8 4v10l-8 4-8-4V7Z"/><path d="m4 7 8 4 8-4M12 11v10"/>
        </template>
        <template v-else-if="name === 'orders'">
            <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h3"/>
        </template>
        <template v-else-if="name === 'workforce' || name === 'users'">
            <circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3.3 2.4-5 5.5-5s4.9 1.7 5.5 5"/><circle cx="17" cy="9" r="2.2"/><path d="M15.5 14.5c2.8-.5 4.7.9 5 3.5"/>
        </template>
        <template v-else-if="name === 'reports'">
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>
        </template>
        <template v-else-if="name === 'devices'">
            <rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8M12 17v4M7 10h.01M10 10h.01"/>
        </template>
        <template v-else-if="name === 'settings'">
            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1v.1h-4v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4h-.1v-4H3A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1v-.1h4V3a1.7 1.7 0 0 0 1.1 1.6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.14.38.35.72.6 1 .28.28.63.4 1 .4h.1v4H21a1.7 1.7 0 0 0-1.6.6Z"/>
        </template>
        <template v-else-if="name === 'logout'">
            <path d="M10 17l5-5-5-5M15 12H3M14 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"/>
        </template>
        <template v-else-if="name === 'chevron-left'"><path d="m15 18-6-6 6-6"/></template>
        <template v-else-if="name === 'chevron-right'"><path d="m9 18 6-6-6-6"/></template>
        <template v-else-if="name === 'eye'"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></template>
        <template v-else-if="name === 'eye-off'"><path d="m3 3 18 18M10.6 6.2A8.6 8.6 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-2.1 2.8M6.6 6.6C4 8.3 2.5 12 2.5 12s3.5 6 9.5 6a9 9 0 0 0 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/></template>
        <template v-else-if="name === 'profile'"><circle cx="12" cy="8" r="3.5"/><path d="M5 21c.7-4.3 3-6.5 7-6.5s6.3 2.2 7 6.5"/></template>
        <template v-else-if="name === 'status'"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.3 2.3 4.8-5"/></template>
        <template v-else-if="name === 'shield'"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></template>
        <template v-else-if="name === 'clock'"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></template>
        <template v-else-if="name === 'key'"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2 2M14 9l2 2"/></template>
        <template v-else-if="name === 'cloud'"><path d="M17.5 19H6a4 4 0 0 1-.5-8 6.5 6.5 0 0 1 12.4-1.5A4.8 4.8 0 0 1 17.5 19Z"/></template>
        <template v-else-if="name === 'calendar'"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></template>
        <template v-else-if="name === 'lock'"><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></template>
        <template v-else-if="name === 'moon'"><path d="M20 15.5A8 8 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></template>
        <template v-else-if="name === 'sync'"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 8.5A7 7 0 0 1 18.7 7L20 12M4 12l1.3 5a7 7 0 0 0 12.6-1.5"/></template>
        <template v-else-if="name === 'plus'"><path d="M12 5v14M5 12h14"/></template>
        <template v-else-if="name === 'edit'"><path d="M4 20h4l11-11-4-4L4 16v4ZM13.5 6.5l4 4"/></template>
        <template v-else-if="name === 'adjust'"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/></template>
        <template v-else-if="name === 'trash'"><path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6"/></template>
        <template v-else-if="name === 'search'"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></template>
        <template v-else-if="name === 'arrow-right'"><path d="M5 12h14M14 7l5 5-5 5"/></template>
        <template v-else-if="name === 'catalog'"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></template>
        <template v-else-if="name === 'sales'"><path d="m4 16 5-5 4 3 7-8M15 6h5v5"/></template>
        <template v-else-if="name === 'chart'"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></template>
        <template v-else-if="name === 'team'"><circle cx="8" cy="9" r="3"/><circle cx="17" cy="8" r="2"/><path d="M2.5 20c.6-4 2.4-6 5.5-6s4.9 2 5.5 6M14 14c3.6-.7 6 .9 6.5 4"/></template>
        <template v-else-if="name === 'shop'"><path d="M4 10v10h16V10M3 10l2-6h14l2 6"/><path d="M3 10a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2M9 20v-5h6v5"/></template>
        <template v-else><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></template>
    </svg>
</template>
