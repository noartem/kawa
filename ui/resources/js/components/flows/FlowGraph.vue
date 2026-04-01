<script setup lang="ts">
import FlowGraphRenderer from '@/components/flows/FlowGraphRenderer.vue';
import FlowDiscoveryPanel from '@/components/flows/editor/FlowDiscoveryPanel.vue';
import type { FlowWebhookEndpoint } from '@/components/flows/editor/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Expand,
    RotateCcw,
    Workflow,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-vue-next';
import {
    flushPendingDispatchPathHighlight,
    propagateDispatchPathHighlight,
    type DispatchHighlightTarget,
    type DispatchPathHighlight,
} from './graphHighlights';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface GraphNodePayload {
    id: string;
    type: 'event' | 'actor' | 'other';
}

interface DiscoverySelectionTarget {
    id: string;
    type: 'event' | 'actor';
    requestKey: number;
}

interface GraphMeta {
    actors?: number;
    events?: number;
    status?: string;
    updatedAt?: string;
    freshnessLabel?: string;
}

interface FlowGraphRendererExpose extends DispatchHighlightTarget {
    zoomIn: () => void;
    zoomOut: () => void;
    resetView: () => void;
}

const props = withDefaults(
    defineProps<{
        graph?: Record<string, unknown> | null;
        meta?: GraphMeta | null;
        webhookEndpoints?: FlowWebhookEndpoint[];
        outdated?: boolean;
    }>(),
    {
        graph: null,
        meta: null,
        webhookEndpoints: () => [],
        outdated: false,
    },
);

const emit = defineEmits<{
    (
        event: 'node-select',
        payload: { id: string; type: 'event' | 'actor' },
    ): void;
    (event: 'jump-to-code', line: number): void;
}>();

const { t } = useI18n();

const fullscreenOpen = ref(false);
const fullscreenSelectedTarget = ref<DiscoverySelectionTarget | null>(null);
const inlineZoom = ref(100);
const modalZoom = ref(100);
const pendingModalDispatchHighlight = ref<DispatchPathHighlight | null>(null);

const inlineRenderer = ref<FlowGraphRendererExpose | null>(null);
const modalRenderer = ref<FlowGraphRendererExpose | null>(null);

const countGraphNodesByType = (
    graph: Record<string, unknown> | null | undefined,
    expectedType: 'actor' | 'event',
): number => {
    const rawNodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    let count = 0;

    for (const rawNode of rawNodes) {
        if (!rawNode || typeof rawNode !== 'object') {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const nodeType =
            typeof node.type === 'string' ? node.type.toLowerCase() : null;

        if (nodeType === expectedType) {
            count += 1;
        }
    }

    return count;
};

const hasGraphData = computed(() => {
    const rawNodes = Array.isArray(props.graph?.nodes) ? props.graph.nodes : [];

    return rawNodes.length > 0;
});

const graphMeta = computed(() => {
    return {
        actors:
            props.meta?.actors ?? countGraphNodesByType(props.graph, 'actor'),
        events:
            props.meta?.events ?? countGraphNodesByType(props.graph, 'event'),
        status: props.meta?.status ?? t('common.empty'),
        freshnessLabel:
            props.meta?.freshnessLabel ??
            (props.outdated ? t('common.outdated') : t('common.updated_at')),
        updatedAt: props.meta?.updatedAt ?? t('common.empty'),
    };
});

const formatZoom = (zoomPercent: number): string => {
    return `${Math.round(zoomPercent)}%`;
};

const openFullscreen = async (): Promise<void> => {
    fullscreenOpen.value = true;
    await nextTick();
    modalRenderer.value?.resetView();
    pendingModalDispatchHighlight.value = flushPendingDispatchPathHighlight(
        modalRenderer.value,
        pendingModalDispatchHighlight.value,
    );
};

const closeFullscreen = (): void => {
    fullscreenOpen.value = false;
};

watch(fullscreenOpen, (isOpen) => {
    if (isOpen) {
        return;
    }

    fullscreenSelectedTarget.value = null;
});

watch(
    () => props.graph,
    () => {
        pendingModalDispatchHighlight.value = null;
    },
    { deep: true },
);

const zoomIn = (target: FlowGraphRendererExpose | null): void => {
    target?.zoomIn();
};

const zoomOut = (target: FlowGraphRendererExpose | null): void => {
    target?.zoomOut();
};

const resetView = (target: FlowGraphRendererExpose | null): void => {
    target?.resetView();
};

const highlightDispatchPath = (payload: DispatchPathHighlight): void => {
    pendingModalDispatchHighlight.value = payload;

    propagateDispatchPathHighlight(
        [inlineRenderer.value, modalRenderer.value],
        payload,
    );

    pendingModalDispatchHighlight.value = modalRenderer.value ? null : payload;
};

watch(modalRenderer, (renderer) => {
    pendingModalDispatchHighlight.value = flushPendingDispatchPathHighlight(
        renderer,
        pendingModalDispatchHighlight.value,
    );
});

const selectInlineNode = (node: GraphNodePayload): void => {
    if (node.type !== 'actor' && node.type !== 'event') {
        return;
    }

    emit('node-select', {
        id: node.id,
        type: node.type,
    });
};

const selectModalNode = (node: GraphNodePayload): void => {
    if (node.type !== 'actor' && node.type !== 'event') {
        return;
    }

    fullscreenSelectedTarget.value = {
        id: node.id,
        type: node.type,
        requestKey: Date.now(),
    };
};

const handleModalJumpToCode = async (line: number): Promise<void> => {
    closeFullscreen();

    await nextTick();

    emit('jump-to-code', line);
};

defineExpose({
    highlightDispatchPath,
});
</script>

<template>
    <div
        v-bind="$attrs"
        class="relative overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
    >
        <div class="absolute top-2 right-2 z-10 flex items-center gap-1">
            <div
                class="pointer-events-auto flex items-center gap-1 rounded-md border border-border/80 bg-background/90 p-1 shadow-sm backdrop-blur"
            >
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    :title="t('flows.graph.zoom_out')"
                    :aria-label="t('flows.graph.zoom_out')"
                    @click="zoomOut(inlineRenderer)"
                >
                    <ZoomOut class="size-4" />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    :title="t('flows.graph.zoom_in')"
                    :aria-label="t('flows.graph.zoom_in')"
                    @click="zoomIn(inlineRenderer)"
                >
                    <ZoomIn class="size-4" />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    :title="t('flows.graph.reset_view')"
                    :aria-label="t('flows.graph.reset_view')"
                    @click="resetView(inlineRenderer)"
                >
                    <RotateCcw class="size-4" />
                </Button>
                <span
                    class="min-w-10 px-1 text-center text-[11px] font-medium text-muted-foreground tabular-nums"
                >
                    {{ formatZoom(inlineZoom) }}
                </span>
                <span class="mx-0.5 h-5 w-px bg-border/70" />
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    :title="t('flows.graph.open_fullscreen')"
                    :aria-label="t('flows.graph.open_fullscreen')"
                    @click="openFullscreen"
                >
                    <Expand class="size-4" />
                </Button>
            </div>
        </div>

        <div
            class="h-full transition-all"
            :class="props.outdated ? 'opacity-70 grayscale saturate-0' : ''"
        >
            <div v-if="hasGraphData" class="h-full w-full">
                <FlowGraphRenderer
                    ref="inlineRenderer"
                    class="h-full w-full"
                    :graph="props.graph"
                    @node-click="selectInlineNode"
                    @zoom-change="inlineZoom = $event"
                />
            </div>

            <div
                v-else
                class="flex aspect-[16/9] w-full items-center justify-center p-4"
            >
                <div
                    class="flex h-full w-full flex-col items-center justify-center text-center"
                >
                    <Workflow class="mb-4 size-10 text-muted-foreground/70" />
                    <p class="text-sm font-semibold text-foreground">
                        {{ t('flows.graph.empty_title') }}
                    </p>
                    <p class="mt-2 max-w-md text-sm text-muted-foreground">
                        {{ t('flows.graph.empty_description') }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="pointer-events-none absolute right-2 bottom-2 flex h-6 max-w-[calc(100%-1rem)] items-center gap-2 overflow-x-auto rounded-md border border-border/80 bg-background/90 px-2 text-[10px] leading-none text-muted-foreground shadow-sm backdrop-blur"
        >
            <span class="whitespace-nowrap">
                {{ t('flows.metrics.actors') }}:
                <span class="font-medium text-foreground">{{
                    graphMeta.actors
                }}</span>
            </span>
            <span class="text-border">|</span>
            <span class="whitespace-nowrap">
                {{ t('flows.metrics.events') }}:
                <span class="font-medium text-foreground">{{
                    graphMeta.events
                }}</span>
            </span>
            <span class="text-border">|</span>
            <span class="whitespace-nowrap">
                {{ t('common.status') }}:
                <span class="font-medium text-foreground">{{
                    graphMeta.status
                }}</span>
            </span>
            <span class="text-border">|</span>
            <span class="whitespace-nowrap">
                {{ graphMeta.freshnessLabel }}:
                <span class="font-medium text-foreground">{{
                    graphMeta.updatedAt
                }}</span>
            </span>
        </div>
    </div>

    <Dialog v-model:open="fullscreenOpen">
        <DialogContent
            class="h-[90vh] w-[95vw] max-w-[95vw] overflow-hidden p-0 sm:max-w-[95vw] lg:max-w-[88vw] xl:max-w-[84vw]"
        >
            <DialogTitle class="sr-only">{{
                t('flows.graph.fullscreen_title')
            }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ t('flows.graph.interaction_hint') }}
            </DialogDescription>

            <div
                class="grid h-full min-h-0 grid-rows-[minmax(0,1fr)_minmax(18rem,40vh)] lg:grid-cols-[minmax(0,1fr)_24rem] lg:grid-rows-1"
            >
                <div class="relative min-h-0 overflow-hidden">
                    <div
                        class="absolute top-2 right-2 z-10 flex items-center gap-1 rounded-md border border-border/80 bg-background/90 p-1 shadow-sm backdrop-blur"
                    >
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="h-7 w-7"
                            :title="t('flows.graph.zoom_out')"
                            :aria-label="t('flows.graph.zoom_out')"
                            @click="zoomOut(modalRenderer)"
                        >
                            <ZoomOut class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="h-7 w-7"
                            :title="t('flows.graph.zoom_in')"
                            :aria-label="t('flows.graph.zoom_in')"
                            @click="zoomIn(modalRenderer)"
                        >
                            <ZoomIn class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="h-7 w-7"
                            :title="t('flows.graph.reset_view')"
                            :aria-label="t('flows.graph.reset_view')"
                            @click="resetView(modalRenderer)"
                        >
                            <RotateCcw class="size-4" />
                        </Button>
                        <span
                            class="min-w-10 px-1 text-center text-[11px] font-medium text-muted-foreground tabular-nums"
                        >
                            {{ formatZoom(modalZoom) }}
                        </span>
                        <span class="mx-0.5 h-5 w-px bg-border/70" />
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="h-7 w-7"
                            :title="t('actions.close')"
                            :aria-label="t('actions.close')"
                            @click="closeFullscreen"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>

                    <div
                        class="h-full transition-all"
                        :class="
                            props.outdated
                                ? 'opacity-70 grayscale saturate-0'
                                : ''
                        "
                    >
                        <FlowGraphRenderer
                            v-if="hasGraphData"
                            ref="modalRenderer"
                            class="h-full w-full"
                            :graph="props.graph"
                            @node-click="selectModalNode"
                            @zoom-change="modalZoom = $event"
                        />

                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center p-6"
                        >
                            <div
                                class="flex h-full w-full max-w-2xl flex-col items-center justify-center rounded-xl border border-dashed border-border bg-muted/20 px-6 text-center"
                            >
                                <Workflow
                                    class="mb-4 size-10 text-muted-foreground/70"
                                />
                                <p
                                    class="text-sm font-semibold text-foreground"
                                >
                                    {{ t('flows.graph.empty_title') }}
                                </p>
                                <p
                                    class="mt-2 max-w-md text-sm text-muted-foreground"
                                >
                                    {{ t('flows.graph.empty_description') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="pointer-events-none absolute right-2 bottom-2 flex h-6 max-w-[calc(100%-1rem)] items-center gap-2 overflow-x-auto rounded-md border border-border/80 bg-background/90 px-2 text-[10px] leading-none text-muted-foreground shadow-sm backdrop-blur"
                    >
                        <span class="whitespace-nowrap">
                            {{ t('flows.metrics.actors') }}:
                            <span class="font-medium text-foreground">{{
                                graphMeta.actors
                            }}</span>
                        </span>
                        <span class="text-border">|</span>
                        <span class="whitespace-nowrap">
                            {{ t('flows.metrics.events') }}:
                            <span class="font-medium text-foreground">{{
                                graphMeta.events
                            }}</span>
                        </span>
                        <span class="text-border">|</span>
                        <span class="whitespace-nowrap">
                            {{ t('common.status') }}:
                            <span class="font-medium text-foreground">{{
                                graphMeta.status
                            }}</span>
                        </span>
                        <span class="text-border">|</span>
                        <span class="whitespace-nowrap">
                            {{ graphMeta.freshnessLabel }}:
                            <span class="font-medium text-foreground">{{
                                graphMeta.updatedAt
                            }}</span>
                        </span>
                    </div>
                </div>

                <div
                    class="min-h-0 border-t border-border bg-background/80 lg:border-t-0 lg:border-l"
                >
                    <div
                        class="flex h-full min-h-0 flex-col gap-3 p-4 pt-12 lg:pt-4"
                    >
                        <div
                            class="px-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('flows.editor.tabs.discovery') }}
                        </div>

                        <FlowDiscoveryPanel
                            class="h-full min-h-0"
                            :graph="props.graph"
                            :webhook-endpoints="props.webhookEndpoints"
                            :selected-target="fullscreenSelectedTarget"
                            :outdated="props.outdated"
                            @jump-to-code="handleModalJumpToCode"
                        />
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
