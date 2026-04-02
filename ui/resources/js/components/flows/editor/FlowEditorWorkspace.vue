<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowCodeMergeView from '@/components/flows/FlowCodeMergeView.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import FlowDiscoveryPanel from '@/components/flows/editor/FlowDiscoveryPanel.vue';
import FlowEditorChatPanel from '@/components/flows/editor/FlowEditorChatPanel.vue';
import type {
    FlowChatConversation,
    FlowChatMessage,
    FlowWebhookEndpoint,
    GraphMeta,
    HistoryCard,
} from '@/components/flows/editor/types';
import {
    getHistoryAccordionValue,
    retainExpandedHistoryValues,
} from '@/components/flows/editor/historyAccordion';
import {
    Accordion,
    AccordionContent,
    AccordionHeader,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    AlertCircle,
    ChevronDown,
    History,
    Play,
    Square,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowCodeEditorExpose {
    focusLine: (line: number, flash?: boolean) => boolean;
}

interface DiscoverySelectionTarget {
    id: string;
    type: 'actor' | 'event';
    requestKey: number;
}

interface DispatchPathHighlight {
    actor: string;
    event: string;
    triggerEvent: string | null;
}

interface FlowGraphExpose {
    highlightDispatchPath: (payload: DispatchPathHighlight) => void;
}

const code = defineModel<string>('code', { required: true });
const chatDraft = defineModel<string>('chatDraft', { required: true });

const props = defineProps<{
    canUpdate: boolean;
    canRun: boolean;
    actionInProgress: string | null;
    chatPending: boolean;
    currentDevelopmentActive: boolean;
    currentDevelopmentStatus?: string | null;
    statusTone: (status?: string | null) => string;
    statusLabel: (status?: string | null) => string;
    codeUpdatedAt?: string | null;
    codeErrorMessages: string[];
    historyCards: HistoryCard[];
    activeChat: FlowChatConversation | null;
    chatMessages: FlowChatMessage[];
    graph: Record<string, unknown> | null;
    webhookEndpoints: FlowWebhookEndpoint[];
    graphMeta: GraphMeta;
    graphIsOutdated: boolean;
    logStreamKey?: string | number | null;
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
    'send-chat-message': [];
    'retry-chat-message': [];
    'new-chat': [];
    'compact-chat': [];
    'apply-proposal': [message: FlowChatMessage];
    'apply-and-save-proposal': [message: FlowChatMessage];
}>();

const { t } = useI18n();

const isStatusTransitioning = computed(() => {
    return (
        props.actionInProgress === 'run' || props.actionInProgress === 'stop'
    );
});

const showStatusChip = computed(() => {
    const visibleStatuses = new Set([
        'creating',
        'created',
        'running',
        'stopping',
        'error',
        'failed',
        'lock_failed',
    ]);

    return (
        props.currentDevelopmentActive ||
        isStatusTransitioning.value ||
        visibleStatuses.has(props.currentDevelopmentStatus ?? '')
    );
});

const statusChipStatus = computed<string>(() => {
    if (props.actionInProgress === 'run') {
        return 'creating';
    }

    if (props.actionInProgress === 'stop') {
        return 'stopping';
    }

    if (props.currentDevelopmentStatus) {
        return props.currentDevelopmentStatus;
    }

    return props.currentDevelopmentActive ? 'running' : 'unknown';
});

const statusChipLabel = computed(() => {
    return props.statusLabel(statusChipStatus.value);
});

const statusChipIcon = computed(() => {
    switch (statusChipStatus.value) {
        case 'error':
        case 'failed':
        case 'lock_failed':
            return AlertCircle;
        case 'running':
        case 'ready':
        case 'locked':
            return Play;
        case 'stopped':
        case 'success':
            return Square;
        default:
            return Spinner;
    }
});

const activeTab = ref<'editor' | 'chat' | 'discovery' | 'changes'>('editor');
const expandedHistoryValues = ref<string[]>([]);
const workspaceSection = ref<HTMLElement | null>(null);
const codeEditor = ref<FlowCodeEditorExpose | null>(null);
const flowGraph = ref<FlowGraphExpose | null>(null);
const selectedDiscoveryTarget = ref<DiscoverySelectionTarget | null>(null);

let suppressWorkspaceScroll = false;
let restoreWorkspaceScrollTimer: ReturnType<typeof setTimeout> | null = null;

const clearWorkspaceScrollSuppression = (): void => {
    suppressWorkspaceScroll = false;

    if (restoreWorkspaceScrollTimer === null) {
        return;
    }

    clearTimeout(restoreWorkspaceScrollTimer);
    restoreWorkspaceScrollTimer = null;
};

const suppressNextWorkspaceScroll = (): void => {
    suppressWorkspaceScroll = true;

    if (restoreWorkspaceScrollTimer !== null) {
        clearTimeout(restoreWorkspaceScrollTimer);
    }

    restoreWorkspaceScrollTimer = setTimeout(() => {
        clearWorkspaceScrollSuppression();
    }, 80);
};

const focusEditor = (): void => {
    if (suppressWorkspaceScroll) {
        return;
    }

    workspaceSection.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
};

const jumpToCode = async (line: number): Promise<void> => {
    suppressNextWorkspaceScroll();
    activeTab.value = 'editor';
    await nextTick();

    requestAnimationFrame(() => {
        codeEditor.value?.focusLine(line, true);
    });
};

const openDiscoveryNode = async (payload: {
    id: string;
    type: 'actor' | 'event';
}): Promise<void> => {
    suppressNextWorkspaceScroll();

    selectedDiscoveryTarget.value = {
        ...payload,
        requestKey: Date.now(),
    };
    activeTab.value = 'discovery';
    await nextTick();
};

const highlightDispatchPath = (payload: DispatchPathHighlight): void => {
    flowGraph.value?.highlightDispatchPath(payload);
};

watch(
    () => props.historyCards,
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
    <section ref="workspaceSection" class="h-[100vh] p-4" @click="focusEditor">
        <div
            class="grid h-full gap-2 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] md:grid-rows-[42px_minmax(16rem,2fr)_minmax(24rem,3fr)]"
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
                            activeTab === 'discovery'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = 'discovery'"
                    >
                        {{ t('flows.editor.tabs.discovery') }}
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
                    <Badge
                        v-if="showStatusChip"
                        variant="outline"
                        :class="[
                            'inline-flex items-center gap-1.5',
                            statusTone(statusChipStatus),
                        ]"
                    >
                        <Spinner
                            v-if="isStatusTransitioning"
                            class="size-3.5"
                        />
                        <component
                            :is="statusChipIcon"
                            v-else
                            class="size-3.5"
                        />
                        {{ statusChipLabel }}
                    </Badge>

                    <Button
                        v-if="currentDevelopmentActive"
                        variant="outline"
                        :disabled="
                            !canRun || actionInProgress !== null || chatPending
                        "
                        @click="$emit('stop-flow')"
                    >
                        <Square class="size-4" />
                        {{ t('actions.stop') }}
                    </Button>
                    <Button
                        v-else
                        :disabled="
                            !canRun || actionInProgress !== null || chatPending
                        "
                        @click="$emit('run-flow')"
                    >
                        <Play class="size-4" />
                        {{ t('actions.start') }}
                    </Button>
                </div>
            </div>

            <div
                class="col-start-1 row-span-2 row-start-2 min-h-0 overflow-hidden"
            >
                <div v-if="activeTab === 'editor'" class="flex h-full flex-col">
                    <div
                        class="relative h-full overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
                    >
                        <FlowCodeEditor
                            ref="codeEditor"
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

                <div v-else-if="activeTab === 'chat'" class="h-full">
                    <FlowEditorChatPanel
                        v-model:draft="chatDraft"
                        :chat="activeChat"
                        :messages="chatMessages"
                        :can-update="canUpdate"
                        :pending="chatPending"
                        :current-code="code"
                        :format-recent-date="formatRecentDate"
                        @send="$emit('send-chat-message')"
                        @retry="$emit('retry-chat-message')"
                        @new-chat="$emit('new-chat')"
                        @compact="$emit('compact-chat')"
                        @apply-proposal="$emit('apply-proposal', $event)"
                        @apply-and-save-proposal="
                            $emit('apply-and-save-proposal', $event)
                        "
                    />
                </div>

                <div v-else-if="activeTab === 'discovery'" class="h-full">
                    <div
                        class="h-full overflow-hidden rounded-xl border border-border bg-muted/15"
                    >
                        <FlowDiscoveryPanel
                            :graph="graph"
                            :webhook-endpoints="webhookEndpoints"
                            :outdated="graphIsOutdated"
                            :selected-target="selectedDiscoveryTarget"
                            @jump-to-code="jumpToCode"
                        />
                    </div>
                </div>

                <template v-else>
                    <Accordion
                        v-if="historyCards.length"
                        v-model:model-value="expandedHistoryValues"
                        type="multiple"
                        :unmount-on-hide="false"
                        class="flex max-h-full flex-col overflow-y-auto overscroll-contain rounded-lg border border-border bg-muted/15 divide-y divide-border"
                    >
                        <AccordionItem
                            v-for="historyCard in historyCards"
                            :key="historyCard.item.id"
                            :value="getHistoryAccordionValue(historyCard.item.id)"
                            v-slot="{ open }"
                        >
                            <article>
                                <AccordionHeader
                                    :class="
                                        cn(
                                            'sticky top-0 z-50 bg-background/95 text-xs text-muted-foreground backdrop-blur-sm transition-[border-color,background-color] duration-200',
                                            open
                                                ? 'border-b border-border bg-background'
                                                : '',
                                        )
                                    "
                                >
                                    <AccordionTrigger as-child>
                                        <button
                                            type="button"
                                            class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-2 text-left outline-none transition focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2"
                                        >
                                            <span
                                                class="inline-flex items-center gap-2"
                                            >
                                                <History class="size-4" />
                                                {{
                                                    t('flows.history.version', {
                                                        id: historyCard.item.id,
                                                    })
                                                }}
                                            </span>

                                            <span class="flex items-center gap-3">
                                                <span>{{
                                                    formatDate(
                                                        historyCard.item.created_at,
                                                    )
                                                }}</span>

                                                <span
                                                    class="font-semibold text-emerald-500"
                                                >
                                                    +{{
                                                        historyCard.diffChanges
                                                            .added
                                                    }}
                                                </span>
                                                <span
                                                    class="font-semibold text-rose-500"
                                                >
                                                    -{{
                                                        historyCard.diffChanges
                                                            .removed
                                                    }}
                                                </span>

                                                <span
                                                    class="inline-flex size-8 items-center justify-center"
                                                >
                                                    <ChevronDown
                                                        class="size-4 transition-transform"
                                                        :class="
                                                            open
                                                                ? 'rotate-180'
                                                                : ''
                                                        "
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
                                        class="transition-[opacity,transform] duration-200 ease-out"
                                        :class="
                                            open
                                                ? 'translate-y-0 opacity-100'
                                                : 'pointer-events-none -translate-y-1 opacity-0'
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
                        class="flex h-full flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center"
                    >
                        <History
                            class="mb-3 size-10 text-muted-foreground/70"
                        />
                        <p class="text-sm font-semibold text-foreground">
                            {{ t('flows.editor.changes.empty_title') }}
                        </p>
                        <p
                            class="mt-1 h-10 max-w-sm text-sm text-muted-foreground"
                        >
                            {{ t('flows.editor.changes.empty_description') }}
                        </p>
                    </div>
                </template>
            </div>

            <FlowGraph
                ref="flowGraph"
                class="col-start-2 row-start-2 h-full min-h-0"
                :graph="graph"
                :meta="graphMeta"
                :webhook-endpoints="webhookEndpoints"
                :outdated="graphIsOutdated"
                @jump-to-code="jumpToCode"
                @node-select="openDiscoveryNode"
            />

            <FlowLogsPanel
                class="col-start-2 row-start-3 h-full min-h-0"
                :logs="developmentLogs"
                :stream-key="logStreamKey"
                :empty-message="t('flows.logs.empty_dev')"
                compact
                @dispatch-edge-highlight="highlightDispatchPath"
                @select-node="openDiscoveryNode"
            />
        </div>
    </section>
</template>
