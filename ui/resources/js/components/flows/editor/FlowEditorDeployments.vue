<script setup lang="ts">
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { show as flowDeploymentShow } from '@/routes/flows/deployments';
import { ArrowUpRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flowId: number;
    deploymentCards: DeploymentCard[];
    allDeploymentsUrl?: string | null;
    statusTone: (status?: string | null) => string;
    statusLabel: (status?: string | null) => string;
    runTypeLabel: (type?: FlowRun['type'] | null) => string;
    formatDate: (value?: string | null) => string;
    formatDuration: (start?: string | null, end?: string | null) => string;
}>();

const { t } = useI18n();

const deploymentHref = (deploymentId: number): string => {
    return flowDeploymentShow({
        flow: props.flowId,
        deployment: deploymentId,
    }).url;
};
</script>

<template>
    <section class="space-y-3 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ t('flows.deployments.title') }}
                </h2>
            </div>

            <Link
                v-if="allDeploymentsUrl"
                :href="allDeploymentsUrl"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-700 hover:underline focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                {{ t('flows.deployments.all') }}
                <ArrowUpRight class="size-4" aria-hidden="true" />
            </Link>
        </div>

        <div class="grid divide-y rounded-xl border border-border">
            <Link
                v-for="item in deploymentCards"
                :key="item.deployment.id"
                :href="deploymentHref(item.deployment.id)"
                class="block w-full px-4 py-3 text-left transition-colors hover:bg-muted/40"
            >
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p class="text-sm font-semibold text-foreground">
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

                    <div class="flex-1" />

                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('common.started') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            formatDate(item.deployment.started_at)
                        }}</span>
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('common.finished') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            formatDate(item.deployment.finished_at)
                        }}</span>
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('flows.metrics.duration') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            formatDuration(
                                item.deployment.started_at,
                                item.deployment.finished_at,
                            )
                        }}</span>
                    </Badge>
                    <Badge
                        variant="outline"
                        class="border-border bg-transparent text-muted-foreground"
                    >
                        {{ t('flows.deployments.logs') }}:
                        <span class="ml-1 font-medium text-foreground">{{
                            item.deployment.logs.length
                        }}</span>
                    </Badge>

                    <span
                        class="inline-flex items-center text-muted-foreground"
                    >
                        <ArrowUpRight class="size-4 shrink-0" />
                    </span>
                </div>
            </Link>
        </div>
    </section>
</template>
