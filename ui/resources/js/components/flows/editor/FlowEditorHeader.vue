<script setup lang="ts">
import type { FlowEnvironment } from '@/components/flows/editor/types';
import { Button } from '@/components/ui/button';
import { Share2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{
    name: string;
    description: string;
    activeDeploymentType: FlowEnvironment;
    canRun: boolean;
    actionInProgress: string | null;
}>();

defineEmits<{
    'update:deploymentType': [value: FlowEnvironment];
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
                <div
                    class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/30 p-1"
                >
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm transition"
                        :class="
                            activeDeploymentType === 'development'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :disabled="actionInProgress !== null"
                        @click="$emit('update:deploymentType', 'development')"
                    >
                        {{ t('environments.development') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm transition"
                        :class="
                            activeDeploymentType === 'production'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :disabled="actionInProgress !== null"
                        @click="$emit('update:deploymentType', 'production')"
                    >
                        {{ t('environments.production') }}
                    </button>
                </div>
                <Button variant="outline" :disabled="!canRun">
                    <Share2 class="size-4" />
                    {{ t('actions.share') }}
                </Button>
            </div>
        </div>
    </section>
</template>
