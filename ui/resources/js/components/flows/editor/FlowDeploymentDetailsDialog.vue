<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import FlowDiscoveryPanel from '@/components/flows/editor/FlowDiscoveryPanel.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowCodeEditorExpose {
    focusLine: (line: number, flash?: boolean) => boolean;
}

interface DiscoverySelectionTarget {
    id: string;
    type: 'actor' | 'event';
    requestKey: number;
}

const open = defineModel<boolean>('open', { default: false });

defineProps<{
    deploymentCard: DeploymentCard | null;
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
    <Dialog v-model:open="open">
        <DialogContent
            class="grid h-[90vh] max-h-[90vh] grid-rows-[auto_minmax(0,1fr)] overflow-hidden xl:max-w-[1400px]"
        >
            <DialogHeader class="space-y-1 pb-1">
                <DialogTitle
                    v-if="deploymentCard"
                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                >
                    <span class="text-base font-semibold text-foreground">
                        {{
                            t('flows.deploy.label', {
                                id: deploymentCard.deployment.id,
                            })
                        }}
                    </span>
                    <Badge
                        variant="outline"
                        :class="statusTone(deploymentCard.deployment.status)"
                    >
                        {{ statusLabel(deploymentCard.deployment.status) }}
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-muted/50 text-muted-foreground"
                    >
                        {{ runTypeLabel(deploymentCard.deployment.type) }}
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('common.started') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            formatDate(deploymentCard.deployment.started_at)
                        }}</span>
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('common.finished') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            formatDate(deploymentCard.deployment.finished_at)
                        }}</span>
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('flows.metrics.duration') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            formatDuration(
                                deploymentCard.deployment.started_at,
                                deploymentCard.deployment.finished_at,
                            )
                        }}</span>
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('flows.deployments.logs') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            deploymentCard.deployment.logs.length
                        }}</span>
                    </Badge>
                </DialogTitle>
                <DialogTitle v-else>
                    {{ t('flows.deployments.details_title') }}
                </DialogTitle>
            </DialogHeader>

            <div
                v-if="deploymentCard"
                class="grid min-h-0 gap-3 overflow-hidden lg:grid-cols-2 lg:grid-rows-[minmax(0,32vh)_minmax(0,28vh)_minmax(180px,1fr)]"
            >
                <div
                    ref="codeSection"
                    class="relative min-h-0 overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25 lg:col-span-2"
                >
                    <FlowCodeEditor
                        ref="codeEditor"
                        :model-value="
                            deploymentCard.deployment.code || t('common.empty')
                        "
                        :disabled="true"
                        class="h-full min-h-0 text-xs"
                    />
                </div>

                <div ref="overviewSection" class="min-h-0 overflow-hidden">
                    <FlowDiscoveryPanel
                        class="h-full"
                        :graph="deploymentCard.deployment.graph"
                        :webhook-endpoints="deploymentCard.deployment.webhooks ?? []"
                        :selected-target="selectedDiscoveryTarget"
                        @jump-to-code="jumpToCode"
                    />
                </div>

                <FlowGraph
                    class="h-full min-h-0"
                    :graph="deploymentCard.deployment.graph"
                    :meta="deploymentCard.graphMeta"
                    @node-select="openDiscoveryNode"
                />

                <FlowLogsPanel
                    :logs="deploymentCard.deployment.logs"
                    class="h-full min-h-0 lg:col-span-2"
                    :empty-message="t('flows.logs.empty')"
                    compact
                    dense
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
