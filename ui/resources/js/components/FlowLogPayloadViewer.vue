<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Braces,
    Brackets,
    ChevronsDownUp,
    ChevronsUpDown,
    Dot,
} from 'lucide-vue-next';
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

const payloadSizeLabel = computed(() => {
    const serializedPayload = JSON.stringify(props.payload) ?? 'null';
    const payloadBytes = new TextEncoder().encode(serializedPayload).length;
    const sizeUnits = ['B', 'KB', 'MB', 'GB', 'TB'];

    let sizeValue = payloadBytes;
    let unitIndex = 0;

    while (sizeValue >= 1024 && unitIndex < sizeUnits.length - 1) {
        sizeValue /= 1024;
        unitIndex += 1;
    }

    const formattedSize =
        unitIndex === 0
            ? String(payloadBytes)
            : new Intl.NumberFormat(undefined, {
                  maximumFractionDigits: sizeValue >= 10 ? 0 : 1,
              }).format(sizeValue);

    return `${formattedSize} ${sizeUnits[unitIndex]}`;
});

const canToggleAll = computed(() => {
    return payloadKind.value !== 'primitive' && payloadCount.value > 0;
});

const expandSignal = ref(0);
const collapseSignal = ref(0);
const expandedNodes = ref<Record<string, boolean>>({});

const registerExpandedState = (nodePath: string, expanded: boolean): void => {
    expandedNodes.value = {
        ...expandedNodes.value,
        [nodePath]: expanded,
    };
};

const unregisterExpandedState = (nodePath: string): void => {
    const nextExpandedNodes = { ...expandedNodes.value };

    delete nextExpandedNodes[nodePath];
    expandedNodes.value = nextExpandedNodes;
};

const hasExpandedNodes = computed(() => {
    return Object.values(expandedNodes.value).some(Boolean);
});

const shouldShowCollapseAll = computed(() => {
    return canToggleAll.value && hasExpandedNodes.value;
});

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
            class="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/25 px-3 py-1.5"
        >
            <div class="flex min-w-0 items-center gap-1.5 text-xs">
                <Braces
                    v-if="payloadKind === 'object'"
                    class="size-3.5 shrink-0 text-muted-foreground"
                />
                <Brackets
                    v-else-if="payloadKind === 'array'"
                    class="size-3.5 shrink-0 text-muted-foreground"
                />
                <span class="leading-none font-medium text-foreground">
                    {{ t('flows.logs.payload.title') }}
                </span>
                <div
                    class="flex min-w-0 items-center text-[11px] leading-none text-muted-foreground"
                >
                    <Dot class="size-4 shrink-0" />
                    <span class="truncate">{{ payloadSummary }}</span>
                    <Dot class="size-4 shrink-0" />
                    <span class="shrink-0">{{ payloadSizeLabel }}</span>
                </div>
            </div>

            <div v-if="canToggleAll" class="flex items-center">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-6 gap-1.5 px-2 text-[11px]"
                    @click="shouldShowCollapseAll ? collapseAll() : expandAll()"
                >
                    <ChevronsDownUp
                        v-if="shouldShowCollapseAll"
                        class="size-3.5"
                    />
                    <ChevronsUpDown v-else class="size-3.5" />
                    {{
                        shouldShowCollapseAll
                            ? t('flows.logs.payload.collapse_all')
                            : t('flows.logs.payload.expand_all')
                    }}
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
                node-path="root"
                :register-expanded-state="registerExpandedState"
                :unregister-expanded-state="unregisterExpandedState"
            />
        </div>
    </div>
</template>
