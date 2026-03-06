<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from 'vue-i18n';

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
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="overflow-hidden xl:max-w-[1400px]">
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

            <div v-if="deploymentCard" class="space-y-4">
                <div
                    class="grid h-[40vh] min-h-[280px] gap-3 lg:grid-cols-[2fr_1fr]"
                >
                    <div
                        class="relative h-full overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
                    >
                        <FlowCodeEditor
                            :model-value="
                                deploymentCard.deployment.code ||
                                t('common.empty')
                            "
                            :disabled="true"
                            class="text-xs"
                        />
                    </div>

                    <FlowGraph
                        class="h-full"
                        :graph="deploymentCard.deployment.graph"
                        :meta="deploymentCard.graphMeta"
                    />
                </div>

                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ t('common.logs') }}</span>
                    <span>{{ deploymentCard.deployment.logs.length }}</span>
                </div>

                <FlowLogsPanel
                    :logs="deploymentCard.deployment.logs"
                    class="max-h-64"
                    :empty-message="t('flows.logs.empty')"
                    compact
                    dense
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
