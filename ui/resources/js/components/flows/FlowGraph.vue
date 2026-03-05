<script setup lang="ts">
import FlowGraphRenderer from '@/components/flows/FlowGraphRenderer.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Expand, RotateCcw, X, ZoomIn, ZoomOut } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface GraphMeta {
    actors?: number;
    events?: number;
    status?: string;
    updatedAt?: string;
    freshnessLabel?: string;
}

interface FlowGraphRendererExpose {
    zoomIn: () => void;
    zoomOut: () => void;
    resetView: () => void;
}

const props = withDefaults(
    defineProps<{
        graph?: Record<string, unknown> | null;
        meta?: GraphMeta | null;
        outdated?: boolean;
    }>(),
    {
        graph: null,
        meta: null,
        outdated: false,
    },
);

const { t } = useI18n();

const fullscreenOpen = ref(false);
const inlineZoom = ref(100);
const modalZoom = ref(100);

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
        actors: props.meta?.actors ?? countGraphNodesByType(props.graph, 'actor'),
        events: props.meta?.events ?? countGraphNodesByType(props.graph, 'event'),
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
};

const closeFullscreen = (): void => {
    fullscreenOpen.value = false;
};

const zoomIn = (target: FlowGraphRendererExpose | null): void => {
    target?.zoomIn();
};

const zoomOut = (target: FlowGraphRendererExpose | null): void => {
    target?.zoomOut();
};

const resetView = (target: FlowGraphRendererExpose | null): void => {
    target?.resetView();
};
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
                    class="min-w-10 px-1 text-center text-[11px] font-medium tabular-nums text-muted-foreground"
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
            class="transition-all h-full"
            :class="props.outdated ? 'opacity-70 grayscale saturate-0' : ''"
        >
            <div v-if="hasGraphData" class="h-full w-full">
                <FlowGraphRenderer
                    ref="inlineRenderer"
                    class="h-full w-full"
                    :graph="props.graph"
                    @zoom-change="inlineZoom = $event"
                />
            </div>

            <div
                v-else
                class="flex w-full aspect-[16/9] items-center justify-center text-sm text-muted-foreground"
            >
                {{ t('common.empty') }}
            </div>
        </div>

        <div
            class="pointer-events-none absolute right-2 bottom-2 flex h-6 max-w-[calc(100%-1rem)] items-center gap-2 overflow-x-auto rounded-md border border-border/80 bg-background/90 px-2 text-[10px] leading-none text-muted-foreground shadow-sm backdrop-blur"
        >
            <span class="whitespace-nowrap">
                {{ t('flows.metrics.actors') }}:
                <span class="font-medium text-foreground">{{ graphMeta.actors }}</span>
            </span>
            <span class="text-border">|</span>
            <span class="whitespace-nowrap">
                {{ t('flows.metrics.events') }}:
                <span class="font-medium text-foreground">{{ graphMeta.events }}</span>
            </span>
            <span class="text-border">|</span>
            <span class="whitespace-nowrap">
                {{ t('common.status') }}:
                <span class="font-medium text-foreground">{{ graphMeta.status }}</span>
            </span>
            <span class="text-border">|</span>
            <span class="whitespace-nowrap">
                {{ graphMeta.freshnessLabel }}:
                <span class="font-medium text-foreground">{{ graphMeta.updatedAt }}</span>
            </span>
        </div>
    </div>

    <Dialog v-model:open="fullscreenOpen">
        <DialogContent
            class="h-[90vh] w-[95vw] max-w-[95vw] overflow-hidden p-0 sm:max-w-[95vw] lg:max-w-[88vw] xl:max-w-[84vw]"
        >
            <DialogTitle class="sr-only">{{ t('flows.graph.fullscreen_title') }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ t('flows.graph.interaction_hint') }}
            </DialogDescription>

            <div class="h-full">
                <div class="relative h-full overflow-hidden">
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
                            class="min-w-10 px-1 text-center text-[11px] font-medium tabular-nums text-muted-foreground"
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
                            @zoom-change="modalZoom = $event"
                        />

                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center text-sm text-muted-foreground"
                        >
                            {{ t('common.empty') }}
                        </div>
                    </div>

                    <div
                        class="pointer-events-none absolute right-2 bottom-2 flex h-6 max-w-[calc(100%-1rem)] items-center gap-2 overflow-x-auto rounded-md border border-border/80 bg-background/90 px-2 text-[10px] leading-none text-muted-foreground shadow-sm backdrop-blur"
                    >
                        <span class="whitespace-nowrap">
                            {{ t('flows.metrics.actors') }}:
                            <span class="font-medium text-foreground">{{ graphMeta.actors }}</span>
                        </span>
                        <span class="text-border">|</span>
                        <span class="whitespace-nowrap">
                            {{ t('flows.metrics.events') }}:
                            <span class="font-medium text-foreground">{{ graphMeta.events }}</span>
                        </span>
                        <span class="text-border">|</span>
                        <span class="whitespace-nowrap">
                            {{ t('common.status') }}:
                            <span class="font-medium text-foreground">{{ graphMeta.status }}</span>
                        </span>
                        <span class="text-border">|</span>
                        <span class="whitespace-nowrap">
                            {{ graphMeta.freshnessLabel }}:
                            <span class="font-medium text-foreground">{{ graphMeta.updatedAt }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
