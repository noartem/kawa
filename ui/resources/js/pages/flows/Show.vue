<script setup lang="ts">
import FlowEditorDeployments from '@/components/flows/editor/FlowEditorDeployments.vue';
import FlowEditorHeader from '@/components/flows/editor/FlowEditorHeader.vue';
import FlowEditorSettings from '@/components/flows/editor/FlowEditorSettings.vue';
import FlowEditorSummary from '@/components/flows/editor/FlowEditorSummary.vue';
import FlowPastChatsPanel from '@/components/flows/editor/FlowPastChatsPanel.vue';
import type {
    DeploymentCard,
    FlowDeployment,
    FlowChatConversation,
    FlowDetail,
    FlowEditorTab,
    FlowEnvironment,
    FlowHistory,
    FlowRun,
    Permissions,
    RunStat,
} from '@/components/flows/editor/types';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    archive as flowArchive,
    deployments as flowDeployments,
    destroy as flowDestroy,
    editor as flowEditor,
    restore as flowRestore,
    show as flowShow,
    index as flowsIndex,
    update as flowUpdate,
} from '@/routes/flows';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flow: FlowDetail;
    allChatsUrl: string;
    lastProductionDeployment: FlowDeployment | null;
    lastDevelopmentDeployment: FlowDeployment | null;
    productionLogsCount: number;
    deployments?: FlowDeployment[];
    runStats: RunStat[];
    history: FlowHistory[];
    pastChats: FlowChatConversation[];
    timezoneOptions: string[];
    permissions: Permissions;
    activeDeploymentType: FlowEnvironment;
    activeEditorTab: FlowEditorTab;
}>();

const { t, locale } = useI18n();

const actionInProgress = ref<'archive' | 'restore' | null>(null);

const form = useForm({
    name: props.flow.name,
    description: props.flow.description || '',
    timezone: props.flow.timezone || 'UTC',
});

const deployments = computed(() => props.deployments ?? []);
const recentDeployments = computed(() => deployments.value.slice(0, 5));
const currentProduction = computed(() => props.lastProductionDeployment);
const currentDevelopment = computed(() => props.lastDevelopmentDeployment);
const selectedDeployment = computed<FlowDeployment | null>(() => {
    return props.activeDeploymentType === 'production'
        ? currentProduction.value
        : currentDevelopment.value;
});
const canSave = computed(() => props.permissions.canUpdate);
const pageTitle = computed(() => form.name || t('flows.untitled'));
const isArchived = computed(() => Boolean(props.flow.archived_at));
const hasActiveDeploys = computed(() => {
    return Boolean(
        currentProduction.value?.active || currentDevelopment.value?.active,
    );
});
const currentDeploymentLabel = computed(() => {
    return props.activeDeploymentType === 'production'
        ? t('environments.production')
        : t('environments.development');
});
const currentDeploymentLogsCount = computed(() => {
    return props.activeDeploymentType === 'production'
        ? props.productionLogsCount
        : (selectedDeployment.value?.logs.length ?? 0);
});
const allDeploymentsUrl = computed(() => {
    return flowDeployments({ flow: props.flow.id ?? 0 }).url;
});

const buildShowQueryOptions = () => ({
    query: {
        deployment: props.activeDeploymentType,
        tab: props.activeEditorTab,
    },
});

const buildShowActionQueryOptions = () => ({
    query: {
        deployment: props.activeDeploymentType,
        tab: props.activeEditorTab,
        origin: 'show',
    },
});

const buildShowUrl = (): string => {
    return flowShow({ flow: props.flow.id ?? 0 }, buildShowQueryOptions()).url;
};

const buildEditorUrl = (): string => {
    return flowEditor({ flow: props.flow.id ?? 0 }, buildShowQueryOptions())
        .url;
};

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
    {
        title: t('flows.breadcrumbs.flow', { id: props.flow.id }),
        href: buildShowUrl(),
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
        case 'creating':
        case 'created':
        case 'stopping':
            return 'border-sky-500/40 bg-sky-500/10 text-sky-300';
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

const save = (): void => {
    if (!canSave.value) {
        return;
    }

    form.put(
        flowUpdate({ flow: props.flow.id ?? 0 }, buildShowActionQueryOptions())
            .url,
        {
            preserveScroll: true,
        },
    );
};

const archiveFlow = (): void => {
    if (!props.permissions.canUpdate || actionInProgress.value !== null) {
        return;
    }

    actionInProgress.value = 'archive';

    router.post(
        flowArchive({ flow: props.flow.id ?? 0 }, buildShowActionQueryOptions())
            .url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actionInProgress.value = null;
            },
        },
    );
};

const restoreFlow = (): void => {
    if (!props.permissions.canUpdate || actionInProgress.value !== null) {
        return;
    }

    actionInProgress.value = 'restore';

    router.post(
        flowRestore({ flow: props.flow.id ?? 0 }, buildShowActionQueryOptions())
            .url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actionInProgress.value = null;
            },
        },
    );
};

const deleteFlow = (): void => {
    if (!props.permissions.canDelete) {
        return;
    }

    if (!confirm(t('flows.delete.confirm'))) {
        return;
    }

    router.delete(flowDestroy({ flow: props.flow.id ?? 0 }).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-400 divide-y pt-3 pb-8">
            <FlowEditorHeader
                :name="form.name"
                :description="form.description"
                :can-run="props.permissions.canRun"
                :editor-url="buildEditorUrl()"
            />

            <FlowEditorSummary
                :flow-runs-count="props.flow.runs_count"
                :last-started-at="props.flow.last_started_at"
                :last-finished-at="props.flow.last_finished_at"
                :has-current-deployment="Boolean(selectedDeployment)"
                :current-deployment-label="currentDeploymentLabel"
                :current-deployment-status="selectedDeployment?.status ?? null"
                :current-deployment-started-at="selectedDeployment?.started_at"
                :current-deployment-finished-at="
                    selectedDeployment?.finished_at
                "
                :current-deployment-events-count="
                    selectedDeployment?.events?.length ?? 0
                "
                :current-deployment-logs-count="currentDeploymentLogsCount"
                :run-stats="props.runStats"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :format-recent-date="formatRecentDate"
                :format-duration="formatDuration"
            />

            <FlowEditorDeployments
                v-if="deploymentCards.length"
                :flow-id="props.flow.id ?? 0"
                :deployment-cards="deploymentCards"
                :all-deployments-url="allDeploymentsUrl"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :run-type-label="runTypeLabel"
                :format-date="formatDate"
                :format-duration="formatDuration"
            />

            <FlowPastChatsPanel
                v-if="props.pastChats.length"
                :flow-id="props.flow.id ?? 0"
                :chats="props.pastChats"
                :all-chats-url="props.allChatsUrl"
                :format-date="formatDate"
                :format-recent-date="formatRecentDate"
            />

            <FlowEditorSettings
                v-if="props.permissions.canUpdate"
                v-model:name="form.name"
                v-model:description="form.description"
                v-model:timezone="form.timezone"
                :processing="form.processing"
                :can-save="canSave"
                :is-archived="isArchived"
                :can-update="props.permissions.canUpdate"
                :can-delete="props.permissions.canDelete"
                :has-active-deploys="hasActiveDeploys"
                :action-in-progress="actionInProgress"
                :timezone-options="props.timezoneOptions"
                :name-error="form.errors.name"
                :timezone-error="form.errors.timezone"
                @save="save"
                @archive="archiveFlow"
                @restore="restoreFlow"
                @delete="deleteFlow"
            />
        </div>
    </AppLayout>
</template>
