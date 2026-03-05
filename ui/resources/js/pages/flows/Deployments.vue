<script setup lang="ts">
import FlowEditorDeployments from '@/components/flows/editor/FlowEditorDeployments.vue';
import type {
    DeploymentCard,
    FlowDeployment,
    FlowDeploymentsPaginator,
    FlowRun,
} from '@/components/flows/editor/types';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    deployments as flowDeployments,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flow: {
        id: number;
        name: string;
    };
    deployments: FlowDeploymentsPaginator;
}>();

const { t } = useI18n();

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
]);

const statusLabel = (status?: string | null): string => {
    return t(`statuses.${status ?? 'unknown'}`);
};

const runTypeLabel = (type?: FlowRun['type'] | null): string => {
    return type === 'production'
        ? t('environments.production')
        : t('environments.development');
};

const statusTone = (status?: string | null): string => {
    switch (status) {
        case 'running':
        case 'ready':
        case 'locked':
            return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300';
        case 'error':
        case 'failed':
        case 'lock_failed':
            return 'border-rose-500/40 bg-rose-500/10 text-rose-300';
        case 'stopped':
        case 'success':
            return 'border-amber-500/40 bg-amber-500/10 text-amber-300';
        default:
            return 'border-border bg-muted/40 text-muted-foreground';
    }
};

const parseDateMs = (value?: string | null): number | null => {
    if (!value) {
        return null;
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return parsed.getTime();
};

const formatDate = (value?: string | null): string => {
    if (!value) {
        return t('common.empty');
    }

    const parsed = parseDateMs(value);
    if (parsed === null) {
        return value;
    }

    return new Date(parsed).toLocaleString();
};

const formatDuration = (start?: string | null, end?: string | null): string => {
    if (!start) {
        return t('common.empty');
    }

    const startAt = parseDateMs(start);
    const endAt = parseDateMs(end) ?? Date.now();

    if (startAt === null) {
        return t('common.empty');
    }

    const totalSeconds = Math.max(Math.floor((endAt - startAt) / 1000), 0);
    const minutes = Math.floor(totalSeconds / 60);
    const hours = Math.floor(minutes / 60);

    if (hours > 0) {
        return t('common.duration.hours', { hours, minutes: minutes % 60 });
    }

    if (minutes > 0) {
        return t('common.duration.minutes', {
            minutes,
            seconds: totalSeconds % 60,
        });
    }

    return t('common.duration.seconds', { seconds: totalSeconds });
};

const countGraphNodesByType = (
    graph: Record<string, unknown> | null | undefined,
    expectedType: 'actor' | 'event',
): number => {
    const rawNodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    let count = 0;

    for (const rawNode of rawNodes) {
        if (!rawNode || typeof rawNode !== 'object') {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const nodeType =
            typeof node.type === 'string' ? node.type.toLowerCase() : null;

        if (nodeType === expectedType) {
            count += 1;
        }
    }

    return count;
};

const deploymentCards = computed<DeploymentCard[]>(() => {
    return props.deployments.data.map((deployment: FlowDeployment) => {
        return {
            deployment,
            graphMeta: {
                actors: countGraphNodesByType(deployment.graph, 'actor'),
                events: countGraphNodesByType(deployment.graph, 'event'),
                status: statusLabel(deployment.status),
                freshnessLabel: t('common.updated_at'),
                updatedAt: formatDate(
                    deployment.finished_at ??
                        deployment.started_at ??
                        deployment.created_at,
                ),
            },
        };
    });
});

const pageNumbers = computed<number[]>(() => {
    const start = Math.max(1, props.deployments.current_page - 2);
    const end = Math.min(
        props.deployments.last_page,
        props.deployments.current_page + 2,
    );
    const pages: number[] = [];

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const buildPageUrl = (page: number): string => {
    return `${flowDeployments({ flow: props.flow.id }).url}?page=${page}`;
};
</script>

<template>
    <Head :title="t('flows.deployments_page.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1600px] space-y-4 pt-3 pb-8">
            <section class="rounded-xl border border-border bg-background p-4">
                <h1 class="text-xl font-semibold">
                    {{ t('flows.deployments_page.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('flows.deployments_page.description') }}
                </p>
            </section>

            <FlowEditorDeployments
                v-if="deploymentCards.length"
                :deployment-cards="deploymentCards"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :run-type-label="runTypeLabel"
                :format-date="formatDate"
                :format-duration="formatDuration"
            />

            <section
                v-else
                class="rounded-xl border border-border bg-background p-6 text-sm text-muted-foreground"
            >
                {{ t('flows.deployments_page.empty') }}
            </section>

            <nav
                v-if="props.deployments.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3 px-4"
                :aria-label="t('flows.deployments_page.title')"
            >
                <Link
                    v-if="props.deployments.current_page > 1"
                    :href="buildPageUrl(props.deployments.current_page - 1)"
                    class="rounded-md border border-border px-3 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
                >
                    {{ t('flows.deployments_page.pagination.previous') }}
                </Link>
                <span v-else class="text-sm text-muted-foreground">
                    {{ t('flows.deployments_page.pagination.previous') }}
                </span>

                <div class="flex flex-wrap items-center gap-1">
                    <Link
                        v-for="page in pageNumbers"
                        :key="page"
                        :href="buildPageUrl(page)"
                        class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                        :class="
                            page === props.deployments.current_page
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-foreground hover:bg-muted'
                        "
                    >
                        {{ page }}
                    </Link>
                </div>

                <Link
                    v-if="
                        props.deployments.current_page <
                        props.deployments.last_page
                    "
                    :href="buildPageUrl(props.deployments.current_page + 1)"
                    class="rounded-md border border-border px-3 py-1.5 text-sm text-foreground transition-colors hover:bg-muted"
                >
                    {{ t('flows.deployments_page.pagination.next') }}
                </Link>
                <span v-else class="text-sm text-muted-foreground">
                    {{ t('flows.deployments_page.pagination.next') }}
                </span>
            </nav>
        </div>
    </AppLayout>
</template>
