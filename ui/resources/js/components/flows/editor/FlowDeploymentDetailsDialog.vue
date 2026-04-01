<script setup lang="ts">
import FlowDeploymentDetailsContent from '@/components/flows/editor/FlowDeploymentDetailsContent.vue';
import type { DeploymentCard, FlowRun } from '@/components/flows/editor/types';
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
        <DialogContent
            class="h-[90vh] max-h-[90vh] gap-0 overflow-hidden p-0 xl:max-w-[1400px]"
        >
            <DialogHeader class="sr-only">
                <DialogTitle v-if="deploymentCard">
                    {{
                        t('flows.deploy.label', {
                            id: deploymentCard.deployment.id,
                        })
                    }}
                </DialogTitle>
                <DialogTitle v-else>
                    {{ t('flows.deployments.details_title') }}
                </DialogTitle>
            </DialogHeader>

            <FlowDeploymentDetailsContent
                :deployment="deploymentCard?.deployment ?? null"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :run-type-label="runTypeLabel"
                :format-date="formatDate"
                :format-duration="formatDuration"
            />
        </DialogContent>
    </Dialog>
</template>
