<script setup lang="ts">
import type { RunStat } from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { useI18n } from 'vue-i18n';

defineProps<{
    flowRunsCount?: number | null;
    lastStartedAt?: string | null;
    lastFinishedAt?: string | null;
    hasCurrentProduction: boolean;
    currentProductionStatus?: string | null;
    currentProductionStartedAt?: string | null;
    currentProductionFinishedAt?: string | null;
    currentProductionEventsCount: number;
    productionLogsCount: number;
    runStats: RunStat[];
    statusTone: (status?: string | null) => string;
    statusLabel: (status?: string | null) => string;
    formatRecentDate: (value?: string | null) => string;
    formatDuration: (start?: string | null, end?: string | null) => string;
}>();

const { t } = useI18n();
</script>

<template>
    <section class="p-4">
        <div
            class="grid divide-y divide-border lg:grid-cols-3 lg:divide-x lg:divide-y-0"
        >
            <div class="space-y-2 py-2 pr-3">
                <p
                    class="text-[11px] tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('flows.summary.title') }}
                </p>
                <div class="flex items-end justify-between gap-3">
                    <p class="text-2xl leading-none font-semibold">
                        {{ flowRunsCount ?? 0 }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('flows.summary.runs') }}
                    </p>
                </div>
                <div class="grid gap-1 text-xs">
                    <p class="flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">{{
                            t('flows.summary.last_start')
                        }}</span>
                        <span class="text-right font-medium">{{
                            formatRecentDate(lastStartedAt)
                        }}</span>
                    </p>
                    <p class="flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">{{
                            t('flows.summary.last_finish')
                        }}</span>
                        <span class="text-right font-medium">{{
                            formatRecentDate(lastFinishedAt)
                        }}</span>
                    </p>
                </div>
            </div>

            <div class="space-y-2 px-3 py-2">
                <div class="flex items-center justify-between gap-2">
                    <p
                        class="text-[11px] tracking-wide text-muted-foreground uppercase"
                    >
                        {{ t('flows.current_deploy.title') }}
                    </p>
                    <Badge
                        variant="outline"
                        :class="statusTone(currentProductionStatus)"
                    >
                        {{
                            hasCurrentProduction
                                ? statusLabel(currentProductionStatus)
                                : t('common.empty')
                        }}
                    </Badge>
                </div>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                    <p class="text-muted-foreground">
                        {{ t('common.started') }}
                    </p>
                    <p class="text-right font-medium">
                        {{ formatRecentDate(currentProductionStartedAt) }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ t('flows.metrics.duration') }}
                    </p>
                    <p class="text-right font-medium">
                        {{
                            formatDuration(
                                currentProductionStartedAt,
                                currentProductionFinishedAt,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ t('flows.metrics.events') }}
                    </p>
                    <p class="text-right font-medium">
                        {{ currentProductionEventsCount }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ t('common.logs') }}
                    </p>
                    <p class="text-right font-medium">
                        {{ productionLogsCount }}
                    </p>
                </div>
            </div>

            <div class="space-y-2 py-2 pl-3">
                <p
                    class="text-[11px] tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('flows.health.title') }}
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        v-for="stat in runStats"
                        :key="stat.status"
                        variant="outline"
                        :class="statusTone(stat.status)"
                    >
                        {{ statusLabel(stat.status) }}: {{ stat.total }}
                    </Badge>
                </div>
            </div>
        </div>
    </section>
</template>
