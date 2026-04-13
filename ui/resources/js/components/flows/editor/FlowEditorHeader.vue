<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Share2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{
    name: string;
    description: string;
    canRun: boolean;
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
                <Button variant="outline" :disabled="!canRun">
                    <Share2 class="size-4" />
                    {{ t('actions.share') }}
                </Button>
            </div>
        </div>
    </section>
</template>
