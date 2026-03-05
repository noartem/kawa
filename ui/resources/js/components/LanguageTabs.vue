<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { currentLocale, supportedLocales, setLocale } = useLocale();

const localeOptions = computed(() =>
    supportedLocales.value.map((locale) => ({
        value: locale,
        label: t(`locales.${locale}`),
    })),
);
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
    >
        <button
            v-for="option in localeOptions"
            :key="option.value"
            type="button"
            @click="setLocale(option.value)"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 text-sm transition-colors',
                currentLocale === option.value
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            <span>{{ option.label }}</span>
        </button>
    </div>
</template>
