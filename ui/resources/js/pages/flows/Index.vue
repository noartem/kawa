<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    create as flowCreate,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import type { BreadcrumbItem, FlowSidebarItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Activity, Clock3, FileCode, Plus, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Flow extends FlowSidebarItem {
    description?: string | null;
    runs_count?: number;
    container_id?: string | null;
    updated_at?: string;
}

const props = defineProps<{
    flows: Array<Flow>;
}>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
]);

const metrics = computed(() => {
    const total = props.flows.length;
    const running = props.flows.filter(
        (flow) => flow.status === 'running',
    ).length;
    const failing = props.flows.filter(
        (flow) => flow.status === 'error',
    ).length;
    const drafts = props.flows.filter(
        (flow) => !flow.status || flow.status === 'draft',
    ).length;
    const totalRuns = props.flows.reduce(
        (sum, flow) => sum + (flow.runs_count ?? 0),
        0,
    );

    return { total, running, failing, drafts, totalRuns };
});

const statusTone = (status?: string | null) => {
    switch (status) {
        case 'running':
            return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
        case 'error':
            return 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30';
        case 'stopped':
            return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
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

const statusLabel = (status?: string | null) =>
    t(`statuses.${status ?? 'draft'}`);
</script>

<template>
    <Head :title="t('nav.flows')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 pt-4 pb-12">
            <section class="space-y-6 px-4">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-center">
                    <div class="space-y-1">
                        <h1 class="text-3xl leading-tight font-semibold">
                            {{ t('flows.index.title') }}
                        </h1>
                        <div class="flex flex-wrap gap-3 pt-2">
                            <Badge
                                variant="outline"
                                class="bg-primary/10 text-primary"
                                >{{
                                    t('flows.index.total', {
                                        count: metrics.total,
                                    })
                                }}</Badge
                            >
                            <Badge
                                v-if="metrics.running > 0"
                                variant="outline"
                                class="bg-emerald-500/10 text-emerald-300"
                                >{{
                                    t('flows.index.running', {
                                        count: metrics.running,
                                    })
                                }}
                            </Badge>
                            <Badge
                                v-if="metrics.failing > 0"
                                variant="outline"
                                class="bg-rose-500/10 text-rose-300"
                                >{{
                                    t('flows.index.errors', {
                                        count: metrics.failing,
                                    })
                                }}
                            </Badge>
                            <Badge
                                v-if="metrics.drafts > 0"
                                variant="outline"
                                class="bg-muted/60 text-muted-foreground"
                                >{{
                                    t('flows.index.drafts', {
                                        count: metrics.drafts,
                                    })
                                }}
                            </Badge>
                        </div>
                    </div>

                    <div class="flex-1" />

                    <div class="flex flex-wrap gap-3">
                        <Button as-child>
                            <Link :href="flowCreate().url">
                                <Plus class="size-4" />
                                {{ t('flows.actions.create') }}
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            <Separator />

            <section class="px-4">
                <div v-if="props.flows.length" class="space-y-0">
                    <template
                        v-for="(flow, index) in props.flows"
                        :key="flow.id"
                    >
                        <div
                            class="flex flex-col gap-2 py-4 md:flex-row md:items-center md:justify-between"
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
                                </Link>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        flow.description ||
                                        t('flows.index.description_empty')
                                    }}
                                </p>
                                <div
                                    class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                                >
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Activity class="size-3" />
                                        {{
                                            t('flows.index.runs', {
                                                count: flow.runs_count ?? 0,
                                            })
                                        }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Clock3 class="size-3" />
                                        {{
                                            t('flows.index.updated', {
                                                date: formatDate(
                                                    flow.updated_at,
                                                ),
                                            })
                                        }}
                                    </span>
                                </div>
                            </div>
                            <div
                                class="flex items-center gap-3 self-start md:self-center"
                            >
                                <Badge
                                    :class="statusTone(flow.status)"
                                    variant="outline"
                                >
                                    {{ statusLabel(flow.status) }}
                                </Badge>
                                <Button as-child variant="outline" size="sm">
                                    <Link
                                        :href="flowShow({ flow: flow.id }).url"
                                    >
                                        <FileCode class="size-4" />
                                        {{ t('actions.open') }}
                                    </Link>
                                </Button>
                            </div>
                        </div>
                        <Separator v-if="index < props.flows.length - 1" />
                    </template>
                </div>
                <p v-else class="py-6 text-sm text-muted-foreground">
                    {{ t('flows.index.empty') }}
                </p>
            </section>
        </div>
    </AppLayout>
</template>
