<script setup lang="ts">
import FlowDeploymentDetailsContent from '@/components/flows/editor/FlowDeploymentDetailsContent.vue';
import { createDeploymentDetailsHelpers } from '@/components/flows/editor/deploymentDetails';
import type { FlowDeployment } from '@/components/flows/editor/types';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    deployments as flowDeployments,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import { show as flowDeploymentShow } from '@/routes/flows/deployments';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

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

const pageTitle = computed(() => {
    return t('flows.deploy.label', { id: props.deployment.id });
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
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1600px] p-4">
            <div class="overflow-hidden rounded-xl border bg-background">
                <FlowDeploymentDetailsContent
                    :deployment="deployment"
                    :status-tone="statusTone"
                    :status-label="statusLabel"
                    :run-type-label="runTypeLabel"
                    :format-date="formatDate"
                    :format-duration="formatDuration"
                />
            </div>
        </div>
    </AppLayout>
</template>
