<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';
import { SquarePen } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{
    name: string;
    description: string;
    canRun: boolean;
    editorUrl?: string | null;
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
                <Button v-if="editorUrl" as-child class="h-11 px-5 text-base">
                    <Link :href="editorUrl">
                        <SquarePen />
                        {{ t('flows.actions.open_editor') }}
                    </Link>
                </Button>
            </div>
        </div>
    </section>
</template>
