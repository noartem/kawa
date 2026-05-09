<script setup lang="ts">
import FlowCodeMergeView from '@/components/flows/FlowCodeMergeView.vue';
import {
    getHistoryAccordionValue,
    retainExpandedHistoryValues,
} from '@/components/flows/editor/historyAccordion';
import type { FlowHistory, HistoryCard } from '@/components/flows/editor/types';
import {
    Accordion,
    AccordionContent,
    AccordionHeader,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { cn } from '@/lib/utils';
import { ChevronDown, History } from 'lucide-vue-next';
import { computed, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        history: FlowHistory[];
        currentCode?: string;
    }>(),
    {
        currentCode: '',
    },
);

const { t } = useI18n();

const expandedHistoryValues = ref<string[]>([]);

const buildHistorySnapshotSignature = (history: FlowHistory[]): string => {
    return history
        .map((historyItem) => `${historyItem.id}:${historyItem.created_at}`)
        .join('|');
};

const stableHistory = shallowRef<FlowHistory[]>(props.history);
const stableHistorySignature = ref(
    buildHistorySnapshotSignature(props.history),
);

const formatDate = (value?: string | null): string => {
    if (!value) {
        return t('common.empty');
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString();
};

const countHistoryDiffChanges = (
    diff?: string | null,
): { added: number; removed: number } => {
    if (!diff) {
        return { added: 0, removed: 0 };
    }

    let added = 0;
    let removed = 0;

    for (const line of diff.split('\n')) {
        if (
            line.startsWith('+++') ||
            line.startsWith('---') ||
            line.startsWith('@@')
        ) {
            continue;
        }

        if (line.startsWith('+')) {
            added += 1;
            continue;
        }

        if (line.startsWith('-')) {
            removed += 1;
        }
    }

    return { added, removed };
};

const historyCards = computed<HistoryCard[]>(() => {
    return stableHistory.value.map((item, index) => {
        const previousVersion = stableHistory.value[index - 1];
        const originalCode = item.code ?? '';
        const modifiedCode =
            index === 0 ? props.currentCode : (previousVersion?.code ?? '');

        return {
            item,
            diffChanges: countHistoryDiffChanges(item.diff),
            originalCode,
            modifiedCode,
        };
    });
});

watch(
    () => props.history,
    (nextHistory) => {
        const nextSignature = buildHistorySnapshotSignature(nextHistory);

        if (nextSignature === stableHistorySignature.value) {
            return;
        }

        stableHistorySignature.value = nextSignature;
        stableHistory.value = nextHistory;
    },
    { deep: true },
);

watch(
    historyCards,
    (nextHistoryCards) => {
        const nextExpandedValues = retainExpandedHistoryValues(
            nextHistoryCards.map((historyCard) => historyCard.item.id),
            expandedHistoryValues.value,
        );

        if (
            nextExpandedValues.length !== expandedHistoryValues.value.length ||
            nextExpandedValues.some(
                (value, index) => expandedHistoryValues.value[index] !== value,
            )
        ) {
            expandedHistoryValues.value = nextExpandedValues;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Accordion
        v-if="historyCards.length"
        v-model:model-value="expandedHistoryValues"
        type="multiple"
        :unmount-on-hide="false"
        class="flex max-h-full flex-col overflow-y-auto overscroll-contain bg-muted/15"
    >
        <AccordionItem
            v-for="(historyCard, i) in historyCards"
            :key="historyCard.item.id"
            :value="getHistoryAccordionValue(historyCard.item.id)"
            v-slot="{ open }"
        >
            <article>
                <AccordionHeader
                    :class="
                        cn(
                            'sticky top-0 z-50 border-b border-border bg-background/95 text-xs text-muted-foreground backdrop-blur-sm transition-[border-color,background-color] duration-200',
                            open ? 'bg-background' : '',
                        )
                    "
                >
                    <AccordionTrigger as-child>
                        <button
                            type="button"
                            class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-2 text-left transition outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2"
                        >
                            <span class="inline-flex items-center gap-2">
                                <History class="size-4" />
                                {{
                                    t('flows.history.version', {
                                        id: historyCard.item.id,
                                    })
                                }}
                            </span>

                            <span class="flex items-center gap-3">
                                <span>{{
                                    formatDate(historyCard.item.created_at)
                                }}</span>

                                <span class="font-semibold text-emerald-500">
                                    +{{ historyCard.diffChanges.added }}
                                </span>
                                <span class="font-semibold text-rose-500">
                                    -{{ historyCard.diffChanges.removed }}
                                </span>

                                <span
                                    class="inline-flex size-8 items-center justify-center"
                                >
                                    <ChevronDown
                                        class="size-4 transition-transform"
                                        :class="open ? 'rotate-180' : ''"
                                    />
                                </span>
                                <span class="sr-only"
                                    >Toggle history details</span
                                >
                            </span>
                        </button>
                    </AccordionTrigger>
                </AccordionHeader>

                <AccordionContent
                    class="relative overflow-hidden bg-linear-to-br from-background to-muted/25"
                >
                    <div
                        :class="
                            cn(
                                'transition-[opacity,transform] duration-200 ease-out',
                                open
                                    ? 'translate-y-0 opacity-100'
                                    : 'pointer-events-none -translate-y-1 opacity-0',
                                open && i < historyCards.length - 1
                                    ? 'border-b'
                                    : '',
                            )
                        "
                    >
                        <FlowCodeMergeView
                            :id="`history-details-${historyCard.item.id}`"
                            :original-value="historyCard.originalCode"
                            :modified-value="historyCard.modifiedCode"
                            class="h-52 text-xs"
                        />
                    </div>
                </AccordionContent>
            </article>
        </AccordionItem>
    </Accordion>

    <div
        v-else
        class="flex h-full flex-col items-center justify-center bg-muted/20 px-6 text-center"
    >
        <History class="mb-3 size-10 text-muted-foreground/70" />
        <p class="text-sm font-semibold text-foreground">
            {{ t('flows.editor.changes.empty_title') }}
        </p>
        <p class="mt-1 h-10 max-w-sm text-sm text-muted-foreground">
            {{ t('flows.editor.changes.empty_description') }}
        </p>
    </div>
</template>
