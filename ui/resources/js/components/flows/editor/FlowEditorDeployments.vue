<script setup lang="ts">
import FlowDeploymentDetailsDialog from '@/components/flows/editor/FlowDeploymentDetailsDialog.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    deploymentCards: DeploymentCard[];
    allDeploymentsUrl?: string | null;
    statusTone: (status?: string | null) => string;
    statusLabel: (status?: string | null) => string;
    runTypeLabel: (type?: FlowRun['type'] | null) => string;
    formatDate: (value?: string | null) => string;
    formatDuration: (start?: string | null, end?: string | null) => string;
}>();

const { t } = useI18n();
const detailsOpen = ref(false);
const selectedDeploymentId = ref<number | null>(null);

const selectedDeploymentCard = computed<DeploymentCard | null>(() => {
    if (selectedDeploymentId.value === null) {
        return null;
    }

    return (
        props.deploymentCards.find(
            (item) => item.deployment.id === selectedDeploymentId.value,
        ) ?? null
    );
});

const openDeploymentDetails = (deploymentId: number): void => {
    selectedDeploymentId.value = deploymentId;
    detailsOpen.value = true;
};

watch(detailsOpen, (open) => {
    if (!open) {
        selectedDeploymentId.value = null;
    }
});

watch(
    () => props.deploymentCards,
    (nextDeployments) => {
        const availableDeploymentIds = new Set(
            nextDeployments.map((item) => item.deployment.id),
        );

        if (
            selectedDeploymentId.value !== null &&
            !availableDeploymentIds.has(selectedDeploymentId.value)
        ) {
            detailsOpen.value = false;
            selectedDeploymentId.value = null;
        }
    },
    { immediate: true },
);
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

        <button
            v-for="item in deploymentCards"
            :key="item.deployment.id"
            type="button"
            class="w-full rounded-xl border border-border bg-background p-3 text-left transition-colors hover:bg-muted/40"
            @click="openDeploymentDetails(item.deployment.id)"
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
            </div>
        </button>

        <FlowDeploymentDetailsDialog
            v-model:open="detailsOpen"
            :deployment-card="selectedDeploymentCard"
            :status-tone="statusTone"
            :status-label="statusLabel"
            :run-type-label="runTypeLabel"
            :format-date="formatDate"
            :format-duration="formatDuration"
        />
    </section>
</template>
