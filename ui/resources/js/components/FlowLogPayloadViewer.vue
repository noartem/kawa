<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Braces, Brackets } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import FlowLogPayloadTreeNode from '@/components/FlowLogPayloadTreeNode.vue';

const props = defineProps<{
    payload: unknown;
}>();

const { t } = useI18n();

const isRecord = (value: unknown): value is Record<string, unknown> => {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
};

const payloadKind = computed<'object' | 'array' | 'primitive'>(() => {
    if (Array.isArray(props.payload)) {
        return 'array';
    }

    if (isRecord(props.payload)) {
        return 'object';
    }

    return 'primitive';
});

const payloadCount = computed(() => {
    if (Array.isArray(props.payload)) {
        return props.payload.length;
    }

    if (isRecord(props.payload)) {
        return Object.keys(props.payload).length;
    }

    return 0;
});

const payloadSummary = computed(() => {
    if (payloadKind.value === 'array') {
        return payloadCount.value === 0
            ? t('flows.logs.payload.empty_array')
            : t('flows.logs.payload.summary_array', {
                  count: payloadCount.value,
              });
    }

    if (payloadKind.value === 'object') {
        return payloadCount.value === 0
            ? t('flows.logs.payload.empty_object')
            : t('flows.logs.payload.summary_object', {
                  count: payloadCount.value,
              });
    }

    return t('flows.logs.payload.primitive_value');
});

const canToggleAll = computed(() => {
    return payloadKind.value !== 'primitive' && payloadCount.value > 0;
});

const expandSignal = ref(0);
const collapseSignal = ref(0);

const expandAll = (): void => {
    expandSignal.value += 1;
};

const collapseAll = (): void => {
    collapseSignal.value += 1;
};
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border border-border/70 bg-popover text-popover-foreground shadow-sm"
    >
        <div
            class="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/30 px-3 py-2"
        >
            <div class="flex min-w-0 items-center gap-2 text-sm">
                <Braces
                    v-if="payloadKind === 'object'"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <Brackets
                    v-else-if="payloadKind === 'array'"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <span class="font-medium text-foreground">
                    {{ t('flows.logs.payload.title') }}
                </span>
                <span class="truncate text-xs text-muted-foreground">
                    {{ payloadSummary }}
                </span>
            </div>

            <div v-if="canToggleAll" class="flex items-center gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    @click="expandAll"
                >
                    {{ t('flows.logs.payload.expand_all') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    @click="collapseAll"
                >
                    {{ t('flows.logs.payload.collapse_all') }}
                </Button>
            </div>
        </div>

        <div
            class="max-h-[26rem] overflow-auto bg-[linear-gradient(to_bottom,rgba(148,163,184,0.04),transparent)] px-3 py-2"
        >
            <FlowLogPayloadTreeNode
                :value="payload"
                :initially-expanded="true"
                :expand-signal="expandSignal"
                :collapse-signal="collapseSignal"
            />
        </div>
    </div>
</template>
