<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import type {
    DispatchPathHighlight,
    FlowGraphEdgeHighlight,
} from '@/components/flows/graphHighlights';
import {
    parseHiddenGraphNodeIds,
    setHiddenGraphNodeQueryParams,
} from '@/components/flows/graphVisibility';
import { logFlowGraphVisibility } from '@/components/flows/graphVisibilityDebug';
import FlowDiscoveryPanel from '@/components/flows/editor/FlowDiscoveryPanel.vue';
import StackedSidePanelsLayout from '@/components/flows/editor/StackedSidePanelsLayout.vue';
import FlowStoragePanel from '@/components/flows/editor/FlowStoragePanel.vue';
import {
    buildDeploymentGraphMeta,
    createDeploymentDetailsHelpers,
} from '@/components/flows/editor/deploymentDetails';
import {
    createDefaultStackedSidePanelsResizeState,
    readStackedSidePanelsResizeState,
    setStackedSidePanelsResizeQueryParams,
    stackedSidePanelsResizeStatesEqual,
    type StackedSidePanelsResizeState,
} from '@/components/flows/editor/stackedSidePanelsLayout';
import { formatFlowStorageContent } from '@/components/flows/editor/storageContent';
import type { FlowDeployment } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    deployments as flowDeployments,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import { show as flowDeploymentShow } from '@/routes/flows/deployments';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Logs, Workflow } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

type DeploymentDetailsTab = 'info' | 'code' | 'storage' | 'discovery';

interface FlowCodeEditorExpose {
    focusLine: (line: number, flash?: boolean) => boolean;
}

interface DiscoverySelectionTarget {
    id: string;
    type: 'actor' | 'event';
    requestKey: number;
}

interface FlowGraphExpose {
    highlightDispatchPath: (payload: DispatchPathHighlight) => void;
    focusEdge: (payload: FlowGraphEdgeHighlight) => void;
    focusNode: (nodeId: string) => void;
    setHoveredEdgeHighlight: (payload: FlowGraphEdgeHighlight | null) => void;
}

const props = defineProps<{
    flow: {
        id: number;
        name: string;
    };
    deployment: FlowDeployment;
}>();

const { t } = useI18n();

const { formatDate, formatDuration, runTypeLabel, statusLabel, statusTone } =
    createDeploymentDetailsHelpers(t);

const codeEditor = ref<FlowCodeEditorExpose | null>(null);
const flowGraph = ref<FlowGraphExpose | null>(null);
const selectedDiscoveryTarget = ref<DiscoverySelectionTarget | null>(null);
const activeTab = ref<DeploymentDetailsTab>('info');
const graphPanelVisible = ref(true);
const logsPanelVisible = ref(true);
const hiddenNodeIds = ref<string[]>([]);
const sidePanelResizeState = ref<StackedSidePanelsResizeState>(
    createDefaultStackedSidePanelsResizeState(),
);

const deploymentTabs = computed<
    Array<{ value: DeploymentDetailsTab; label: string }>
>(() => [
    { value: 'info', label: t('flows.editor.tabs.info') },
    { value: 'code', label: t('flows.editor.tabs.code') },
    { value: 'storage', label: t('flows.editor.tabs.storage') },
    { value: 'discovery', label: t('flows.editor.tabs.discovery') },
]);

const graphMeta = computed(() => {
    return buildDeploymentGraphMeta(
        props.deployment,
        t,
        statusLabel,
        formatDate,
    );
});

const storageSnapshotJson = computed(() => {
    return formatFlowStorageContent(props.deployment.storage_snapshot, 2);
});

const pageTitle = computed(() => {
    return t('flows.deploy.label', { id: props.deployment.id });
});

const graphToggleTooltipLabel = computed(() => {
    return graphPanelVisible.value
        ? t('flows.editor.actions.close_graph')
        : t('flows.editor.actions.open_graph');
});

const logsToggleTooltipLabel = computed(() => {
    return logsPanelVisible.value
        ? t('flows.editor.actions.close_logs')
        : t('flows.editor.actions.open_logs');
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
    {
        title: t('flows.breadcrumbs.flow', { id: props.flow.id }),
        href: flowShow({ flow: props.flow.id }).url,
    },
    {
        title: t('flows.deployments_page.title'),
        href: flowDeployments({ flow: props.flow.id }).url,
    },
    {
        title: pageTitle.value,
        href: flowDeploymentShow({
            flow: props.flow.id,
            deployment: props.deployment.id,
        }).url,
    },
]);

const buildDeploymentUrl = (
    nextHiddenNodeIds: string[] = hiddenNodeIds.value,
    nextResizeState: StackedSidePanelsResizeState = sidePanelResizeState.value,
): string => {
    const baseUrl = flowDeploymentShow({
        flow: props.flow.id,
        deployment: props.deployment.id,
    }).url;
    const query =
        typeof window === 'undefined'
            ? new URLSearchParams()
            : new URLSearchParams(window.location.search);

    setHiddenGraphNodeQueryParams(query, nextHiddenNodeIds);
    setStackedSidePanelsResizeQueryParams(query, nextResizeState);

    const queryString = query.toString();

    logFlowGraphVisibility('Deployment.buildDeploymentUrl', {
        hiddenNodeIds: [...nextHiddenNodeIds],
        resizeState: nextResizeState,
        queryString,
        baseUrl,
    });

    return queryString.length > 0 ? `${baseUrl}?${queryString}` : baseUrl;
};

const syncBrowserUrl = (replace = false): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const nextUrl = buildDeploymentUrl();
    const currentUrl = `${window.location.pathname}${window.location.search}`;

    logFlowGraphVisibility('Deployment.syncBrowserUrl', {
        replace,
        currentUrl,
        nextUrl,
        hiddenNodeIds: [...hiddenNodeIds.value],
        resizeState: sidePanelResizeState.value,
    });

    if (nextUrl === currentUrl) {
        return;
    }

    const stateMethod = replace ? 'replaceState' : 'pushState';
    window.history[stateMethod](window.history.state, '', nextUrl);
};

const readDeploymentStateFromLocation = (): {
    hiddenNodeIds: string[];
    resizeState: StackedSidePanelsResizeState;
} => {
    if (typeof window === 'undefined') {
        return {
            hiddenNodeIds: [],
            resizeState: createDefaultStackedSidePanelsResizeState(),
        };
    }

    const query = new URLSearchParams(window.location.search);

    const state = {
        hiddenNodeIds: parseHiddenGraphNodeIds(query),
        resizeState: readStackedSidePanelsResizeState(query),
    };

    logFlowGraphVisibility('Deployment.readDeploymentStateFromLocation', {
        search: window.location.search,
        state,
    });

    return state;
};

const jumpToCode = async (line: number): Promise<void> => {
    activeTab.value = 'code';

    await nextTick();

    requestAnimationFrame(() => {
        codeEditor.value?.focusLine(line, true);
    });
};

const openDiscoveryNode = (payload: {
    id: string;
    type: 'actor' | 'event';
}) => {
    selectedDiscoveryTarget.value = {
        ...payload,
        requestKey: Date.now(),
    };
    activeTab.value = 'discovery';
};

const highlightDispatchPath = (payload: DispatchPathHighlight): void => {
    flowGraph.value?.highlightDispatchPath(payload);
};

const setHoveredEdgeHighlight = (
    payload: FlowGraphEdgeHighlight | null,
): void => {
    flowGraph.value?.setHoveredEdgeHighlight(payload);
};

const focusEdgeHighlight = (payload: FlowGraphEdgeHighlight): void => {
    flowGraph.value?.focusEdge(payload);
};

const focusDiscoveryNode = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    flowGraph.value?.focusNode(payload.id);
};

const handleLogNodeSelection = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    openDiscoveryNode(payload);
    focusDiscoveryNode(payload);
};

const setGraphPanelVisible = (visible: boolean): void => {
    if (graphPanelVisible.value === visible) {
        return;
    }

    graphPanelVisible.value = visible;
};

const setLogsPanelVisible = (visible: boolean): void => {
    if (logsPanelVisible.value === visible) {
        return;
    }

    logsPanelVisible.value = visible;
};

const setSidePanelResizeState = (
    nextResizeState: StackedSidePanelsResizeState,
    replace = true,
): void => {
    if (
        stackedSidePanelsResizeStatesEqual(
            sidePanelResizeState.value,
            nextResizeState,
        )
    ) {
        return;
    }

    sidePanelResizeState.value = nextResizeState;
    syncBrowserUrl(replace);
};

const toggleHiddenNodeVisibility = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    const nextHiddenNodeIds = hiddenNodeIds.value.includes(payload.id)
        ? hiddenNodeIds.value.filter((nodeId) => nodeId !== payload.id)
        : [...hiddenNodeIds.value, payload.id];

    logFlowGraphVisibility('Deployment.toggleHiddenNodeVisibility', {
        payload,
        hiddenNodeIdsBefore: [...hiddenNodeIds.value],
        hiddenNodeIdsAfter: nextHiddenNodeIds,
    });

    hiddenNodeIds.value = nextHiddenNodeIds;

    syncBrowserUrl();
};

const handlePopstate = (): void => {
    const nextState = readDeploymentStateFromLocation();

    logFlowGraphVisibility('Deployment.handlePopstate', {
        state: nextState,
    });

    hiddenNodeIds.value = nextState.hiddenNodeIds;
    sidePanelResizeState.value = nextState.resizeState;
};

onMounted(() => {
    const nextState = readDeploymentStateFromLocation();

    logFlowGraphVisibility('Deployment.onMounted', {
        state: nextState,
    });

    hiddenNodeIds.value = nextState.hiddenNodeIds;
    sidePanelResizeState.value = nextState.resizeState;
    syncBrowserUrl(true);
    window.addEventListener('popstate', handlePopstate);
});

onBeforeUnmount(() => {
    if (typeof window === 'undefined') {
        return;
    }

    window.removeEventListener('popstate', handlePopstate);
});
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #header>
            <div
                class="z-0 flex h-12 items-center justify-center gap-3 py-2 lg:absolute lg:top-0 lg:left-0 lg:w-full"
            >
                <div class="w-21" />

                <nav
                    class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/30 p-1"
                    aria-label="Deployment tabs"
                >
                    <button
                        v-for="tab in deploymentTabs"
                        :key="tab.value"
                        type="button"
                        class="rounded-md px-3 py-0.5 text-sm transition"
                        :class="
                            activeTab === tab.value
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeTab = tab.value"
                    >
                        {{ tab.label }}
                    </button>
                </nav>

                <div
                    class="inline-flex w-21 items-center justify-center gap-1.5 rounded-lg border border-border bg-muted/30 p-1"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="graphToggleTooltipLabel"
                                :aria-pressed="graphPanelVisible"
                                :class="
                                    cn(
                                        'h-6 w-8',
                                        graphPanelVisible
                                            ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30 hover:bg-emerald-400/20 hover:text-emerald-400'
                                            : 'text-muted-foreground hover:bg-background/80 hover:text-foreground',
                                    )
                                "
                                @click="setGraphPanelVisible(!graphPanelVisible)"
                            >
                                <Workflow class="size-3" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ graphToggleTooltipLabel }}
                        </TooltipContent>
                    </Tooltip>

                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="logsToggleTooltipLabel"
                                :aria-pressed="logsPanelVisible"
                                :class="
                                    cn(
                                        'h-6 w-8',
                                        logsPanelVisible
                                            ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30 hover:bg-emerald-400/20 hover:text-emerald-400'
                                            : 'text-muted-foreground hover:bg-background/80 hover:text-foreground',
                                    )
                                "
                                @click="setLogsPanelVisible(!logsPanelVisible)"
                            >
                                <Logs class="size-3" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ logsToggleTooltipLabel }}
                        </TooltipContent>
                    </Tooltip>
                </div>
            </div>
        </template>

        <div
            v-if="graphMeta"
            class="flex h-full max-h-[calc(100vh-64px)] flex-1 flex-col overflow-hidden"
        >
            <StackedSidePanelsLayout
                class="h-full"
                :top-active="graphPanelVisible"
                :bottom-active="logsPanelVisible"
                :main-ratio="1.3"
                :side-ratio="1"
                :top-ratio="1"
                :bottom-ratio="1"
                :resizable="true"
                :resize-state="sidePanelResizeState"
                @update:resize-state="setSidePanelResizeState"
            >
                <template #main>
                    <div class="h-full min-h-0 overflow-hidden">
                        <div v-if="activeTab === 'info'" class="h-full">
                            <div
                                class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl bg-linear-to-br from-background via-background to-muted/20"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-4 border-b border-border/80 px-5 py-5"
                                >
                                    <div class="space-y-1.5">
                                        <h2
                                            class="text-xl font-semibold text-foreground"
                                        >
                                            {{
                                                t('flows.deploy.label', {
                                                    id: deployment.id,
                                                })
                                            }}
                                        </h2>
                                        <p class="text-sm text-muted-foreground">
                                            {{ flow.name }}
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant="outline"
                                            :class="statusTone(deployment.status)"
                                        >
                                            {{ statusLabel(deployment.status) }}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            class="border-border bg-muted/50 text-muted-foreground"
                                        >
                                            {{ runTypeLabel(deployment.type) }}
                                        </Badge>
                                    </div>
                                </div>

                                <div class="min-h-0 flex-1 overflow-auto p-5">
                                    <div
                                        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                                    >
                                        <div
                                            class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                        >
                                            <p
                                                class="text-xs tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{
                                                    t(
                                                        'flows.deployments_page.columns.id',
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="mt-2 text-lg font-semibold text-foreground"
                                            >
                                                #{{ deployment.id }}
                                            </p>
                                        </div>

                                    <div
                                        v-if="deployment.container_id"
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{
                                                t(
                                                    'flows.deployments.container_id',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-2 text-sm font-medium break-all text-foreground"
                                        >
                                            {{ deployment.container_id }}
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{ t('common.started') }}
                                        </p>
                                        <p
                                            class="mt-2 text-sm font-medium text-foreground"
                                        >
                                            {{
                                                formatDate(
                                                    deployment.started_at,
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{ t('common.finished') }}
                                        </p>
                                        <p
                                            class="mt-2 text-sm font-medium text-foreground"
                                        >
                                            {{
                                                formatDate(
                                                    deployment.finished_at,
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{ t('flows.metrics.duration') }}
                                        </p>
                                        <p
                                            class="mt-2 text-lg font-semibold text-foreground"
                                        >
                                            {{
                                                formatDuration(
                                                    deployment.started_at,
                                                    deployment.finished_at,
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{ t('flows.deployments.logs') }}
                                        </p>
                                        <p
                                            class="mt-2 text-lg font-semibold text-foreground"
                                        >
                                            {{ deployment.logs.length }}
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{
                                                t(
                                                    'flows.deployments_page.columns.actors',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-2 text-lg font-semibold text-foreground"
                                        >
                                            {{ graphMeta.actors }}
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-xl border border-border/80 bg-background/80 px-4 py-3"
                                    >
                                        <p
                                            class="text-xs tracking-wide text-muted-foreground uppercase"
                                        >
                                            {{
                                                t(
                                                    'flows.deployments_page.columns.events',
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="mt-2 text-lg font-semibold text-foreground"
                                        >
                                            {{ graphMeta.events }}
                                        </p>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else-if="activeTab === 'code'"
                            class="flex h-full flex-col"
                        >
                            <div
                                class="relative h-full overflow-hidden bg-linear-to-br from-background to-muted/25"
                            >
                                <FlowCodeEditor
                                    ref="codeEditor"
                                    id="deployment-code"
                                    :model-value="
                                        deployment.code || t('common.empty')
                                    "
                                    :disabled="true"
                                    class="h-full"
                                    bottom-padding="3rem"
                                />
                            </div>
                        </div>

                        <FlowStoragePanel
                            v-else-if="activeTab === 'storage'"
                            :content="storageSnapshotJson"
                            editor-id="deployment-storage"
                            readonly
                            bottom-padding="3rem"
                        />

                        <div v-else class="h-full">
                            <div
                                class="h-full overflow-hidden rounded-xl bg-muted/15"
                            >
                                <FlowDiscoveryPanel
                                    class="h-full"
                                    :graph="deployment.graph"
                                    :hidden-node-ids="hiddenNodeIds"
                                    :webhook-endpoints="deployment.webhooks ?? []"
                                    :selected-target="selectedDiscoveryTarget"
                                    @jump-to-code="jumpToCode"
                                    @node-select="focusDiscoveryNode"
                                    @toggle-node-visibility="toggleHiddenNodeVisibility"
                                />
                            </div>
                        </div>
                    </div>
                </template>

                <template #top>
                    <FlowGraph
                        ref="flowGraph"
                        class="h-full min-h-0 w-full"
                        :graph="deployment.graph"
                        :hidden-node-ids="hiddenNodeIds"
                        :meta="graphMeta"
                        variant="plain"
                        :webhook-endpoints="deployment.webhooks ?? []"
                        @jump-to-code="jumpToCode"
                        @node-select="openDiscoveryNode"
                        @toggle-node-visibility="toggleHiddenNodeVisibility"
                    />
                </template>

                <template #bottom>
                    <FlowLogsPanel
                        :logs="deployment.logs"
                        :stream-key="deployment.id"
                        class="h-full min-h-0 w-full"
                        :empty-message="t('flows.logs.empty')"
                        variant="plain"
                        compact
                        @dispatch-edge-highlight="highlightDispatchPath"
                        @log-edge-focus="focusEdgeHighlight"
                        @log-edge-hover="setHoveredEdgeHighlight"
                        @select-node="handleLogNodeSelection"
                    />
                </template>
            </StackedSidePanelsLayout>
        </div>
    </AppLayout>
</template>
