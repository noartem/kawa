<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { show as flowShow, index as flowsIndex } from '@/routes/flows';
import {
    type BreadcrumbItem,
    type FlowRunSummary,
    type FlowSidebarItem,
    type FlowStatsSummary,
} from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Activity, ArrowUpRight, Plus, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowOverview extends FlowSidebarItem {
    runs_count?: number;
    updated_at?: string;
}

const props = defineProps<{
    flowStats: FlowStatsSummary;
    recentFlows: FlowOverview[];
    recentRuns: FlowRunSummary[];
    activeDeploys: FlowRunSummary[];
}>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.dashboard'),
        href: dashboard().url,
    },
]);

const statusTone = (status?: string | null) => {
    switch (status) {
        case 'running':
            return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25';
        case 'error':
            return 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/25';
        case 'stopped':
            return 'bg-amber-500/15 text-amber-200 ring-1 ring-amber-500/25';
        default:
            return 'bg-muted text-muted-foreground ring-1 ring-border';
    }
};

const formatDate = (value?: string | null) => {
    if (!value) return t('common.empty');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const headline = computed(() => {
    const running = props.flowStats?.running ?? 0;
    const total = props.flowStats?.total ?? 0;
    const errors = props.flowStats?.errors ?? 0;
    if (running > 0) {
        return t('dashboard.headline.running', { count: running });
    }
    if (errors > 0) {
        return t('dashboard.headline.errors', { count: errors });
    }
    return t('dashboard.headline.total', { count: total });
});

const totalRuns = computed(
    () =>
        props.flowStats?.runsByStatus?.reduce(
            (sum, row) => sum + (row.total ?? 0),
            0,
        ) ?? 0,
);

const statusLabel = (status?: string | null) =>
    t(`statuses.${status ?? 'unknown'}`);
const runTypeLabel = (type?: FlowRunSummary['type'] | null) =>
    type === 'production'
        ? t('environments.production')
        : t('environments.development');
</script>

<template>
    <Head :title="t('nav.dashboard')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 px-4 pt-2 pb-10">
            <div
                class="relative overflow-hidden rounded-2xl border border-border/80 bg-background p-6 shadow-sm"
            >
                <div
                    class="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="space-y-3">
                        <h1 class="text-3xl leading-tight font-semibold">
                            {{ t('dashboard.title') }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ headline }}
                        </p>
                        <div class="flex flex-wrap gap-3 pt-2">
                            <Button as-child>
                                <Link :href="flowsIndex().url">
                                    <Plus class="size-4" />
                                    {{ t('flows.actions.new') }}
                                </Link>
                            </Button>
                            <Button variant="outline" as-child>
                                <Link :href="flowsIndex().url">
                                    <Workflow class="size-4" />
                                    {{ t('flows.actions.all') }}
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div
                        class="flex flex-col gap-3 rounded-xl border border-border/60 bg-background/70 p-4 text-sm backdrop-blur"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary"
                            >
                                {{ t('dashboard.summary.total_label') }}
                            </div>
                            <div class="text-3xl font-semibold">
                                {{ flowStats?.total ?? 0 }}
                            </div>
                        </div>
                        <div
                            class="grid grid-cols-3 gap-2 text-xs text-muted-foreground"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="h-2 w-2 rounded-full bg-emerald-400/70"
                                />
                                {{ flowStats?.running ?? 0 }}
                                {{ t('dashboard.summary.running') }}
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="h-2 w-2 rounded-full bg-amber-400/70"
                                />
                                {{ flowStats?.stopped ?? 0 }}
                                {{ t('dashboard.summary.stopped') }}
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="h-2 w-2 rounded-full bg-rose-400/70"
                                />
                                {{ flowStats?.errors ?? 0 }}
                                {{ t('dashboard.summary.errors') }}
                            </div>
                        </div>
                        <p class="text-[11px] text-muted-foreground">
                            {{
                                t('dashboard.summary.updated', {
                                    date: formatDate(flowStats?.lastUpdatedAt),
                                })
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader class="pb-3">
                        <CardTitle>{{
                            t('dashboard.recent_flows.title')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('dashboard.recent_flows.description')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="recentFlows?.length"
                            class="divide-y divide-border"
                        >
                            <div
                                v-for="flow in recentFlows"
                                :key="flow.id"
                                class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="space-y-1">
                                    <Link
                                        :href="flowShow({ flow: flow.id }).url"
                                        class="inline-flex items-center gap-2 text-base font-semibold hover:text-primary"
                                    >
                                        <Workflow
                                            class="size-4 text-muted-foreground"
                                        />
                                        {{ flow.name }}
                                        <ArrowUpRight class="size-4" />
                                    </Link>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            t('dashboard.recent_flows.runs', {
                                                count: flow.runs_count ?? 0,
                                            })
                                        }}
                                        •
                                        {{
                                            t(
                                                'dashboard.recent_flows.updated',
                                                {
                                                    date: formatDate(
                                                        flow.updated_at,
                                                    ),
                                                },
                                            )
                                        }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <Badge
                                        :class="statusTone(flow.status)"
                                        variant="outline"
                                    >
                                        {{ statusLabel(flow.status) }}
                                    </Badge>
                                    <Link
                                        :href="flowShow({ flow: flow.id }).url"
                                        class="text-xs font-semibold text-primary hover:underline"
                                    >
                                        {{ t('actions.open') }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded-lg border border-dashed border-border p-6 text-sm text-muted-foreground"
                        >
                            {{ t('dashboard.recent_flows.empty') }}
                        </div>
                    </CardContent>
                </Card>

                <Card class="self-start">
                    <CardHeader class="pb-3">
                        <CardTitle>{{
                            t('dashboard.status_breakdown.title')
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-if="flowStats?.runsByStatus?.length"
                            class="space-y-3"
                        >
                            <div
                                v-for="row in flowStats.runsByStatus"
                                :key="row.status ?? 'unknown'"
                                class="space-y-1"
                            >
                                <div
                                    class="flex items-center justify-between text-sm"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="h-2 w-2 rounded-full bg-primary"
                                        />
                                        <span
                                            class="text-muted-foreground capitalize"
                                            >{{ statusLabel(row.status) }}</span
                                        >
                                    </div>
                                    <span class="font-semibold">{{
                                        row.total
                                    }}</span>
                                </div>
                                <div
                                    class="relative h-2 w-full overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="absolute inset-y-0 left-0 rounded-full bg-primary"
                                        :style="{
                                            width: `${Math.min((row.total / Math.max(totalRuns, 1)) * 100, 100)}%`,
                                        }"
                                    />
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-sm text-muted-foreground">
                            {{ t('dashboard.status_breakdown.empty') }}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader class="pb-3">
                        <CardTitle>{{
                            t('dashboard.runs_feed.title')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('dashboard.runs_feed.description')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="recentRuns?.length"
                            class="divide-y divide-border"
                        >
                            <div
                                v-for="run in recentRuns"
                                :key="run.id"
                                class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <Activity
                                            class="size-4 text-muted-foreground"
                                        />
                                        <span class="text-sm font-semibold">{{
                                            t('dashboard.runs_feed.run', {
                                                id: run.id,
                                            })
                                        }}</span>
                                        <Badge
                                            :class="statusTone(run.status)"
                                            variant="outline"
                                            >{{
                                                statusLabel(run.status)
                                            }}</Badge
                                        >
                                    </div>
                                    <div
                                        class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                                    >
                                        <Link
                                            :href="
                                                flowShow({ flow: run.flow.id })
                                                    .url
                                            "
                                            class="inline-flex items-center gap-1 font-semibold hover:text-primary"
                                        >
                                            {{ run.flow.name }}
                                            <ArrowUpRight class="size-3" />
                                        </Link>
                                        <Separator
                                            orientation="vertical"
                                            class="h-4"
                                        />
                                        <span>{{
                                            t('dashboard.runs_feed.started', {
                                                date: formatDate(
                                                    run.started_at,
                                                ),
                                            })
                                        }}</span>
                                        <span class="text-muted-foreground"
                                            >→</span
                                        >
                                        <span>{{
                                            t('dashboard.runs_feed.finished', {
                                                date: formatDate(
                                                    run.finished_at,
                                                ),
                                            })
                                        }}</span>
                                    </div>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        t('dashboard.runs_feed.created', {
                                            date: formatDate(run.created_at),
                                        })
                                    }}
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded-lg border border-dashed border-border p-6 text-sm text-muted-foreground"
                        >
                            {{ t('dashboard.runs_feed.empty') }}
                        </div>
                    </CardContent>
                </Card>

                <Card class="self-start">
                    <CardHeader class="pb-3">
                        <CardTitle>{{
                            t('dashboard.active_deploys.title')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('dashboard.active_deploys.description')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="activeDeploys?.length" class="space-y-2">
                            <div
                                v-for="run in activeDeploys"
                                :key="run.id"
                                class="rounded-lg border border-border/60 bg-muted/30 p-3"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <Link
                                            :href="
                                                flowShow({ flow: run.flow.id })
                                                    .url
                                            "
                                            class="text-sm font-semibold hover:text-primary"
                                        >
                                            {{ run.flow.name }}
                                        </Link>
                                        <div
                                            class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                                        >
                                            <Badge
                                                :class="statusTone(run.status)"
                                                variant="outline"
                                            >
                                                {{ statusLabel(run.status) }}
                                            </Badge>
                                            <span>{{
                                                runTypeLabel(run.type)
                                            }}</span>
                                        </div>
                                    </div>
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            t(
                                                'dashboard.active_deploys.started',
                                                {
                                                    date: formatDate(
                                                        run.started_at,
                                                    ),
                                                },
                                            )
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
                        >
                            {{ t('dashboard.active_deploys.empty') }}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
