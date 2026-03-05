<script setup lang="ts">
import FlowDeploymentDetailsDialog from '@/components/flows/editor/FlowDeploymentDetailsDialog.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/vue3';
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
                <p class="text-sm text-muted-foreground">
                    {{ t('flows.deployments.description') }}
                </p>
            </div>

            <Link
                v-if="allDeploymentsUrl"
                :href="allDeploymentsUrl"
                class="text-sm font-medium text-primary hover:underline"
            >
                {{ t('flows.deployments.all') }}
            </Link>
        </div>

        <button
            v-for="item in deploymentCards"
            :key="item.deployment.id"
            type="button"
            class="w-full rounded-xl border border-border bg-background p-3 text-left transition-colors hover:bg-muted/40"
            @click="openDeploymentDetails(item.deployment.id)"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
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

                <p class="text-xs text-muted-foreground">
                    {{ formatDate(item.deployment.started_at) }}
                </p>
            </div>

            <div
                class="mt-2 grid gap-2 text-xs text-muted-foreground md:grid-cols-3"
            >
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
