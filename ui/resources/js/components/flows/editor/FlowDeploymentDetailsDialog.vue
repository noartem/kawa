<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
        <DialogContent
            class="max-h-[90vh] w-[95vw] max-w-[1200px] overflow-y-auto"
        >
            <DialogTitle class="sr-only">
                {{ t('flows.deployments.details_title') }}
            </DialogTitle>
            <DialogDescription class="sr-only">
                {{ t('flows.deployments.description') }}
            </DialogDescription>

            <div v-if="deploymentCard" class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-base font-semibold">
                        {{
                            t('flows.deploy.label', {
                                id: deploymentCard.deployment.id,
                            })
                        }}
                    </p>
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
                </div>

                <div
                    class="grid gap-3 text-xs text-muted-foreground md:grid-cols-4"
                >
                    <p>
                        {{ t('common.started') }}:
                        <span class="font-medium text-foreground">{{
                            formatDate(deploymentCard.deployment.started_at)
                        }}</span>
                    </p>
                    <p>
                        {{ t('common.finished') }}:
                        <span class="font-medium text-foreground">{{
                            formatDate(deploymentCard.deployment.finished_at)
                        }}</span>
                    </p>
                    <p>
                        {{ t('flows.metrics.duration') }}:
                        <span class="font-medium text-foreground">{{
                            formatDuration(
                                deploymentCard.deployment.started_at,
                                deploymentCard.deployment.finished_at,
                            )
                        }}</span>
                    </p>
                    <p>
                        {{ t('flows.deployments.logs') }}:
                        <span class="font-medium text-foreground">{{
                            deploymentCard.deployment.logs.length
                        }}</span>
                    </p>
                </div>

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
