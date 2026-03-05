<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ChevronDown } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    deploymentCards: DeploymentCard[];
    statusTone: (status?: string | null) => string;
    statusLabel: (status?: string | null) => string;
    runTypeLabel: (type?: FlowRun['type'] | null) => string;
    formatDate: (value?: string | null) => string;
    formatDuration: (start?: string | null, end?: string | null) => string;
}>();

const { t } = useI18n();
const expandedDeploymentIds = ref<Set<number>>(new Set());

const isDeploymentExpanded = (deploymentId: number): boolean => {
    return expandedDeploymentIds.value.has(deploymentId);
};

const toggleDeploymentCard = (deploymentId: number): void => {
    const nextExpandedIds = new Set(expandedDeploymentIds.value);

    if (nextExpandedIds.has(deploymentId)) {
        nextExpandedIds.delete(deploymentId);
    } else {
        nextExpandedIds.add(deploymentId);
    }

    expandedDeploymentIds.value = nextExpandedIds;
};

watch(
    () => props.deploymentCards,
    (nextDeployments) => {
        const availableDeploymentIds = new Set(
            nextDeployments.map((item) => item.deployment.id),
        );
        const nextExpandedIds = new Set(
            [...expandedDeploymentIds.value].filter((deploymentId) =>
                availableDeploymentIds.has(deploymentId),
            ),
        );

        if (nextExpandedIds.size !== expandedDeploymentIds.value.size) {
            expandedDeploymentIds.value = nextExpandedIds;
        }
    },
    { immediate: true },
);
</script>

<template>
    <section class="space-y-3 p-4">
        <h2 class="text-lg font-semibold">
            {{ t('flows.deployments.title') }}
        </h2>

        <article
            v-for="item in deploymentCards"
            :key="item.deployment.id"
            class="space-y-3 rounded-xl border border-border bg-background p-3"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold">
                        {{
                            t('flows.deploy.label', {
                                id: item.deployment.id,
                            })
                        }}
                    </p>
                    <Badge
                        variant="outline"
                        :class="statusTone(item.deployment.status)"
                    >
                        {{ statusLabel(item.deployment.status) }}
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-muted/50 text-muted-foreground"
                    >
                        {{ runTypeLabel(item.deployment.type) }}
                    </Badge>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8"
                    :aria-expanded="isDeploymentExpanded(item.deployment.id)"
                    :aria-controls="`deployment-details-${item.deployment.id}`"
                    @click="toggleDeploymentCard(item.deployment.id)"
                >
                    <ChevronDown
                        class="size-4 transition-transform"
                        :class="
                            isDeploymentExpanded(item.deployment.id)
                                ? 'rotate-180'
                                : ''
                        "
                    />
                    <span class="sr-only">Toggle deployment details</span>
                </Button>
            </div>

            <div
                v-if="isDeploymentExpanded(item.deployment.id)"
                :id="`deployment-details-${item.deployment.id}`"
                class="space-y-3"
            >
                <div
                    class="grid gap-3 text-xs text-muted-foreground md:grid-cols-4"
                >
                    <p>
                        {{ t('common.started') }}:
                        <span class="font-medium text-foreground">{{
                            formatDate(item.deployment.started_at)
                        }}</span>
                    </p>
                    <p>
                        {{ t('common.finished') }}:
                        <span class="font-medium text-foreground">{{
                            formatDate(item.deployment.finished_at)
                        }}</span>
                    </p>
                    <p>
                        {{ t('flows.metrics.duration') }}:
                        <span class="font-medium text-foreground">{{
                            formatDuration(
                                item.deployment.started_at,
                                item.deployment.finished_at,
                            )
                        }}</span>
                    </p>
                    <p>
                        {{ t('flows.deployments.logs') }}:
                        <span class="font-medium text-foreground">{{
                            item.deployment.logs.length
                        }}</span>
                    </p>
                </div>

                <div
                    class="grid h-[30vh] min-h-[240px] gap-3 lg:grid-cols-[2fr_1fr]"
                >
                    <div
                        class="relative h-full overflow-hidden rounded-xl border border-border bg-linear-to-br from-background to-muted/25"
                    >
                        <FlowCodeEditor
                            :model-value="
                                item.deployment.code || t('common.empty')
                            "
                            :disabled="true"
                            class="text-xs"
                        />
                    </div>

                    <FlowGraph
                        class="h-full"
                        :graph="item.deployment.graph"
                        :meta="item.graphMeta"
                    />
                </div>

                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ t('common.logs') }}</span>
                    <span>{{ item.deployment.logs.length }}</span>
                </div>

                <FlowLogsPanel
                    :logs="item.deployment.logs"
                    class="max-h-56"
                    :empty-message="t('flows.logs.empty')"
                    compact
                    dense
                />
            </div>
        </article>
    </section>
</template>
