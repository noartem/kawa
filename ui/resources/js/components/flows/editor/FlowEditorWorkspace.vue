<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowCodeMergeView from '@/components/flows/FlowCodeMergeView.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import type { GraphMeta, HistoryCard } from '@/components/flows/editor/types';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { ChevronDown, History, Play, Square } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const code = defineModel<string>('code', { required: true });

const props = defineProps<{
    canUpdate: boolean;
    canRun: boolean;
    actionInProgress: string | null;
    currentDevelopmentActive: boolean;
    codeUpdatedAt?: string | null;
    codeErrorMessages: string[];
    historyCards: HistoryCard[];
    graph: Record<string, unknown> | null;
    graphMeta: GraphMeta;
    graphIsOutdated: boolean;
    developmentLogs: Array<{
        id: number;
        level?: string | null;
        message?: string | null;
        node_key?: string | null;
        context?: Record<string, unknown> | null;
        created_at: string;
    }>;
    formatRecentDate: (value?: string | null) => string;
    formatDate: (value?: string | null) => string;
}>();

defineEmits<{
    'run-flow': [];
    'stop-flow': [];
}>();

const { t } = useI18n();

const activeTab = ref<'editor' | 'chat' | 'changes'>('editor');
const expandedHistoryIds = ref<Set<number>>(new Set());
const editor = ref<HTMLElement | null>(null);

const isHistoryExpanded = (historyId: number): boolean => {
    return expandedHistoryIds.value.has(historyId);
};

const toggleHistoryCard = (historyId: number): void => {
    const nextExpandedIds = new Set(expandedHistoryIds.value);

    if (nextExpandedIds.has(historyId)) {
        nextExpandedIds.delete(historyId);
    } else {
        nextExpandedIds.add(historyId);
    }

    expandedHistoryIds.value = nextExpandedIds;
};

const focusEditor = (): void => {
    editor.value?.scrollIntoView({ behavior: 'smooth' });
};

watch(
    () => props.historyCards,
    (nextHistoryCards) => {
        const availableHistoryIds = new Set(
            nextHistoryCards.map((historyCard) => historyCard.item.id),
        );
        const nextExpandedIds = new Set(
            [...expandedHistoryIds.value].filter((historyId) =>
                availableHistoryIds.has(historyId),
            ),
        );

        if (nextExpandedIds.size !== expandedHistoryIds.value.size) {
            expandedHistoryIds.value = nextExpandedIds;
        }
    },
    { immediate: true },
);
</script>

<template>
    <section ref="editor" class="h-[100vh] p-4" @click="focusEditor">
        <div
            class="grid h-full gap-2 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] md:grid-rows-[42px_1fr_1fr]"
        >
            <div
                class="col-span-2 row-1 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div
                    class="inline-flex w-full items-center gap-1 rounded-lg border border-border bg-muted/30 p-1 lg:w-auto"
                >
                    <button
                        type="button"
                        class="flex-1 rounded-md px-3 py-1.5 text-sm transition lg:flex-none"
                        :class="
                            activeTab === 'editor'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'editor'"
                    >
                        {{ t('flows.editor.tabs.code') }}
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-md px-3 py-1.5 text-sm transition lg:flex-none"
                        :class="
                            activeTab === 'chat'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'chat'"
                    >
                        {{ t('flows.editor.tabs.chat') }}
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-md px-3 py-1.5 text-sm transition lg:flex-none"
                        :class="
                            activeTab === 'changes'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'changes'"
                    >
                        {{ t('flows.editor.tabs.changes') }}
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="currentDevelopmentActive"
                        variant="outline"
                        :disabled="!canRun || actionInProgress !== null"
                        @click="$emit('stop-flow')"
                    >
                        <Square class="size-4" />
                        {{ t('actions.stop') }}
                    </Button>
                    <Button
                        v-else
                        :disabled="!canRun || actionInProgress !== null"
                        @click="$emit('run-flow')"
                    >
                        <Play class="size-4" />
                        {{ t('actions.start') }}
                    </Button>
                </div>
            </div>

            <div
                class="col-start-1 row-span-2 row-start-2 min-h-0 overflow-y-auto"
            >
                <div v-if="activeTab === 'editor'" class="flex h-full flex-col">
                    <div
                        class="relative h-full overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
                    >
                        <FlowCodeEditor
                            id="flow-code"
                            v-model="code"
                            :disabled="!canUpdate"
                            class="h-full"
                            bottom-padding="3rem"
                        />

                        <div
                            class="pointer-events-none absolute right-3 bottom-3 flex items-center gap-2 rounded-md bg-background/85 px-2 py-1 text-xs text-muted-foreground shadow-sm backdrop-blur-sm"
                        >
                            <span>
                                {{ t('common.updated_at') }}:
                                {{ formatRecentDate(codeUpdatedAt) }}
                            </span>

                            <TooltipProvider
                                v-if="codeErrorMessages.length"
                                :delay-duration="0"
                            >
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span
                                            class="pointer-events-auto inline-flex cursor-help items-center rounded-sm border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 font-medium text-destructive"
                                        >
                                            {{ codeErrorMessages.length }}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        class="max-w-xs space-y-1 text-xs"
                                    >
                                        <p
                                            v-for="(
                                                message, index
                                            ) in codeErrorMessages"
                                            :key="`code-error-${index}`"
                                            class="break-words whitespace-pre-wrap"
                                        >
                                            {{ message }}
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </div>
                </div>

                <div v-else-if="activeTab === 'chat'" class="space-y-3">
                    <div
                        class="rounded-lg border border-dashed border-border bg-muted/20 p-4 text-sm text-muted-foreground"
                    >
                        <p>
                            {{ t('flows.editor.chat.example_question') }}
                        </p>
                        <p class="mt-2">
                            {{ t('flows.editor.chat.example_answer') }}
                        </p>
                    </div>
                </div>

                <template v-else>
                    <div
                        v-if="historyCards.length"
                        class="grid gap-2 overflow-y-auto pr-1"
                    >
                        <article
                            v-for="historyCard in historyCards"
                            :key="historyCard.item.id"
                            class="rounded-lg border border-border bg-muted/15 p-3"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                                role="button"
                                tabindex="0"
                                @click="toggleHistoryCard(historyCard.item.id)"
                                @keydown.enter.prevent="
                                    toggleHistoryCard(historyCard.item.id)
                                "
                                @keydown.space.prevent="
                                    toggleHistoryCard(historyCard.item.id)
                                "
                            >
                                <span class="inline-flex items-center gap-2">
                                    <History class="size-4" />
                                    {{
                                        t('flows.history.version', {
                                            id: historyCard.item.id,
                                        })
                                    }}
                                </span>

                                <div class="flex items-center gap-3">
                                    <span>{{
                                        formatDate(historyCard.item.created_at)
                                    }}</span>

                                    <span
                                        class="font-semibold text-emerald-500"
                                    >
                                        +{{ historyCard.diffChanges.added }}
                                    </span>
                                    <span class="font-semibold text-rose-500">
                                        -{{ historyCard.diffChanges.removed }}
                                    </span>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="h-8 w-8"
                                        :aria-expanded="
                                            isHistoryExpanded(
                                                historyCard.item.id,
                                            )
                                        "
                                        :aria-controls="`history-details-${historyCard.item.id}`"
                                        @click.stop="
                                            toggleHistoryCard(
                                                historyCard.item.id,
                                            )
                                        "
                                    >
                                        <ChevronDown
                                            class="size-4 transition-transform"
                                            :class="
                                                isHistoryExpanded(
                                                    historyCard.item.id,
                                                )
                                                    ? 'rotate-180'
                                                    : ''
                                            "
                                        />
                                        <span class="sr-only"
                                            >Toggle history details</span
                                        >
                                    </Button>
                                </div>
                            </div>

                            <div
                                v-if="isHistoryExpanded(historyCard.item.id)"
                                class="relative mt-2 overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
                            >
                                <FlowCodeMergeView
                                    :id="`history-details-${historyCard.item.id}`"
                                    :original-value="historyCard.originalCode"
                                    :modified-value="historyCard.modifiedCode"
                                    class="h-52 text-xs"
                                />
                            </div>
                        </article>
                    </div>
                    <div
                        v-else
                        class="flex min-h-[420px] flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center"
                    >
                        <History
                            class="mb-3 size-10 text-muted-foreground/70"
                        />
                        <p class="text-sm font-semibold text-foreground">
                            {{ t('flows.editor.changes.empty_title') }}
                        </p>
                        <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                            {{ t('flows.editor.changes.empty_description') }}
                        </p>
                    </div>
                </template>
            </div>

            <FlowGraph
                class="col-start-2 row-start-2 h-full min-h-0"
                :graph="graph"
                :meta="graphMeta"
                :outdated="graphIsOutdated"
            />

            <FlowLogsPanel
                class="col-start-2 row-start-3 h-full min-h-0"
                :logs="developmentLogs"
                :empty-message="t('flows.logs.empty_dev')"
                compact
            />
        </div>
    </section>
</template>
