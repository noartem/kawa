<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowLog {
    id: number;
    level?: string | null;
    message?: string | null;
    node_key?: string | null;
    created_at: string;
}

const props = withDefaults(
    defineProps<{
        title: string;
        logs: FlowLog[];
        emptyMessage: string;
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const { t } = useI18n();

const formatDate = (value?: string | null) => {
    if (!value) return t('common.empty');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const containerClass = computed(() =>
    props.compact ? 'max-h-56 space-y-1.5' : 'max-h-72 space-y-2',
);
const itemClass = computed(() =>
    props.compact
        ? 'rounded-lg border border-border bg-muted/40 p-2'
        : 'rounded-lg border border-border bg-muted/40 p-3',
);
</script>

<template>
    <div class="space-y-2">
        <div
            class="flex items-center justify-between text-xs text-muted-foreground"
        >
            <span>{{ title }}</span>
            <span>{{ logs.length }}</span>
        </div>
        <div
            v-if="logs.length"
            :class="containerClass"
            class="overflow-y-auto pr-1"
        >
            <div v-for="log in logs" :key="log.id" :class="itemClass">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span class="tracking-wide uppercase">{{
                        log.level ?? t('common.unknown')
                    }}</span>
                    <span>{{ formatDate(log.created_at) }}</span>
                </div>
                <p v-if="log.message" class="mt-2 text-sm text-foreground">
                    {{ log.message }}
                </p>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    {{ t('flows.logs.empty') }}
                </p>
                <p v-if="log.node_key" class="text-xs text-muted-foreground">
                    {{ t('flows.logs.node', { node: log.node_key }) }}
                </p>
            </div>
        </div>
        <div
            v-else
            class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
        >
            {{ emptyMessage }}
        </div>
    </div>
</template>
