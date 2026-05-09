<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

const { appearance, updateAppearance } = useAppearance();

const tabs = computed(() => [
    { value: 'light', icon: Sun },
    { value: 'dark', icon: Moon },
    { value: 'system', icon: Monitor },
]);
</script>

<template>
    <div
        class="flex w-min gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
    >
        <button
            v-for="tab in tabs"
            :key="tab.value"
            @click="updateAppearance(tab.value)"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                tab.value === appearance
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            <component :is="tab.icon" class="size-4" />
        </button>
    </div>
</template>
