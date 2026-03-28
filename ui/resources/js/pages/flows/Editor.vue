<script setup lang="ts">
import FlowEditorDeployments from '@/components/flows/editor/FlowEditorDeployments.vue';
import FlowEditorHeader from '@/components/flows/editor/FlowEditorHeader.vue';
import FlowEditorSettings from '@/components/flows/editor/FlowEditorSettings.vue';
import FlowEditorSummary from '@/components/flows/editor/FlowEditorSummary.vue';
import FlowEditorWorkspace from '@/components/flows/editor/FlowEditorWorkspace.vue';
import FlowPastChatsPanel from '@/components/flows/editor/FlowPastChatsPanel.vue';
import type {
    DeploymentCard,
    FlowChatConversation,
    FlowChatMessage,
    FlowDeployment,
    FlowDetail,
    FlowHistory,
    FlowLog,
    FlowRun,
    FlowWebhookEndpoint,
    Permissions,
    RunStat,
} from '@/components/flows/editor/types';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    archive as flowArchive,
    deploy as flowDeploy,
    deployments as flowDeployments,
    destroy as flowDestroy,
    restore as flowRestore,
    run as flowRun,
    show as flowShow,
    index as flowsIndex,
    stop as flowStop,
    undeploy as flowUndeploy,
    update as flowUpdate,
} from '@/routes/flows';
import {
    compact as flowChatCompact,
    index as flowChatIndex,
    newMethod as flowChatNew,
    store as flowChatStore,
} from '@/routes/flows/chat';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flow: FlowDetail;
    productionRun: FlowRun | null;
    lastDevelopmentDeployment: FlowDeployment | null;
    webhookEndpoints: FlowWebhookEndpoint[];
    productionLogsCount: number;
    deployments?: FlowDeployment[];
    status?: string | null;
    runStats: RunStat[];
    history: FlowHistory[];
    activeChat: FlowChatConversation | null;
    pastChats: FlowChatConversation[];
    timezoneOptions: string[];
    permissions: Permissions;
}>();

const { t, locale } = useI18n();

const actionInProgress = ref<
    'run' | 'stop' | 'deploy' | 'undeploy' | 'archive' | 'restore' | null
>(null);
const saving = ref(false);
const refreshInFlight = ref(false);
const chatPending = ref(false);
const chatDraft = ref('');
const chatError = ref<string | null>(null);
const optimisticUserMessage = ref<FlowChatMessage | null>(null);
const failedChatRequest = ref<{
    message: string;
    currentCode: string;
} | null>(null);
const activeChat = ref<FlowChatConversation | null>(props.activeChat);
const pastChats = ref<FlowChatConversation[]>(props.pastChats ?? []);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

const form = useForm({
    name: props.flow.name,
    description: props.flow.description || '',
    code: props.flow.code || '',
    timezone: props.flow.timezone || 'UTC',
});

const buildHistorySnapshotSignature = (history: FlowHistory[]): string => {
    return history
        .map((historyItem) => `${historyItem.id}:${historyItem.created_at}`)
        .join('|');
};

const stableHistory = shallowRef<FlowHistory[]>(props.history);
const stableHistorySignature = ref(
    buildHistorySnapshotSignature(props.history),
);

const refreshOnlyProps = [
    'flow',
    'productionRun',
    'productionLogsCount',
    'lastDevelopmentDeployment',
    'webhookEndpoints',
    'deployments',
    'runStats',
    'history',
    'recentFlows',
    'flash',
] as const;

const currentProduction = computed(() => props.productionRun);
const currentDevelopment = computed(() => props.lastDevelopmentDeployment);
const deployments = computed(() => props.deployments ?? []);
const displayGraph = computed<Record<string, unknown> | null>(() => {
    return currentDevelopment.value?.graph ?? null;
});
const displayDevelopmentStatus = computed(() => {
    return currentDevelopment.value?.status ?? null;
});
const displayDevelopmentLogs = computed<FlowLog[]>(() => {
    return currentDevelopment.value?.logs ?? [];
});
const displayedChatMessages = computed<FlowChatMessage[]>(() => {
    const baseMessages = activeChat.value?.messages ?? [];
    const messages = optimisticUserMessage.value
        ? [...baseMessages, optimisticUserMessage.value]
        : [...baseMessages];

    if (chatPending.value) {
        return [
            ...messages,
            {
                id: 'ui-pending-message',
                role: 'assistant',
                content: '',
                created_at: null,
                kind: null,
                status: 'pending',
                transient: true,
                retryable: false,
                source_code: null,
                proposed_code: null,
                diff: null,
                has_code_changes: false,
            },
        ];
    }

    if (chatError.value) {
        return [
            ...messages,
            {
                id: 'ui-error-message',
                role: 'assistant',
                content: chatError.value,
                created_at: null,
                kind: null,
                status: 'error',
                transient: true,
                retryable: failedChatRequest.value !== null,
                source_code: null,
                proposed_code: null,
                diff: null,
                has_code_changes: false,
            },
        ];
    }

    return messages;
});
const recentDeployments = computed(() => deployments.value.slice(0, 5));
const allDeploymentsUrl = computed(() => {
    return flowDeployments({ flow: props.flow.id ?? 0 }).url;
});
const allChatsUrl = computed(() => {
    return flowChatIndex({ flow: props.flow.id ?? 0 }).url;
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
        form.code !== (props.flow.code || '') ||
        form.timezone !== (props.flow.timezone || 'UTC')
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

const latestDevelopmentSnapshotAt = computed(() => {
    return (
        currentDevelopment.value?.updated_at ??
        currentDevelopment.value?.finished_at ??
        currentDevelopment.value?.started_at ??
        currentDevelopment.value?.created_at ??
        null
    );
});

const graphIsOutdated = computed(() => {
    const codeUpdated = parseDateMs(props.flow.code_updated_at);
    const graphGenerated = parseDateMs(latestDevelopmentSnapshotAt.value);

    return (
        codeUpdated !== null &&
        graphGenerated !== null &&
        graphGenerated < codeUpdated
    );
});

const graphMeta = computed(() => {
    return {
        actors: countGraphNodesByType(displayGraph.value, 'actor'),
        events: countGraphNodesByType(displayGraph.value, 'event'),
        status: statusLabel(displayDevelopmentStatus.value),
        freshnessLabel: graphIsOutdated.value
            ? t('common.outdated')
            : t('common.updated_at'),
        updatedAt: formatRecentDate(latestDevelopmentSnapshotAt.value),
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
    () => props.activeChat,
    (nextChat) => {
        activeChat.value = nextChat;
    },
    { deep: true },
);

watch(
    () => props.pastChats,
    (nextChats) => {
        pastChats.value = nextChats;
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

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');

const extractChatError = async (response: Response): Promise<string> => {
    try {
        const payload = (await response.json()) as {
            code?: string;
            message?: string;
            errors?: Record<string, string[]>;
        };

        const firstError = Object.values(payload.errors ?? {})[0]?.[0];

        const knownErrorMessages: Record<string, string> = {
            ai_provider_unavailable: t(
                'flows.editor.chat.provider_unavailable',
            ),
            ai_rate_limited: t('flows.editor.chat.rate_limited'),
            ai_insufficient_credits: t(
                'flows.editor.chat.insufficient_credits',
            ),
        };

        if (payload.code && knownErrorMessages[payload.code]) {
            return knownErrorMessages[payload.code];
        }

        if (
            response.status === 503 ||
            payload.message?.includes('OpenAI Error [503]')
        ) {
            return t('flows.editor.chat.provider_unavailable');
        }

        if (response.status === 429) {
            return t('flows.editor.chat.rate_limited');
        }

        return (
            firstError ||
            payload.message ||
            t('flows.editor.chat.error_fallback')
        );
    } catch {
        return t('flows.editor.chat.error_fallback');
    }
};

const postChatJson = async <T,>(
    url: string,
    payload: Record<string, unknown>,
): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(await extractChatError(response));
    }

    return response.json() as Promise<T>;
};

const syncChatState = (payload: {
    activeChat: FlowChatConversation | null;
    pastChats: FlowChatConversation[];
}): void => {
    activeChat.value = payload.activeChat;
    pastChats.value = payload.pastChats;
    chatError.value = null;
    optimisticUserMessage.value = null;
    failedChatRequest.value = null;
};

const createOptimisticUserMessage = (message: string): FlowChatMessage => {
    return {
        id: `ui-user-message-${Date.now()}`,
        role: 'user',
        content: message,
        created_at: new Date().toISOString(),
        kind: 'prompt',
        status: null,
        transient: true,
        retryable: false,
        source_code: null,
        proposed_code: null,
        diff: null,
        has_code_changes: false,
    };
};

const submitChatMessageRequest = async (request: {
    message: string;
    currentCode: string;
}): Promise<void> => {
    chatError.value = null;
    chatPending.value = true;
    failedChatRequest.value = request;

    try {
        const payload = await postChatJson<{
            activeChat: FlowChatConversation | null;
            pastChats: FlowChatConversation[];
        }>(flowChatStore({ flow: props.flow.id ?? 0 }).url, {
            message: request.message,
            current_code: request.currentCode,
        });

        syncChatState(payload);
    } catch (error) {
        chatError.value =
            error instanceof Error
                ? error.message
                : t('flows.editor.chat.error_fallback');
    } finally {
        chatPending.value = false;
    }
};

const sendChatMessage = async (): Promise<void> => {
    if (
        chatPending.value ||
        !props.permissions.canUpdate ||
        chatDraft.value.trim().length === 0
    ) {
        return;
    }

    const request = {
        message: chatDraft.value.trim(),
        currentCode: form.code ?? '',
    };

    optimisticUserMessage.value = createOptimisticUserMessage(request.message);
    chatDraft.value = '';

    await submitChatMessageRequest(request);
};

const retryChatMessage = async (): Promise<void> => {
    if (
        chatPending.value ||
        !props.permissions.canUpdate ||
        failedChatRequest.value === null
    ) {
        return;
    }

    await submitChatMessageRequest(failedChatRequest.value);
};

const startNewChat = async (): Promise<void> => {
    if (chatPending.value || !props.permissions.canUpdate) {
        return;
    }

    chatError.value = null;
    optimisticUserMessage.value = null;
    failedChatRequest.value = null;
    chatPending.value = true;

    try {
        const payload = await postChatJson<{
            activeChat: FlowChatConversation | null;
            pastChats: FlowChatConversation[];
        }>(flowChatNew({ flow: props.flow.id ?? 0 }).url, {});

        syncChatState(payload);
        chatDraft.value = '';
    } catch (error) {
        chatError.value =
            error instanceof Error
                ? error.message
                : t('flows.editor.chat.error_fallback');
    } finally {
        chatPending.value = false;
    }
};

const compactChat = async (): Promise<void> => {
    if (
        chatPending.value ||
        !props.permissions.canUpdate ||
        (activeChat.value?.messages.length ?? 0) === 0
    ) {
        return;
    }

    chatError.value = null;
    optimisticUserMessage.value = null;
    failedChatRequest.value = null;
    chatPending.value = true;

    try {
        const payload = await postChatJson<{
            activeChat: FlowChatConversation | null;
            pastChats: FlowChatConversation[];
        }>(flowChatCompact({ flow: props.flow.id ?? 0 }).url, {
            current_code: form.code ?? '',
        });

        syncChatState(payload);
    } catch (error) {
        chatError.value =
            error instanceof Error
                ? error.message
                : t('flows.editor.chat.error_fallback');
    } finally {
        chatPending.value = false;
    }
};

const applyProposal = (message: FlowChatMessage): void => {
    if (!message.proposed_code || message.proposed_code === form.code) {
        return;
    }

    form.code = message.proposed_code;
    chatError.value = null;
};

const applyAndSaveProposal = (message: FlowChatMessage): void => {
    applyProposal(message);

    if (message.proposed_code && message.proposed_code !== props.flow.code) {
        save();
    }
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

    form.put(flowUpdate({ flow: props.flow.id ?? 0 }).url, {
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

    form.put(flowUpdate({ flow: props.flow.id ?? 0 }).url, {
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
        submitAction('run', flowRun({ flow: props.flow.id ?? 0 }).url);
    });
};

const stopFlow = (): void => {
    if (!props.permissions.canRun) {
        return;
    }

    submitAction('stop', flowStop({ flow: props.flow.id ?? 0 }).url);
};

const deployProd = (): void => {
    if (!props.permissions.canRun || form.processing || saving.value) {
        return;
    }

    saveBeforeAction(() => {
        submitAction('deploy', flowDeploy({ flow: props.flow.id ?? 0 }).url);
    });
};

const undeployProd = (): void => {
    if (!props.permissions.canRun) {
        return;
    }

    submitAction('undeploy', flowUndeploy({ flow: props.flow.id ?? 0 }).url);
};

const archiveFlow = (): void => {
    if (!props.permissions.canUpdate) {
        return;
    }

    submitAction('archive', flowArchive({ flow: props.flow.id ?? 0 }).url);
};

const restoreFlow = (): void => {
    if (!props.permissions.canUpdate) {
        return;
    }

    submitAction('restore', flowRestore({ flow: props.flow.id ?? 0 }).url);
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

const shouldPollForUpdates = computed(() => {
    return (
        actionInProgress.value === null &&
        !chatPending.value &&
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
                v-model:chat-draft="chatDraft"
                :can-update="props.permissions.canUpdate"
                :can-run="props.permissions.canRun"
                :action-in-progress="actionInProgress"
                :chat-pending="chatPending"
                :current-development-active="
                    Boolean(currentDevelopment?.active)
                "
                :current-development-status="displayDevelopmentStatus"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :code-updated-at="props.flow.code_updated_at"
                :code-error-messages="codeErrorMessages"
                :history-cards="historyCards"
                :active-chat="activeChat"
                :chat-messages="displayedChatMessages"
                :graph="displayGraph"
                :webhook-endpoints="props.webhookEndpoints"
                :graph-meta="graphMeta"
                :graph-is-outdated="graphIsOutdated"
                :development-logs="displayDevelopmentLogs"
                :format-recent-date="formatRecentDate"
                :format-date="formatDate"
                @run-flow="runFlow"
                @stop-flow="stopFlow"
                @send-chat-message="sendChatMessage"
                @retry-chat-message="retryChatMessage"
                @new-chat="startNewChat"
                @compact-chat="compactChat"
                @apply-proposal="applyProposal"
                @apply-and-save-proposal="applyAndSaveProposal"
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

            <FlowPastChatsPanel
                v-if="pastChats.length"
                :chats="pastChats"
                :all-chats-url="allChatsUrl"
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
