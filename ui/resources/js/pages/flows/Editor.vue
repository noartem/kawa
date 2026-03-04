<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as flowShow, index as flowsIndex } from '@/routes/flows';
import type { BreadcrumbItem, FlowSidebarItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    AlarmClock,
    Archive,
    Boxes,
    Code,
    ExternalLink,
    History,
    Play,
    Save,
    Share2,
    Square,
    Trash2,
    UploadCloud,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowLog {
    id: number;
    level?: string | null;
    message?: string | null;
    node_key?: string | null;
    created_at: string;
}

interface FlowRun {
    id: number;
    type: 'development' | 'production';
    active: boolean;
    status?: string | null;
    lock?: string | null;
    actors?: string[] | null;
    events?: string[] | null;
    started_at?: string | null;
    finished_at?: string | null;
    created_at?: string | null;
}

interface FlowHistory {
    id: number;
    code?: string | null;
    diff?: string | null;
    created_at: string;
}

interface FlowDetail extends Omit<FlowSidebarItem, 'id' | 'slug'> {
    id?: number | null;
    slug?: string | null;
    description?: string | null;
    code?: string | null;
    code_updated_at?: string | null;
    graph?: Record<string, unknown>;
    graph_generated_at?: string | null;
    runs_count?: number;
    container_id?: string | null;
    entrypoint?: string | null;
    image?: string | null;
    last_started_at?: string | null;
    last_finished_at?: string | null;
    archived_at?: string | null;
    user?: {
        name?: string | null;
    };
}

interface Permissions {
    canRun: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}

interface RunStat {
    status: string;
    total: number;
}

const props = defineProps<{
    flow: FlowDetail;
    productionRun: FlowRun | null;
    developmentRun: FlowRun | null;
    productionRuns: FlowRun[];
    developmentRuns: FlowRun[];
    productionLogs: FlowLog[];
    developmentLogs: FlowLog[];
    status?: string | null;
    runStats: RunStat[];
    history: FlowHistory[];
    permissions: Permissions;
    viewMode: 'development' | 'production';
    requiresDeletePassword: boolean;
}>();

const { t } = useI18n();

const saving = ref(false);
const emptyGraph = { nodes: [], edges: [] };

const normalizeGraphText = (graph?: Record<string, unknown>) =>
    JSON.stringify(graph ?? emptyGraph, null, 2);

const form = useForm({
    name: props.flow.name,
    description: props.flow.description || '',
    code: props.flow.code || '',
});
const graphText = ref(normalizeGraphText(props.flow.graph));
const editorTab = ref<'code' | 'chat'>('code');

watch(
    () => props.flow.graph,
    (nextGraph) => {
        graphText.value = normalizeGraphText(nextGraph);
    },
    { immediate: true },
);

const statusTone = (status?: string | null) => {
    switch (status) {
        case 'running':
        case 'ready':
        case 'locked':
            return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
        case 'error':
        case 'failed':
        case 'lock_failed':
            return 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30';
        case 'stopped':
        case 'success':
            return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
        case 'locking':
            return 'bg-blue-500/15 text-blue-300 ring-1 ring-blue-500/30';
        default:
            return 'bg-muted text-muted-foreground ring-1 ring-border';
    }
};

const saveLabel = computed(() => t('flows.actions.save'));
const canSave = computed(() => props.permissions.canUpdate);
const actionInProgress = ref<
    'run' | 'stop' | 'deploy' | 'undeploy' | 'archive' | 'restore' | null
>(null);
const refreshInFlight = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

const refreshOnlyProps = [
    'flow',
    'productionRun',
    'developmentRun',
    'productionRuns',
    'developmentRuns',
    'productionLogs',
    'developmentLogs',
    'runStats',
    'recentFlows',
    'flash',
] as const;

const refreshFlowView = () => {
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

const startRefreshPolling = () => {
    if (refreshTimer !== null) {
        return;
    }

    refreshTimer = setInterval(() => {
        refreshFlowView();
    }, 3000);
};

const stopRefreshPolling = () => {
    if (refreshTimer === null) {
        return;
    }

    clearInterval(refreshTimer);
    refreshTimer = null;
};

const submitAction = (
    action: NonNullable<typeof actionInProgress.value>,
    url: string,
) => {
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

onBeforeUnmount(() => {
    stopRefreshPolling();
});

const save = () => {
    if (!canSave.value) return;
    saving.value = true;

    return form.put(`/flows/${props.flow.id}`, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
        },
    });
};

const hasUnsavedFlowChanges = computed(() => {
    const flowName = props.flow.name || '';
    const flowDescription = props.flow.description || '';
    const flowCode = props.flow.code || '';

    return (
        form.name !== flowName ||
        form.description !== flowDescription ||
        form.code !== flowCode
    );
});

const saveBeforeAction = (onSuccess: () => void) => {
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

const runFlow = () => {
    if (!props.permissions.canRun || form.processing || saving.value) return;
    saveBeforeAction(() => {
        submitAction('run', `/flows/${props.flow.id}/run`);
    });
};

const stopFlow = () => {
    if (!props.permissions.canRun) return;
    submitAction('stop', `/flows/${props.flow.id}/stop`);
};

const deployProd = () => {
    if (!props.permissions.canRun || form.processing || saving.value) return;
    saveBeforeAction(() => {
        submitAction('deploy', `/flows/${props.flow.id}/deploy`);
    });
};

const undeployProd = () => {
    if (!props.permissions.canRun) return;
    submitAction('undeploy', `/flows/${props.flow.id}/undeploy`);
};

const archiveFlow = () => {
    if (!props.permissions.canUpdate) return;
    submitAction('archive', `/flows/${props.flow.id}/archive`);
};

const restoreFlow = () => {
    if (!props.permissions.canUpdate) return;
    submitAction('restore', `/flows/${props.flow.id}/restore`);
};

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

const pageTitle = computed(() => form.name || t('flows.untitled'));

const deleteFlow = () => {
    if (!props.permissions.canDelete) return;

    if (!confirm(t('flows.delete.confirm'))) {
        return;
    }

    const password = props.requiresDeletePassword
        ? prompt(t('flows.delete.password_prompt'))
        : null;
    if (props.requiresDeletePassword && !password) {
        return;
    }

    router.delete(`/flows/${props.flow.id}`, {
        data: password ? { password } : {},
        preserveScroll: true,
    });
};

const formatDate = (value?: string | null) => {
    if (!value) return t('common.empty');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const relativeTimeFormatter = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});
const recentWindowMs = 24 * 60 * 60 * 1000;

const parseDateMs = (value?: string | null): number | null => {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date.getTime();
};

const formatRelativeFromNow = (dateMs: number): string => {
    const seconds = Math.round((dateMs - Date.now()) / 1000);
    const absSeconds = Math.abs(seconds);

    if (absSeconds < 60) {
        return relativeTimeFormatter.format(seconds, 'second');
    }

    const minutes = Math.round(seconds / 60);
    const absMinutes = Math.abs(minutes);

    if (absMinutes < 60) {
        return relativeTimeFormatter.format(minutes, 'minute');
    }

    const hours = Math.round(minutes / 60);
    const absHours = Math.abs(hours);

    if (absHours < 24) {
        return relativeTimeFormatter.format(hours, 'hour');
    }

    const days = Math.round(hours / 24);

    return relativeTimeFormatter.format(days, 'day');
};

const formatRecentDate = (value?: string | null): string => {
    if (!value) {
        return t('common.empty');
    }

    const dateMs = parseDateMs(value);

    if (dateMs === null) {
        return value;
    }

    if (Math.abs(Date.now() - dateMs) <= recentWindowMs) {
        return formatRelativeFromNow(dateMs);
    }

    return new Date(dateMs).toLocaleString();
};

const formatDuration = (start?: string | null, end?: string | null) => {
    if (!start) return t('common.empty');
    const startDate = new Date(start);
    const endDate = end ? new Date(end) : new Date();
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime()))
        return t('common.empty');
    const diffMs = Math.max(endDate.getTime() - startDate.getTime(), 0);
    const seconds = Math.floor(diffMs / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    if (hours > 0)
        return t('common.duration.hours', { hours, minutes: minutes % 60 });
    if (minutes > 0)
        return t('common.duration.minutes', { minutes, seconds: seconds % 60 });
    return t('common.duration.seconds', { seconds });
};

const hasActiveDeploys = computed(() =>
    Boolean(props.productionRun?.active || props.developmentRun?.active),
);
const graphOutdated = computed(() => {
    const codeUpdatedAt = parseDateMs(props.flow.code_updated_at);

    if (codeUpdatedAt === null) {
        return false;
    }

    const graphGeneratedAt = parseDateMs(props.flow.graph_generated_at);

    if (graphGeneratedAt === null) {
        return true;
    }

    return graphGeneratedAt < codeUpdatedAt;
});
const shouldPollForUpdates = computed(
    () =>
        (hasActiveDeploys.value || graphOutdated.value) &&
        actionInProgress.value === null,
);
const currentProduction = computed(() => props.productionRun);
const currentDevelopment = computed(() => props.developmentRun);
const isArchived = computed(() => Boolean(props.flow.archived_at));
const statusLabel = (status?: string | null) =>
    t(`statuses.${status ?? 'unknown'}`);
const runTypeLabel = (type?: FlowRun['type'] | null) =>
    type === 'production'
        ? t('environments.production')
        : t('environments.development');

watch(
    shouldPollForUpdates,
    (shouldPoll) => {
        if (shouldPoll) {
            startRefreshPolling();

            return;
        }

        stopRefreshPolling();
    },
    { immediate: true },
);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="pt-4 pb-12">
            <section class="space-y-6 px-4 pb-6">
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="space-y-2">
                        <h1 class="pt-1 text-3xl leading-tight font-semibold">
                            {{ form.name || t('flows.untitled') }}
                        </h1>
                        <p class="max-w-2xl text-sm text-muted-foreground">
                            {{
                                form.description ||
                                t('flows.description.placeholder')
                            }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="currentProduction?.active"
                            variant="outline"
                            :disabled="
                                !permissions.canRun || actionInProgress !== null
                            "
                            @click="undeployProd"
                        >
                            <Square class="size-4" />
                            {{ t('actions.stop') }}
                        </Button>
                        <Button
                            v-else
                            :disabled="
                                !permissions.canRun || actionInProgress !== null
                            "
                            @click="deployProd"
                        >
                            <UploadCloud class="size-4" />
                            {{ t('actions.deploy') }}
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="!permissions.canRun"
                        >
                            <Share2 class="size-4" />
                            {{ t('actions.share') }}
                        </Button>
                    </div>
                </div>
            </section>

            <Separator />

            <div class="grid gap-4 px-4 pt-6 pb-6 xl:grid-cols-[2fr,1fr]">
                <section class="space-y-4">
                    <div class="space-y-1">
                        <h2
                            class="flex items-center gap-2 text-lg font-semibold"
                        >
                            <UploadCloud class="size-4 text-muted-foreground" />
                            {{ t('flows.current_deploy.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ t('flows.current_deploy.description') }}
                        </p>
                    </div>
                    <div class="space-y-4">
                        <div v-if="currentProduction" class="space-y-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    :class="
                                        statusTone(currentProduction.status)
                                    "
                                    variant="outline"
                                >
                                    {{ statusLabel(currentProduction.status) }}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    class="bg-muted/60 text-muted-foreground"
                                >
                                    {{ runTypeLabel(currentProduction.type) }}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    class="bg-muted/60 text-muted-foreground"
                                >
                                    {{ t('common.started') }}:
                                    {{
                                        formatDate(currentProduction.started_at)
                                    }}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    class="bg-muted/60 text-muted-foreground"
                                >
                                    {{ t('common.finished') }}:
                                    {{
                                        formatDate(
                                            currentProduction.finished_at,
                                        )
                                    }}
                                </Badge>
                            </div>
                            <div class="grid gap-3 md:grid-cols-3">
                                <div class="py-3">
                                    <div
                                        class="flex items-center justify-between text-xs text-muted-foreground"
                                    >
                                        <span>{{
                                            t('flows.metrics.actors')
                                        }}</span>
                                        <Activity class="size-4" />
                                    </div>
                                    <p class="mt-2 text-sm">
                                        {{
                                            currentProduction.actors?.length
                                                ? currentProduction.actors.join(
                                                      ', ',
                                                  )
                                                : t('common.empty')
                                        }}
                                    </p>
                                </div>
                                <div class="py-3">
                                    <div
                                        class="flex items-center justify-between text-xs text-muted-foreground"
                                    >
                                        <span>{{
                                            t('flows.metrics.events')
                                        }}</span>
                                        <Boxes class="size-4" />
                                    </div>
                                    <p class="mt-2 text-sm">
                                        {{
                                            currentProduction.events?.length
                                                ? currentProduction.events.join(
                                                      ', ',
                                                  )
                                                : t('common.empty')
                                        }}
                                    </p>
                                </div>
                                <div class="py-3">
                                    <div
                                        class="flex items-center justify-between text-xs text-muted-foreground"
                                    >
                                        <span>{{
                                            t('flows.metrics.duration')
                                        }}</span>
                                        <AlarmClock class="size-4" />
                                    </div>
                                    <p class="mt-2 text-sm">
                                        {{
                                            formatDuration(
                                                currentProduction.started_at,
                                                currentProduction.finished_at,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <FlowLogsPanel
                                :title="t('common.logs')"
                                :logs="productionLogs"
                                :empty-message="t('flows.logs.empty_current')"
                            />
                        </div>
                        <div v-else class="py-1 text-sm text-muted-foreground">
                            {{ t('flows.current_deploy.empty') }}
                        </div>
                    </div>
                </section>

                <div class="space-y-4">
                    <section class="space-y-3">
                        <div class="space-y-1">
                            <h2 class="text-lg font-semibold">
                                {{ t('flows.summary.title') }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ t('flows.summary.description') }}
                            </p>
                        </div>
                        <div class="space-y-2 text-sm text-muted-foreground">
                            <div class="flex items-center justify-between">
                                <span
                                    class="inline-flex items-center gap-2 text-foreground"
                                >
                                    <Activity
                                        class="size-4 text-muted-foreground"
                                    />
                                    {{ t('flows.summary.runs') }}
                                </span>
                                <span class="font-semibold text-foreground">{{
                                    props.flow.runs_count ?? 0
                                }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span
                                    class="inline-flex items-center gap-2 text-foreground"
                                >
                                    <AlarmClock
                                        class="size-4 text-muted-foreground"
                                    />
                                    {{ t('flows.summary.last_start') }}
                                </span>
                                <span class="font-semibold text-foreground">{{
                                    formatDate(props.flow.last_started_at)
                                }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span
                                    class="inline-flex items-center gap-2 text-foreground"
                                >
                                    <Square
                                        class="size-4 text-muted-foreground"
                                    />
                                    {{ t('flows.summary.last_finish') }}
                                </span>
                                <span class="font-semibold text-foreground">{{
                                    formatDate(props.flow.last_finished_at)
                                }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Badge
                                    v-for="stat in props.runStats"
                                    :key="stat.status"
                                    variant="outline"
                                    class="bg-muted/50 text-muted-foreground"
                                >
                                    {{ statusLabel(stat.status) }} •
                                    {{ stat.total }}
                                </Badge>
                                <span
                                    v-if="!props.runStats.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ t('flows.summary.empty_runs') }}
                                </span>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <Separator />

            <div class="grid gap-4 px-4 pt-6 pb-6 xl:grid-cols-[1.3fr,1fr]">
                <section v-if="permissions.canUpdate" class="space-y-4">
                    <div class="space-y-1">
                        <h2
                            class="flex items-center gap-2 text-lg font-semibold"
                        >
                            <Code class="size-4 text-muted-foreground" />
                            {{ t('flows.editor.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ t('flows.editor.description') }}
                        </p>
                    </div>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                :disabled="
                                    !permissions.canRun ||
                                    actionInProgress !== null
                                "
                                @click="stopFlow"
                            >
                                <Square class="size-4" />
                                {{ t('actions.stop') }}
                            </Button>
                            <Button
                                :disabled="
                                    !permissions.canRun ||
                                    actionInProgress !== null
                                "
                                @click="runFlow"
                            >
                                <Play class="size-4" />
                                {{ t('actions.start') }}
                            </Button>
                            <Button
                                variant="outline"
                                :disabled="!permissions.canRun"
                            >
                                <Share2 class="size-4" />
                                {{ t('actions.share') }}
                            </Button>
                            <Badge
                                v-if="currentDevelopment"
                                :class="statusTone(currentDevelopment.status)"
                                variant="outline"
                            >
                                {{ statusLabel(currentDevelopment.status) }}
                            </Badge>
                        </div>
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div
                                class="inline-flex items-center gap-1 rounded-lg bg-muted/50 p-1"
                            >
                                <button
                                    type="button"
                                    class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm transition"
                                    :class="
                                        editorTab === 'code'
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    @click="editorTab = 'code'"
                                >
                                    <Code class="size-4" />
                                    {{ t('flows.editor.tabs.code') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm transition"
                                    :class="
                                        editorTab === 'chat'
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    @click="editorTab = 'chat'"
                                >
                                    <Share2 class="size-4" />
                                    {{ t('flows.editor.tabs.chat') }}
                                </button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ t('flows.editor.tabs.hint') }}
                            </p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-[2fr,1fr]">
                            <div class="space-y-4">
                                <template v-if="editorTab === 'code'">
                                    <div class="space-y-2">
                                        <Label for="flow-code">{{
                                            t('flows.editor.code_label')
                                        }}</Label>
                                        <Textarea
                                            id="flow-code"
                                            v-model="form.code"
                                            class="font-mono"
                                            rows="16"
                                        />
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Code saved:
                                            <span
                                                :title="
                                                    formatDate(
                                                        props.flow
                                                            .code_updated_at,
                                                    )
                                                "
                                            >
                                                {{
                                                    formatRecentDate(
                                                        props.flow
                                                            .code_updated_at,
                                                    )
                                                }}
                                            </span>
                                        </p>
                                        <p
                                            v-if="form.errors.code"
                                            class="text-sm text-destructive"
                                        >
                                            {{ form.errors.code }}
                                        </p>
                                    </div>
                                    <div class="space-y-2">
                                        <Label for="flow-graph">{{
                                            t('flows.editor.graph_label')
                                        }}</Label>
                                        <Textarea
                                            id="flow-graph"
                                            v-model="graphText"
                                            readonly
                                            :class="[
                                                'font-mono text-xs transition-colors',
                                                graphOutdated
                                                    ? 'border-muted-foreground/40 bg-muted/60 text-muted-foreground'
                                                    : '',
                                            ]"
                                            rows="10"
                                        />
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Graph generated:
                                            <span
                                                :title="
                                                    formatDate(
                                                        props.flow
                                                            .graph_generated_at,
                                                    )
                                                "
                                            >
                                                {{
                                                    formatRecentDate(
                                                        props.flow
                                                            .graph_generated_at,
                                                    )
                                                }}
                                            </span>
                                        </p>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="py-4">
                                        <p
                                            class="text-sm font-semibold text-foreground"
                                        >
                                            {{ t('flows.editor.chat.title') }}
                                        </p>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{
                                                t('flows.editor.chat.subtitle')
                                            }}
                                        </p>
                                        <div
                                            class="mt-3 space-y-2 text-sm text-muted-foreground"
                                        >
                                            <p>
                                                {{
                                                    t(
                                                        'flows.editor.chat.example_question',
                                                    )
                                                }}
                                            </p>
                                            <p>
                                                {{
                                                    t(
                                                        'flows.editor.chat.example_answer',
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <div class="space-y-4">
                                <div class="py-3">
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('flows.dev_deploy.title') }}
                                    </p>
                                    <p class="mt-2 text-sm">
                                        {{
                                            currentDevelopment?.status
                                                ? statusLabel(
                                                      currentDevelopment.status,
                                                  )
                                                : t('flows.dev_deploy.empty')
                                        }}
                                    </p>
                                    <p
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        {{ t('common.started') }}:
                                        {{
                                            formatDate(
                                                currentDevelopment?.started_at,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ t('common.finished') }}:
                                        {{
                                            formatDate(
                                                currentDevelopment?.finished_at,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <FlowLogsPanel
                            :title="t('common.logs')"
                            :logs="developmentLogs"
                            :empty-message="t('flows.logs.empty_dev')"
                            compact
                        />
                    </div>
                </section>
                <section v-else class="space-y-3">
                    <h2 class="flex items-center gap-2 text-lg font-semibold">
                        <Code class="size-4 text-muted-foreground" />
                        {{ t('flows.editor.readonly.title') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ t('flows.editor.readonly.description') }}
                    </p>
                    <div class="space-y-2 text-sm text-muted-foreground">
                        <p>{{ t('flows.editor.readonly.note_edit') }}</p>
                        <p>{{ t('flows.editor.readonly.note_production') }}</p>
                    </div>
                </section>

                <div class="space-y-4">
                    <section class="space-y-3">
                        <div class="space-y-1">
                            <h2 class="text-lg font-semibold">
                                {{ t('flows.past_deploys.title') }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ t('flows.past_deploys.description') }}
                            </p>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <p
                                    class="text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ t('environments.production') }}
                                </p>
                                <div
                                    v-if="productionRuns.length"
                                    class="mt-2 space-y-2"
                                >
                                    <div
                                        v-for="run in productionRuns"
                                        :key="run.id"
                                        class="py-3"
                                    >
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span
                                                class="text-sm font-semibold"
                                                >{{
                                                    t('flows.deploy.label', {
                                                        id: run.id,
                                                    })
                                                }}</span
                                            >
                                            <Badge
                                                :class="statusTone(run.status)"
                                                variant="outline"
                                                >{{
                                                    statusLabel(run.status)
                                                }}</Badge
                                            >
                                        </div>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatDate(run.started_at) }} →
                                            {{ formatDate(run.finished_at) }}
                                        </p>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="mt-2 text-sm text-muted-foreground"
                                >
                                    {{
                                        t('flows.past_deploys.empty_production')
                                    }}
                                </div>
                            </div>
                            <div>
                                <p
                                    class="text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ t('environments.development') }}
                                </p>
                                <div
                                    v-if="developmentRuns.length"
                                    class="mt-2 space-y-2"
                                >
                                    <div
                                        v-for="run in developmentRuns"
                                        :key="run.id"
                                        class="py-3"
                                    >
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span
                                                class="text-sm font-semibold"
                                                >{{
                                                    t('flows.deploy.label', {
                                                        id: run.id,
                                                    })
                                                }}</span
                                            >
                                            <Badge
                                                :class="statusTone(run.status)"
                                                variant="outline"
                                                >{{
                                                    statusLabel(run.status)
                                                }}</Badge
                                            >
                                        </div>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatDate(run.started_at) }} →
                                            {{ formatDate(run.finished_at) }}
                                        </p>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="mt-2 text-sm text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'flows.past_deploys.empty_development',
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-3">
                        <div class="space-y-1">
                            <h2 class="text-lg font-semibold">
                                {{ t('flows.past_chats.title') }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ t('flows.past_chats.description') }}
                            </p>
                        </div>
                        <div>
                            <div class="space-y-3">
                                <div class="py-3">
                                    <div
                                        class="flex items-center justify-between text-xs text-muted-foreground"
                                    >
                                        <span>{{ t('common.today') }}</span>
                                        <ExternalLink class="size-4" />
                                    </div>
                                    <p class="mt-2 text-sm">
                                        {{
                                            t('flows.past_chats.example_today')
                                        }}
                                    </p>
                                </div>
                                <div class="py-3">
                                    <div
                                        class="flex items-center justify-between text-xs text-muted-foreground"
                                    >
                                        <span>{{ t('common.yesterday') }}</span>
                                        <ExternalLink class="size-4" />
                                    </div>
                                    <p class="mt-2 text-sm">
                                        {{
                                            t(
                                                'flows.past_chats.example_yesterday',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <Separator />

            <section v-if="history.length" class="space-y-3 px-4 pt-6 pb-6">
                <h2 class="text-lg font-semibold">
                    {{ t('flows.history.title') }}
                </h2>
                <div>
                    <div class="max-h-72 space-y-3 overflow-y-auto pr-1">
                        <div
                            v-for="item in history"
                            :key="item.id"
                            class="py-3"
                        >
                            <div
                                class="flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <History class="size-4" />
                                    {{
                                        t('flows.history.version', {
                                            id: item.id,
                                        })
                                    }}
                                </span>
                                <span>{{ formatDate(item.created_at) }}</span>
                            </div>
                            <pre
                                class="mt-2 max-h-28 overflow-auto rounded-md bg-background px-3 py-2 text-xs text-muted-foreground"
                                >{{
                                    item.diff || t('flows.history.empty_diff')
                                }}</pre
                            >
                        </div>
                    </div>
                </div>
            </section>

            <Separator />

            <section class="space-y-4 px-4 pt-6">
                <h2 class="text-lg font-semibold">
                    {{ t('flows.settings.title') }}
                </h2>
                <div class="space-y-4">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="flow-name">{{
                                t('flows.settings.name')
                            }}</Label>
                            <Input
                                id="flow-name"
                                v-model="form.name"
                                required
                                :placeholder="
                                    t('flows.settings.name_placeholder')
                                "
                            />
                            <p
                                v-if="form.errors.name"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="flow-description">{{
                                t('flows.settings.description')
                            }}</Label>
                            <Textarea
                                id="flow-description"
                                v-model="form.description"
                                :placeholder="
                                    t('flows.settings.description_placeholder')
                                "
                                class="min-h-[90px]"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            :disabled="form.processing || !canSave"
                            @click="save"
                        >
                            <Save class="size-4" />
                            {{ saveLabel }}
                        </Button>
                        <Button
                            v-if="!isArchived"
                            variant="outline"
                            :disabled="
                                !permissions.canUpdate ||
                                hasActiveDeploys ||
                                actionInProgress !== null
                            "
                            @click="archiveFlow"
                        >
                            <Archive class="size-4" />
                            {{ t('actions.archive') }}
                        </Button>
                        <Button
                            v-else
                            variant="outline"
                            :disabled="
                                !permissions.canUpdate ||
                                actionInProgress !== null
                            "
                            @click="restoreFlow"
                        >
                            <Archive class="size-4" />
                            {{ t('actions.restore') }}
                        </Button>
                        <Button
                            variant="outline"
                            class="text-destructive"
                            :disabled="
                                !permissions.canDelete || hasActiveDeploys
                            "
                            @click="deleteFlow"
                        >
                            <Trash2 class="size-4" />
                            {{ t('actions.delete') }}
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            {{ t('flows.settings.delete_hint') }}
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
