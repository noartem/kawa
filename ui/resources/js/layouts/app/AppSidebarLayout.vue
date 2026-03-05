<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { BreadcrumbItemType } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const { t } = useI18n();

interface FlashPayload {
    success?: string | null;
    error?: string | null;
}

const flash = computed<FlashPayload>(() => {
    const payload = page.props.flash as FlashPayload | undefined;

    return payload ?? {};
});

const successMessage = computed(() => flash.value.success ?? null);
const errorMessage = computed(() => flash.value.error ?? null);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />

            <div
                v-if="successMessage || errorMessage"
                class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[24rem] max-w-[calc(100vw-2rem)] flex-col gap-2"
            >
                <Alert
                    v-if="successMessage"
                    class="pointer-events-auto border-border bg-background shadow-lg"
                >
                    <CheckCircle2 class="size-4" />
                    <AlertTitle>{{ t('statuses.success') }}</AlertTitle>
                    <AlertDescription>{{ successMessage }}</AlertDescription>
                </Alert>

                <Alert
                    v-if="errorMessage"
                    variant="destructive"
                    class="pointer-events-auto shadow-lg"
                >
                    <AlertCircle class="size-4" />
                    <AlertTitle>{{ t('statuses.error') }}</AlertTitle>
                    <AlertDescription>{{ errorMessage }}</AlertDescription>
                </Alert>
            </div>
        </AppContent>
    </AppShell>
</template>
