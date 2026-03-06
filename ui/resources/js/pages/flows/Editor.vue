<script setup lang="ts">
import FlowEditorDeployments from '@/components/flows/editor/FlowEditorDeployments.vue';
import FlowEditorHeader from '@/components/flows/editor/FlowEditorHeader.vue';
import FlowEditorSettings from '@/components/flows/editor/FlowEditorSettings.vue';
import FlowEditorSummary from '@/components/flows/editor/FlowEditorSummary.vue';
import FlowEditorWorkspace from '@/components/flows/editor/FlowEditorWorkspace.vue';
import type {
    DeploymentCard,
    FlowDeployment,
    FlowDetail,
    FlowHistory,
    FlowLog,
    FlowRun,
    Permissions,
    RunStat,
} from '@/components/flows/editor/types';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    deployments as flowDeployments,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flow: FlowDetail;
    productionRun: FlowRun | null;
    developmentRun: FlowRun | null;
    productionLogsCount: number;
    developmentLogs: FlowLog[];
    deployments?: FlowDeployment[];
    status?: string | null;
    runStats: RunStat[];
    history: FlowHistory[];
    permissions: Permissions;
}>();

const { t, locale } = useI18n();

const actionInProgress = ref<
    'run' | 'stop' | 'deploy' | 'undeploy' | 'archive' | 'restore' | null
>(null);
const saving = ref(false);
const refreshInFlight = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

const form = useForm({
    name: props.flow.name,
    description: props.flow.description || '',
    code: props.flow.code || '',
});

const resolveGraphId = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim().length > 0) {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

const buildGraphSnapshotSignature = (
    graph: Record<string, unknown> | null | undefined,
): string => {
    const rawNodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    const rawEdges = Array.isArray(graph?.edges) ? graph.edges : [];

    const nodes = rawNodes
        .flatMap((rawNode) => {
            if (!rawNode || typeof rawNode !== 'object') {
                return [];
            }

            const node = rawNode as Record<string, unknown>;
            const id = resolveGraphId(node.id ?? node.name);
            if (!id) {
                return [];
            }

            const nodeType =
                typeof node.type === 'string'
                    ? node.type.toLowerCase()
                    : 'other';
            const label =
                resolveGraphId(node.label ?? node.id ?? node.name) ?? id;

            return [`${id}:${nodeType}:${label}`];
        })
        .sort();

    const edges = rawEdges
        .flatMap((rawEdge) => {
            if (!rawEdge || typeof rawEdge !== 'object') {
                return [];
            }

            const edge = rawEdge as Record<string, unknown>;
            const from = resolveGraphId(edge.from);
            const to = resolveGraphId(edge.to);
            if (!from || !to) {
                return [];
            }

            return [`${from}->${to}`];
        })
        .sort();

    return `n:${nodes.join('|')}|e:${edges.join('|')}`;
};

const buildHistorySnapshotSignature = (history: FlowHistory[]): string => {
    return history
        .map((historyItem) => `${historyItem.id}:${historyItem.created_at}`)
        .join('|');
};

const stableFlowGraph = shallowRef<Record<string, unknown> | null>(
    props.flow.graph ?? null,
);
const stableFlowGraphSignature = ref(
    buildGraphSnapshotSignature(props.flow.graph),
);

const stableHistory = shallowRef<FlowHistory[]>(props.history);
const stableHistorySignature = ref(
    buildHistorySnapshotSignature(props.history),
);

const refreshOnlyProps = [
    'flow',
    'productionRun',
    'developmentRun',
    'productionLogsCount',
    'developmentLogs',
    'deployments',
    'runStats',
    'history',
    'recentFlows',
    'flash',
] as const;

const currentProduction = computed(() => props.productionRun);
const currentDevelopment = computed(() => props.developmentRun);
const deployments = computed(() => props.deployments ?? []);
const recentDeployments = computed(() => deployments.value.slice(0, 5));
const allDeploymentsUrl = computed(() => {
    return flowDeployments({ flow: props.flow.id ?? 0 }).url;
});
const canSave = computed(() => props.permissions.canUpdate);
const pageTitle = computed(() => form.name || t('flows.untitled'));
const isArchived = computed(() => Boolean(props.flow.archived_at));
const hasActiveDeploys = computed(() =>
    Boolean(
        currentProduction.value?.active || currentDevelopment.value?.active,
    ),
);

const hasUnsavedFlowChanges = computed(() => {
    return (
        form.name !== (props.flow.name || '') ||
        form.description !== (props.flow.description || '') ||
        form.code !== (props.flow.code || '')
    );
});

const codeErrorMessages = computed(() => {
    if (!form.errors.code) {
        return [];
    }

    return form.errors.code
        .split('\n')
        .map((message) => message.trim())
        .filter((message) => message.length > 0);
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
    {
        title: t('flows.breadcrumbs.flow', { id: props.flow.id }),
        href: flowShow({ flow: props.flow.id }).url,
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

const relativeTimeFormatter = computed(() => {
    return new Intl.RelativeTimeFormat(locale.value, {
        numeric: 'auto',
    });
});

const formatRecentDate = (value?: string | null): string => {
    const parsed = parseDateMs(value);
    if (parsed === null) {
        return formatDate(value);
    }

    const deltaSeconds = Math.round((parsed - Date.now()) / 1000);
    const absSeconds = Math.abs(deltaSeconds);

    if (absSeconds < 60) {
        return relativeTimeFormatter.value.format(deltaSeconds, 'second');
    }

    const deltaMinutes = Math.round(deltaSeconds / 60);
    if (Math.abs(deltaMinutes) < 60) {
        return relativeTimeFormatter.value.format(deltaMinutes, 'minute');
    }

    const deltaHours = Math.round(deltaMinutes / 60);
    if (Math.abs(deltaHours) < 24) {
        return relativeTimeFormatter.value.format(deltaHours, 'hour');
    }

    const deltaDays = Math.round(deltaHours / 24);
    return relativeTimeFormatter.value.format(deltaDays, 'day');
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

const graphIsOutdated = computed(() => {
    const codeUpdated = parseDateMs(props.flow.code_updated_at);
    const graphGenerated = parseDateMs(props.flow.graph_generated_at);

    return (
        codeUpdated !== null &&
        (graphGenerated === null || graphGenerated < codeUpdated)
    );
});

const graphMeta = computed(() => {
    return {
        actors: countGraphNodesByType(stableFlowGraph.value, 'actor'),
        events: countGraphNodesByType(stableFlowGraph.value, 'event'),
        status: statusLabel(currentDevelopment.value?.status),
        freshnessLabel: graphIsOutdated.value
            ? t('common.outdated')
            : t('common.updated_at'),
        updatedAt: formatRecentDate(props.flow.graph_generated_at),
    };
});

const countHistoryDiffChanges = (
    diff?: string | null,
): { added: number; removed: number } => {
    if (!diff) {
        return { added: 0, removed: 0 };
    }

    let added = 0;
    let removed = 0;

    for (const line of diff.split('\n')) {
        if (
            line.startsWith('+++') ||
            line.startsWith('---') ||
            line.startsWith('@@')
        ) {
            continue;
        }

        if (line.startsWith('+')) {
            added += 1;
            continue;
        }

        if (line.startsWith('-')) {
            removed += 1;
        }
    }

    return { added, removed };
};

const historyCards = computed(() => {
    return stableHistory.value.map((item, index) => {
        const previousVersion = stableHistory.value[index - 1];
        const originalCode = item.code ?? '';
        const modifiedCode =
            index === 0 ? (form.code ?? '') : (previousVersion?.code ?? '');

        return {
            item,
            diffChanges: countHistoryDiffChanges(item.diff),
            originalCode,
            modifiedCode,
        };
    });
});

const deploymentCards = computed<DeploymentCard[]>(() => {
    return recentDeployments.value.map((deployment) => {
        return {
            deployment,
            graphMeta: {
                actors: countGraphNodesByType(deployment.graph, 'actor'),
                events: countGraphNodesByType(deployment.graph, 'event'),
                status: statusLabel(deployment.status),
                freshnessLabel: t('common.updated_at'),
                updatedAt: formatRecentDate(
                    deployment.finished_at ??
                        deployment.started_at ??
                        deployment.created_at,
                ),
            },
        };
    });
});

watch(
    () => props.flow.graph,
    (nextGraph) => {
        const nextSignature = buildGraphSnapshotSignature(nextGraph);
        if (nextSignature === stableFlowGraphSignature.value) {
            return;
        }

        stableFlowGraphSignature.value = nextSignature;
        stableFlowGraph.value = nextGraph ?? null;
    },
    { deep: true },
);

watch(
    () => props.history,
    (nextHistory) => {
        const nextSignature = buildHistorySnapshotSignature(nextHistory);
        if (nextSignature === stableHistorySignature.value) {
            return;
        }

        stableHistorySignature.value = nextSignature;
        stableHistory.value = nextHistory;
    },
    { deep: true },
);

const refreshFlowView = (): void => {
    if (refreshInFlight.value) {
        return;
    }

    refreshInFlight.value = true;

    router.reload({
        preserveState: true,
        preserveScroll: true,
        only: [...refreshOnlyProps],
        onFinish: () => {
            refreshInFlight.value = false;
        },
    });
};

const submitAction = (
    action: NonNullable<typeof actionInProgress.value>,
    url: string,
): void => {
    if (actionInProgress.value !== null) {
        return;
    }

    actionInProgress.value = action;

    router.post(
        url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: [...refreshOnlyProps],
            onFinish: () => {
                actionInProgress.value = null;
            },
        },
    );
};

const save = (): void => {
    if (!canSave.value) {
        return;
    }

    saving.value = true;

    form.put(`/flows/${props.flow.id}`, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
        },
    });
};

const saveBeforeAction = (onSuccess: () => void): void => {
    if (!hasUnsavedFlowChanges.value) {
        onSuccess();

        return;
    }

    if (!canSave.value) {
        return;
    }

    saving.value = true;

    form.put(`/flows/${props.flow.id}`, {
        preserveScroll: true,
        only: [...refreshOnlyProps],
        onSuccess: () => {
            onSuccess();
        },
        onFinish: () => {
            saving.value = false;
        },
    });
};

const runFlow = (): void => {
    if (!props.permissions.canRun || form.processing || saving.value) {
        return;
    }

    saveBeforeAction(() => {
        submitAction('run', `/flows/${props.flow.id}/run`);
    });
};

const stopFlow = (): void => {
    if (!props.permissions.canRun) {
        return;
    }

    submitAction('stop', `/flows/${props.flow.id}/stop`);
};

const deployProd = (): void => {
    if (!props.permissions.canRun || form.processing || saving.value) {
        return;
    }

    saveBeforeAction(() => {
        submitAction('deploy', `/flows/${props.flow.id}/deploy`);
    });
};

const undeployProd = (): void => {
    if (!props.permissions.canRun) {
        return;
    }

    submitAction('undeploy', `/flows/${props.flow.id}/undeploy`);
};

const archiveFlow = (): void => {
    if (!props.permissions.canUpdate) {
        return;
    }

    submitAction('archive', `/flows/${props.flow.id}/archive`);
};

const restoreFlow = (): void => {
    if (!props.permissions.canUpdate) {
        return;
    }

    submitAction('restore', `/flows/${props.flow.id}/restore`);
};

const deleteFlow = (): void => {
    if (!props.permissions.canDelete) {
        return;
    }

    if (!confirm(t('flows.delete.confirm'))) {
        return;
    }

    router.delete(`/flows/${props.flow.id}`, {
        preserveScroll: true,
    });
};

const shouldPollForUpdates = computed(() => {
    return (
        actionInProgress.value === null &&
        (hasActiveDeploys.value || graphIsOutdated.value)
    );
});

watch(
    shouldPollForUpdates,
    (poll) => {
        if (poll) {
            if (refreshTimer === null) {
                refreshTimer = setInterval(() => {
                    refreshFlowView();
                }, 3000);
            }

            return;
        }

        if (refreshTimer !== null) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (refreshTimer !== null) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
});
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1600px] divide-y pt-3 pb-8">
            <FlowEditorHeader
                :name="form.name"
                :description="form.description"
                :current-production-active="Boolean(currentProduction?.active)"
                :can-run="props.permissions.canRun"
                :action-in-progress="actionInProgress"
                @deploy-prod="deployProd"
                @undeploy-prod="undeployProd"
            />

            <FlowEditorSummary
                :flow-runs-count="props.flow.runs_count"
                :last-started-at="props.flow.last_started_at"
                :last-finished-at="props.flow.last_finished_at"
                :has-current-production="Boolean(currentProduction)"
                :current-production-status="currentProduction?.status"
                :current-production-started-at="currentProduction?.started_at"
                :current-production-finished-at="currentProduction?.finished_at"
                :current-production-events-count="
                    currentProduction?.events?.length ?? 0
                "
                :production-logs-count="props.productionLogsCount"
                :run-stats="props.runStats"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :format-recent-date="formatRecentDate"
                :format-duration="formatDuration"
            />

            <FlowEditorWorkspace
                v-model:code="form.code"
                :can-update="props.permissions.canUpdate"
                :can-run="props.permissions.canRun"
                :action-in-progress="actionInProgress"
                :current-development-active="
                    Boolean(currentDevelopment?.active)
                "
                :code-updated-at="props.flow.code_updated_at"
                :code-error-messages="codeErrorMessages"
                :history-cards="historyCards"
                :graph="stableFlowGraph"
                :graph-meta="graphMeta"
                :graph-is-outdated="graphIsOutdated"
                :development-logs="props.developmentLogs"
                :format-recent-date="formatRecentDate"
                :format-date="formatDate"
                @run-flow="runFlow"
                @stop-flow="stopFlow"
            />

            <FlowEditorDeployments
                v-if="deploymentCards.length"
                :deployment-cards="deploymentCards"
                :all-deployments-url="allDeploymentsUrl"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :run-type-label="runTypeLabel"
                :format-date="formatDate"
                :format-duration="formatDuration"
            />

            <FlowEditorSettings
                v-if="props.permissions.canUpdate"
                v-model:name="form.name"
                v-model:description="form.description"
                :processing="form.processing"
                :can-save="canSave"
                :is-archived="isArchived"
                :can-update="props.permissions.canUpdate"
                :can-delete="props.permissions.canDelete"
                :has-active-deploys="hasActiveDeploys"
                :action-in-progress="actionInProgress"
                :name-error="form.errors.name"
                @save="save"
                @archive="archiveFlow"
                @restore="restoreFlow"
                @delete="deleteFlow"
            />
        </div>
    </AppLayout>
</template>
