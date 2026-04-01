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

interface FlowCodeEditorExpose {
    focusLine: (line: number, flash?: boolean) => boolean;
}

interface DiscoverySelectionTarget {
    id: string;
    type: 'actor' | 'event';
    requestKey: number;
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
const selectedDiscoveryTarget = ref<DiscoverySelectionTarget | null>(null);

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

const jumpToCode = async (line: number): Promise<void> => {
    codeSection.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });

    await nextTick();

    requestAnimationFrame(() => {
        codeEditor.value?.focusLine(line, true);
    });
};

const openDiscoveryNode = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    overviewSection.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
    });

    selectedDiscoveryTarget.value = {
        ...payload,
        requestKey: Date.now(),
    };
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

        <div
            v-if="deployment && graphMeta"
            class="grid min-h-0 gap-3 overflow-y-auto px-6 pt-4 pb-6 lg:grid-cols-2"
        >
            <div
                ref="codeSection"
                class="relative overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25 lg:col-span-2"
            >
                <FlowCodeEditor
                    ref="codeEditor"
                    :model-value="deployment.code || t('common.empty')"
                    :disabled="true"
                    class="h-[28rem] text-xs lg:h-[32rem]"
                />
            </div>

            <div
                ref="overviewSection"
                class="h-[28rem] min-h-0 overflow-hidden lg:h-[32rem]"
            >
                <FlowDiscoveryPanel
                    class="h-full"
                    :graph="deployment.graph"
                    :webhook-endpoints="deployment.webhooks ?? []"
                    :selected-target="selectedDiscoveryTarget"
                    @jump-to-code="jumpToCode"
                />
            </div>

            <FlowGraph
                class="h-[28rem] min-h-0 lg:h-[32rem]"
                :graph="deployment.graph"
                :meta="graphMeta"
                @node-select="openDiscoveryNode"
            />

            <FlowLogsPanel
                :logs="deployment.logs"
                class="col-span-2 h-[28rem] min-h-0 lg:h-[32rem]"
                :empty-message="t('flows.logs.empty')"
                compact
                dense
            />
        </div>
    </div>
</template>
