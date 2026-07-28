<template>
    <div class="card">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm text-slate-400">{{ title }}</p>
                <p class="text-2xl font-bold mt-1">{{ value }}</p>
                <p v-if="subtitle" class="text-xs text-slate-500 mt-1">{{ subtitle }}</p>
            </div>
            <span class="text-2xl">{{ icon }}</span>
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: String,
    value: [String, Number],
    subtitle: String,
    icon: { type: String, default: '📊' },
    tone: { type: String, default: 'emerald' },
});
</script>
