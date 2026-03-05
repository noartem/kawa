<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
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
        logs: FlowLog[];
        emptyMessage: string;
        compact?: boolean;
        dense?: boolean;
    }>(),
    {
        compact: false,
        dense: false,
    },
);

const { t } = useI18n();

const logsContainerRef = ref<HTMLElement | null>(null);
const scrollBottomThreshold = 12;

const displayLogs = computed(() => [...props.logs].reverse());

const formatDate = (value?: string | null) => {
    if (!value) return t('common.empty');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const itemPaddingClass = computed(() => props.dense ? 'p-1.5' : props.compact ? 'p-2' : 'p-3');

const messageClass = computed(() =>
    props.dense
        ? 'mt-1 text-xs leading-relaxed text-foreground'
        : 'mt-2 text-sm text-foreground',
);

const emptyMessageClass = computed(() =>
    props.dense
        ? 'mt-1 text-xs leading-relaxed text-muted-foreground'
        : 'mt-2 text-sm text-muted-foreground',
);

const isScrolledToBottom = (): boolean => {
    const container = logsContainerRef.value;

    if (!container) {
        return true;
    }

    const distanceToBottom =
        container.scrollHeight - container.scrollTop - container.clientHeight;

    return distanceToBottom <= scrollBottomThreshold;
};

const scrollToBottom = (): void => {
    const container = logsContainerRef.value;

    if (!container) {
        return;
    }

    container.scrollTop = container.scrollHeight;
};

onMounted(async () => {
    await nextTick();
    scrollToBottom();
});

watch(
    () => ({
        length: props.logs.length,
        newestLogId: props.logs[0]?.id ?? null,
    }),
    async (state, previousState) => {
        const shouldStickToBottom = isScrolledToBottom();
        const previousLength = previousState?.length ?? 0;
        const hadLogsBeforeUpdate = previousLength > 0;
        const hasIncomingLogs =
            state.length > previousLength
            || (hadLogsBeforeUpdate
                && state.newestLogId !== previousState?.newestLogId);

        await nextTick();

        if (!hadLogsBeforeUpdate && state.length > 0) {
            scrollToBottom();
            return;
        }

        if (hasIncomingLogs && shouldStickToBottom) {
            scrollToBottom();
        }
    },
);
</script>

<template>
    <div
        v-if="logs.length"
        ref="logsContainerRef"
        class="overflow-y-auto rounded-md border divide-y bg-muted/40"
    >
        <div
            v-for="log in displayLogs"
            :key="log.id"
            :class="itemPaddingClass"
        >
            <div
                class="flex items-center justify-between text-xs text-muted-foreground"
            >
                <span class="tracking-wide uppercase">{{
                    log.level ?? t('common.unknown')
                }}</span>
                <span>{{ formatDate(log.created_at) }}</span>
            </div>
            <p v-if="log.message" :class="messageClass">
                {{ log.message }}
            </p>
            <p v-else :class="emptyMessageClass">
                {{ t('flows.logs.empty') }}
            </p>
            <p v-if="log.node_key" class="text-xs text-muted-foreground">
                {{ t('flows.logs.node', { node: log.node_key }) }}
            </p>
        </div>
    </div>
    <div
        v-else
        class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground flex items-center justify-center"
    >
        {{ emptyMessage }}
    </div>
</template>
