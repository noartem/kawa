<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Share2, Square, UploadCloud } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{
    name: string;
    description: string;
    currentProductionActive: boolean;
    canRun: boolean;
    actionInProgress: string | null;
}>();

defineEmits<{
    'deploy-prod': [];
    'undeploy-prod': [];
}>();

const { t } = useI18n();
</script>

<template>
    <section class="p-4">
        <div
            class="flex flex-col gap-4 py-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <div class="space-y-1.5">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                    {{ name || t('flows.untitled') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ description || t('flows.description.placeholder') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="currentProductionActive"
                    variant="outline"
                    :disabled="!canRun || actionInProgress !== null"
                    @click="$emit('undeploy-prod')"
                >
                    <Square class="size-4" />
                    {{ t('actions.stop') }}
                </Button>
                <Button
                    v-else
                    :disabled="!canRun || actionInProgress !== null"
                    @click="$emit('deploy-prod')"
                >
                    <UploadCloud class="size-4" />
                    {{ t('actions.deploy') }}
                </Button>
                <Button variant="outline" :disabled="!canRun">
                    <Share2 class="size-4" />
                    {{ t('actions.share') }}
                </Button>
            </div>
        </div>
    </section>
</template>
