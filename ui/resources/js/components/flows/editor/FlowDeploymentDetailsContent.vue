<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import FlowDiscoveryPanel from '@/components/flows/editor/FlowDiscoveryPanel.vue';
import { buildDeploymentGraphMeta } from '@/components/flows/editor/deploymentDetails';
import type { FlowDeployment, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

type DeploymentDetailsTab = 'code' | 'storage' | 'discovery';

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

const props = defineProps<{
    deployment: FlowDeployment | null;
    statusTone: (status?: string | null) => string;
    statusLabel: (status?: string | null) => string;
    runTypeLabel: (type?: FlowRun['type'] | null) => string;
    formatDate: (value?: string | null) => string;
    formatDuration: (start?: string | null, end?: string | null) => string;
}>();

const { t } = useI18n();

const codeSection = ref<HTMLElement | null>(null);
const overviewSection = ref<HTMLElement | null>(null);
const codeEditor = ref<FlowCodeEditorExpose | null>(null);
const flowGraph = ref<FlowGraphExpose | null>(null);
const selectedDiscoveryTarget = ref<DiscoverySelectionTarget | null>(null);
const activeTab = ref<DeploymentDetailsTab>('code');

const deploymentTabs = computed<
    Array<{ value: DeploymentDetailsTab; label: string }>
>(() => [
    { value: 'code', label: t('flows.editor.tabs.code') },
    { value: 'storage', label: t('flows.editor.tabs.storage') },
    { value: 'discovery', label: t('flows.editor.tabs.discovery') },
]);

const graphMeta = computed(() => {
    if (!props.deployment) {
        return null;
    }

    return buildDeploymentGraphMeta(
        props.deployment,
        t,
        props.statusLabel,
        props.formatDate,
    );
});

const storageSnapshotJson = computed(() => {
    const snapshot = props.deployment?.storage_snapshot ?? null;

    if (snapshot === null) {
        return JSON.stringify({}, null, 2);
    }

    return JSON.stringify(snapshot, null, 2);
});

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
}): void => {
    selectedDiscoveryTarget.value = {
        ...payload,
        requestKey: Date.now(),
    };
    activeTab.value = 'discovery';
};

const highlightDispatchPath = (payload: DispatchPathHighlight): void => {
    flowGraph.value?.highlightDispatchPath(payload);
};
</script>

<template>
    <div class="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-0 divide-y">
        <div class="space-y-1 px-6 pt-6 pb-3">
            <div
                v-if="deployment"
                class="flex flex-wrap items-center gap-x-2 gap-y-1"
            >
                <h1 class="text-base font-semibold text-foreground">
                    {{ t('flows.deploy.label', { id: deployment.id }) }}
                </h1>
                <Badge variant="outline" :class="statusTone(deployment.status)">
                    {{ statusLabel(deployment.status) }}
                </Badge>
                <Badge
                    variant="outline"
                    class="border-border bg-muted/50 text-muted-foreground"
                >
                    {{ runTypeLabel(deployment.type) }}
                </Badge>
                <Badge
                    variant="outline"
                    class="border-border bg-transparent text-muted-foreground"
                >
                    {{ t('common.started') }}:
                    <span class="ml-1 font-medium text-foreground">{{
                        formatDate(deployment.started_at)
                    }}</span>
                </Badge>
                <Badge
                    variant="outline"
                    class="border-border bg-transparent text-muted-foreground"
                >
                    {{ t('common.finished') }}:
                    <span class="ml-1 font-medium text-foreground">{{
                        formatDate(deployment.finished_at)
                    }}</span>
                </Badge>
                <Badge
                    variant="outline"
                    class="border-border bg-transparent text-muted-foreground"
                >
                    {{ t('flows.metrics.duration') }}:
                    <span class="ml-1 font-medium text-foreground">{{
                        formatDuration(
                            deployment.started_at,
                            deployment.finished_at,
                        )
                    }}</span>
                </Badge>
                <Badge
                    variant="outline"
                    class="border-border bg-transparent text-muted-foreground"
                >
                    {{ t('flows.deployments.logs') }}:
                    <span class="ml-1 font-medium text-foreground">{{
                        deployment.logs.length
                    }}</span>
                </Badge>
            </div>

            <h1 v-else class="text-base font-semibold text-foreground">
                {{ t('flows.deployments.details_title') }}
            </h1>
        </div>

        <section v-if="deployment && graphMeta" class="h-[100vh] p-4">
            <div
                class="grid h-full gap-2 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] md:grid-rows-[42px_minmax(16rem,2fr)_minmax(24rem,3fr)]"
            >
                <div class="flex flex-wrap items-center gap-3 md:col-span-2">
                    <nav
                        class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/30 p-1"
                        aria-label="Deployment tabs"
                    >
                        <button
                            v-for="tab in deploymentTabs"
                            :key="tab.value"
                            type="button"
                            class="rounded-md px-3 py-1.5 text-sm transition"
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

                    <Badge variant="outline">
                        {{ runTypeLabel(deployment.type) }}
                    </Badge>
                </div>

                <div
                    class="min-h-0 overflow-hidden md:col-start-1 md:row-span-2 md:row-start-2"
                >
                    <div v-if="activeTab === 'code'" class="flex h-full flex-col">
                        <div
                            ref="codeSection"
                            class="relative h-full overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
                        >
                            <FlowCodeEditor
                                ref="codeEditor"
                                id="deployment-code"
                                :model-value="deployment.code || t('common.empty')"
                                :disabled="true"
                                class="h-full"
                                bottom-padding="3rem"
                            />
                        </div>
                    </div>

                    <div v-else-if="activeTab === 'storage'" class="flex h-full flex-col">
                        <div
                            class="flex items-center justify-between gap-3 rounded-t-xl border border-b-0 border-border bg-muted/20 px-4 py-3"
                        >
                            <div>
                                <p class="text-sm font-semibold text-foreground">
                                    {{ t('flows.editor.storage.title') }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ t('flows.editor.storage.description') }}
                                </p>
                            </div>

                            <Badge variant="outline">
                                {{ runTypeLabel(deployment.type) }}
                            </Badge>
                        </div>

                        <div
                            class="min-h-0 flex-1 overflow-hidden rounded-b-xl border border-border bg-linear-to-br from-background to-muted/25"
                        >
                            <FlowCodeEditor
                                id="deployment-storage"
                                :model-value="storageSnapshotJson"
                                language="json"
                                :disabled="true"
                                class="h-full"
                                bottom-padding="3rem"
                            />
                        </div>

                        <div
                            class="mt-3 rounded-xl border border-border bg-muted/15 px-4 py-3 text-sm text-muted-foreground"
                        >
                            {{ t('flows.editor.storage.help') }}
                        </div>
                    </div>

                    <div v-else ref="overviewSection" class="h-full">
                        <div
                            class="h-full overflow-hidden rounded-xl border border-border bg-muted/15"
                        >
                            <FlowDiscoveryPanel
                                class="h-full"
                                :graph="deployment.graph"
                                :webhook-endpoints="deployment.webhooks ?? []"
                                :selected-target="selectedDiscoveryTarget"
                                @jump-to-code="jumpToCode"
                            />
                        </div>
                    </div>
                </div>

                <FlowGraph
                    ref="flowGraph"
                    class="h-full min-h-0 md:col-start-2 md:row-start-2"
                    :graph="deployment.graph"
                    :meta="graphMeta"
                    :webhook-endpoints="deployment.webhooks ?? []"
                    @jump-to-code="jumpToCode"
                    @node-select="openDiscoveryNode"
                />

                <FlowLogsPanel
                    :logs="deployment.logs"
                    :stream-key="deployment.id"
                    class="h-full min-h-0 md:col-start-2 md:row-start-3"
                    :empty-message="t('flows.logs.empty')"
                    compact
                    @dispatch-edge-highlight="highlightDispatchPath"
                    @select-node="openDiscoveryNode"
                />
            </div>
        </section>
    </div>
</template>
