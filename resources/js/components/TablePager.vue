<script setup>
import { computed, watch } from "vue";

const props = defineProps({
    total: { type: Number, default: 0 },
    page: { type: Number, default: 1 },
    pageSize: { type: Number, default: 5 },
    label: { type: String, default: "records" },
});
const emit = defineEmits(["update:page", "update:pageSize"]);
const sizes = [5, 10, 15, 20, 25, 30];
const pageCount = computed(() => Math.max(1, Math.ceil(props.total / props.pageSize)));
const first = computed(() => (props.total ? (props.page - 1) * props.pageSize + 1 : 0));
const last = computed(() => Math.min(props.total, props.page * props.pageSize));

watch(pageCount, (count) => {
    if (props.page > count) emit("update:page", count);
});

function resize(event) {
    emit("update:pageSize", Number(event.target.value));
    emit("update:page", 1);
}
</script>

<template>
    <footer class="table-pager" aria-label="Table pagination">
        <span>Showing {{ first }}–{{ last }} of {{ total }} {{ label }}</span>
        <div class="pager-controls">
            <label>
                Rows
                <select :value="pageSize" aria-label="Rows per page" @change="resize">
                    <option v-for="size in sizes" :key="size" :value="size">{{ size }}</option>
                </select>
            </label>
            <button class="btn tiny" :disabled="page <= 1" @click="$emit('update:page', page - 1)">Previous</button>
            <strong>Page {{ page }} of {{ pageCount }}</strong>
            <button class="btn tiny" :disabled="page >= pageCount" @click="$emit('update:page', page + 1)">Next</button>
        </div>
    </footer>
</template>
