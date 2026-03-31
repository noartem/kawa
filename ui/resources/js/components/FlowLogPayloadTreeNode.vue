<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

defineOptions({
    name: 'FlowLogPayloadTreeNode',
});

const props = withDefaults(
    defineProps<{
        value: unknown;
        label?: string;
        depth?: number;
        isLast?: boolean;
        initiallyExpanded?: boolean;
        expandSignal?: number;
        collapseSignal?: number;
        nodePath?: string;
        registerExpandedState?: (nodePath: string, expanded: boolean) => void;
        unregisterExpandedState?: (nodePath: string) => void;
    }>(),
    {
        label: undefined,
        depth: 0,
        isLast: true,
        initiallyExpanded: false,
        expandSignal: 0,
        collapseSignal: 0,
        nodePath: 'root',
        registerExpandedState: undefined,
        unregisterExpandedState: undefined,
    },
);

const { t } = useI18n();

const isRecord = (value: unknown): value is Record<string, unknown> => {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
};

const nodeKind = computed<
    'object' | 'array' | 'string' | 'number' | 'boolean' | 'null' | 'unknown'
>(() => {
    if (Array.isArray(props.value)) {
        return 'array';
    }

    if (isRecord(props.value)) {
        return 'object';
    }

    if (props.value === null) {
        return 'null';
    }

    if (typeof props.value === 'string') {
        return 'string';
    }

    if (typeof props.value === 'number') {
        return 'number';
    }

    if (typeof props.value === 'boolean') {
        return 'boolean';
    }

    return 'unknown';
});

const childEntries = computed(() => {
    if (Array.isArray(props.value)) {
        return props.value.map((value) => ({ value }));
    }

    if (isRecord(props.value)) {
        return Object.entries(props.value).map(([label, value]) => ({
            label,
            value,
        }));
    }

    return [] as Array<{ label?: string; value: unknown }>;
});

const childCount = computed(() => {
    return childEntries.value.length;
});

const isCollapsible = computed(() => {
    return nodeKind.value === 'object' || nodeKind.value === 'array';
});

const openingToken = computed(() => {
    return nodeKind.value === 'array' ? '[' : '{';
});

const closingToken = computed(() => {
    return nodeKind.value === 'array' ? ']' : '}';
});

const summarizeInlineValue = (value: unknown, depth = 0): string => {
    if (typeof value === 'string') {
        return JSON.stringify(
            value.length > 48 ? `${value.slice(0, 45)}...` : value,
        );
    }

    if (
        typeof value === 'number' ||
        typeof value === 'boolean' ||
        value === null
    ) {
        return JSON.stringify(value);
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return '[]';
        }

        if (depth > 0) {
            return `[${value.length}]`;
        }

        return `[${value
            .slice(0, 2)
            .map((item) => summarizeInlineValue(item, depth + 1))
            .join(', ')}${value.length > 2 ? ', ...' : ''}]`;
    }

    if (isRecord(value)) {
        const entries = Object.entries(value);

        if (entries.length === 0) {
            return '{}';
        }

        if (depth > 0) {
            return `{${entries.length}}`;
        }

        return `{ ${entries
            .slice(0, 2)
            .map(
                ([key, entryValue]) =>
                    `${JSON.stringify(key)}: ${summarizeInlineValue(entryValue, depth + 1)}`,
            )
            .join(', ')}${entries.length > 2 ? ', ...' : ''} }`;
    }

    return String(value);
};

const collapsedSummary = computed(() => {
    if (nodeKind.value === 'array') {
        if (childCount.value === 0) {
            return t('flows.logs.payload.empty_array');
        }

        return `${t('flows.logs.payload.summary_array', {
            count: childCount.value,
        })} ${summarizeInlineValue(props.value)}`;
    }

    if (nodeKind.value === 'object') {
        if (childCount.value === 0) {
            return t('flows.logs.payload.empty_object');
        }

        return `${t('flows.logs.payload.summary_object', {
            count: childCount.value,
        })} ${summarizeInlineValue(props.value)}`;
    }

    return null;
});

const primitiveText = computed(() => {
    switch (nodeKind.value) {
        case 'string':
            return JSON.stringify(props.value);
        case 'number':
        case 'boolean':
        case 'null':
            return JSON.stringify(props.value);
        default:
            return String(props.value);
    }
});

const primitiveClass = computed(() => {
    switch (nodeKind.value) {
        case 'string':
            return 'break-all text-emerald-700 dark:text-emerald-300';
        case 'number':
            return 'text-blue-700 dark:text-blue-300';
        case 'boolean':
            return 'text-amber-700 dark:text-amber-300';
        case 'null':
            return 'text-rose-700 dark:text-rose-300';
        default:
            return 'break-all text-muted-foreground';
    }
});

const labelText = computed(() => {
    return props.label === undefined ? null : JSON.stringify(props.label);
});

const isExpanded = ref(props.initiallyExpanded);

const shouldTrackExpandedState = computed(() => {
    return isCollapsible.value && childCount.value > 0;
});

const syncExpandedState = (): void => {
    if (!props.registerExpandedState || !shouldTrackExpandedState.value) {
        return;
    }

    props.registerExpandedState(props.nodePath, isExpanded.value);
};

watch(
    [shouldTrackExpandedState, isExpanded],
    (nextValues, previousValues) => {
        if (!props.registerExpandedState || !props.unregisterExpandedState) {
            return;
        }

        const [nextShouldTrack] = nextValues;
        const previousShouldTrack = previousValues?.[0] ?? false;

        if (!nextShouldTrack) {
            if (previousShouldTrack) {
                props.unregisterExpandedState(props.nodePath);
            }

            return;
        }

        syncExpandedState();
    },
    { immediate: true },
);

watch(
    () => props.expandSignal,
    () => {
        if (isCollapsible.value && childCount.value > 0) {
            isExpanded.value = true;
        }
    },
);

watch(
    () => props.collapseSignal,
    () => {
        if (isCollapsible.value && childCount.value > 0) {
            isExpanded.value = false;
        }
    },
);

watch(
    () => props.initiallyExpanded,
    (nextValue) => {
        if (isCollapsible.value && childCount.value > 0) {
            isExpanded.value = nextValue;
        }
    },
);

onBeforeUnmount(() => {
    if (!props.unregisterExpandedState || !shouldTrackExpandedState.value) {
        return;
    }

    props.unregisterExpandedState(props.nodePath);
});
</script>

<template>
    <div class="font-mono text-[12px] leading-6">
        <div class="flex items-start gap-1.5">
            <button
                v-if="isCollapsible && childCount > 0"
                type="button"
                class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-sm text-muted-foreground transition hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:outline-none"
                :aria-label="
                    isExpanded
                        ? t('flows.logs.payload.collapse_node')
                        : t('flows.logs.payload.expand_node')
                "
                @click="isExpanded = !isExpanded"
            >
                <ChevronRight
                    class="size-3.5 transition-transform"
                    :class="isExpanded ? 'rotate-90' : ''"
                />
            </button>
            <span v-else class="inline-block size-4 shrink-0" />

            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 flex-wrap items-start gap-x-1">
                    <span
                        v-if="labelText !== null"
                        class="break-all text-sky-700 dark:text-sky-300"
                    >
                        {{ labelText }}
                    </span>
                    <span
                        v-if="labelText !== null"
                        class="text-muted-foreground"
                    >
                        :
                    </span>

                    <template v-if="isCollapsible">
                        <span class="text-foreground">{{ openingToken }}</span>

                        <template v-if="childCount === 0">
                            <span class="text-foreground">{{
                                closingToken
                            }}</span>
                        </template>
                        <template v-else-if="!isExpanded">
                            <span class="break-all text-muted-foreground">
                                {{ collapsedSummary }}
                            </span>
                            <span class="text-foreground">{{
                                closingToken
                            }}</span>
                        </template>
                    </template>

                    <template v-else>
                        <span :class="primitiveClass">{{ primitiveText }}</span>
                    </template>

                    <span v-if="!isLast" class="text-muted-foreground">,</span>
                </div>
            </div>
        </div>

        <div
            v-if="isCollapsible && childCount > 0 && isExpanded"
            class="pl-[1.375rem]"
        >
            <div class="ml-2 border-l border-border/60 pl-3">
                <FlowLogPayloadTreeNode
                    v-for="(entry, index) in childEntries"
                    :key="`${props.depth}-${props.label ?? 'root'}-${entry.label ?? index}`"
                    :value="entry.value"
                    :label="entry.label"
                    :depth="props.depth + 1"
                    :is-last="index === childEntries.length - 1"
                    :initially-expanded="props.depth < 1"
                    :expand-signal="expandSignal"
                    :collapse-signal="collapseSignal"
                    :node-path="`${props.nodePath}.${entry.label ?? index}`"
                    :register-expanded-state="props.registerExpandedState"
                    :unregister-expanded-state="props.unregisterExpandedState"
                />
            </div>

            <div class="text-foreground">
                {{ closingToken
                }}<span v-if="!isLast" class="text-muted-foreground">,</span>
            </div>
        </div>
    </div>
</template>
