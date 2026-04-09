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
    FlowEditorTab,
    FlowEditorWorkspaceTab,
    FlowEnvironment,
    FlowHistory,
    FlowLog,
    FlowRun,
    FlowStorageByEnvironment,
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
import { update as flowStorageUpdate } from '@/routes/flows/storage';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    flow: FlowDetail;
    productionRun: FlowRun | null;
    lastProductionDeployment: FlowDeployment | null;
    lastDevelopmentDeployment: FlowDeployment | null;
    webhookEndpoints: FlowWebhookEndpoint[];
    productionLogsCount: number;
    deployments?: FlowDeployment[];
    status?: string | null;
    runStats: RunStat[];
    history: FlowHistory[];
    activeChat: FlowChatConversation | null;
    pastChats: FlowChatConversation[];
    storage: FlowStorageByEnvironment;
    timezoneOptions: string[];
    permissions: Permissions;
    activeDeploymentType: FlowEnvironment;
    activeEditorTab: FlowEditorTab;
}>();

const { t, locale } = useI18n();

type FlowChatHistoryKind = 'apply_proposal' | 'apply_and_save_proposal';

interface PendingChatHistoryEntry {
    clientId: string;
    kind: FlowChatHistoryKind;
    content: string;
    createdAt: string;
    sourceCode: string;
    proposedCode: string;
}

interface ChatMessageRequest {
    message: string;
    currentCode: string;
    history: PendingChatHistoryEntry[];
}

const MAX_CHAT_HISTORY_ENTRIES = 10;
const MAX_CHAT_HISTORY_CODE_LENGTH = 100000;
const WORKSPACE_TABS: FlowEditorWorkspaceTab[] = [
    'editor',
    'chat',
    'storage',
    'discovery',
    'changes',
];

const actionInProgress = ref<
    'run' | 'stop' | 'deploy' | 'undeploy' | 'archive' | 'restore' | null
>(null);
const saving = ref(false);
const refreshInFlight = ref(false);
const chatPending = ref(false);
const chatDraft = ref('');
const chatError = ref<string | null>(null);
const optimisticUserMessage = ref<FlowChatMessage | null>(null);
const pendingChatHistory = ref<PendingChatHistoryEntry[]>([]);
const failedChatRequest = ref<ChatMessageRequest | null>(null);
const activeChat = ref<FlowChatConversation | null>(props.activeChat);
const pastChats = ref<FlowChatConversation[]>(props.pastChats ?? []);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

const form = useForm({
    name: props.flow.name,
    description: props.flow.description || '',
    code: props.flow.code || '',
    timezone: props.flow.timezone || 'UTC',
});

const stringifyStorageContent = (
    value: FlowStorageByEnvironment[FlowEnvironment],
): string => {
    return JSON.stringify(value ?? {}, null, 4);
};

const storageDrafts = ref<Record<FlowEnvironment, string>>({
    development: stringifyStorageContent(props.storage.development),
    production: stringifyStorageContent(props.storage.production),
});
const syncedStorageDrafts = ref<Record<FlowEnvironment, string>>({
    development: storageDrafts.value.development,
    production: storageDrafts.value.production,
});
const activeDeploymentType = ref<FlowEnvironment>(props.activeDeploymentType);
const activeEditorTab = ref<FlowEditorTab>(props.activeEditorTab);
const activeStorageEnvironment = ref<FlowEnvironment>(
    props.activeDeploymentType,
);
const storageSaveInFlight = ref(false);
const storageForm = useForm({
    environment: 'development' as FlowEnvironment,
    content: storageDrafts.value.development,
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
    'lastProductionDeployment',
    'productionLogsCount',
    'lastDevelopmentDeployment',
    'webhookEndpoints',
    'deployments',
    'storage',
    'runStats',
    'history',
    'recentFlows',
    'flash',
    'activeDeploymentType',
    'activeEditorTab',
] as const;

const currentProduction = computed(() => props.lastProductionDeployment);
const currentDevelopment = computed(() => props.lastDevelopmentDeployment);
const selectedDeployment = computed<FlowDeployment | null>(() => {
    return activeDeploymentType.value === 'production'
        ? currentProduction.value
        : currentDevelopment.value;
});
const deployments = computed(() => props.deployments ?? []);
const discoveryWebhookEndpoints = computed<FlowWebhookEndpoint[]>(() => {
    return selectedDeployment.value?.webhooks ?? [];
});
const displayGraph = computed<Record<string, unknown> | null>(() => {
    return selectedDeployment.value?.graph ?? null;
});
const displayDeploymentStatus = computed(() => {
    return selectedDeployment.value?.status ?? null;
});
const displayDeploymentLogs = computed<FlowLog[]>(() => {
    return selectedDeployment.value?.logs ?? [];
});
const displayedChatMessages = computed<FlowChatMessage[]>(() => {
    const baseMessages = activeChat.value?.messages ?? [];
    const historyMessages = pendingChatHistory.value.map(
        (entry): FlowChatMessage => ({
            id: entry.clientId,
            role: 'user',
            content: entry.content,
            created_at: entry.createdAt,
            kind: entry.kind,
            status: null,
            transient: true,
            retryable: false,
            source_code: entry.sourceCode,
            proposed_code: entry.proposedCode,
            diff: null,
            has_code_changes: false,
        }),
    );
    const messages = [...baseMessages, ...historyMessages];

    if (optimisticUserMessage.value) {
        messages.push(optimisticUserMessage.value);
    }

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
const activeStorageContent = computed({
    get: () => {
        return storageDrafts.value[activeStorageEnvironment.value];
    },
    set: (value: string) => {
        storageDrafts.value[activeStorageEnvironment.value] = value;
    },
});
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

const activeStorageIsDirty = computed(() => {
    return (
        storageDrafts.value[activeStorageEnvironment.value] !==
        syncedStorageDrafts.value[activeStorageEnvironment.value]
    );
});

const activeStorageHasRunningDeployment = computed(() => {
    return activeStorageEnvironment.value === 'development'
        ? Boolean(currentDevelopment.value?.active)
        : Boolean(currentProduction.value?.active);
});

const activeStorageValidationError = computed(() => {
    try {
        const parsed = JSON.parse(activeStorageContent.value);
        if (parsed === null || typeof parsed !== 'object') {
            return t('flows.editor.storage.invalid_root');
        }

        return null;
    } catch {
        return t('flows.editor.storage.invalid_json');
    }
});

const activeStorageReadonlyReason = computed(() => {
    if (!props.permissions.canUpdate) {
        return t('flows.editor.storage.readonly_permissions');
    }

    if (activeStorageHasRunningDeployment.value) {
        return t('flows.editor.storage.readonly_active');
    }

    return null;
});

const activeStorageErrorMessage = computed(() => {
    return (
        activeStorageValidationError.value ??
        storageForm.errors.content ??
        storageForm.errors.environment ??
        null
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
        href: buildEditorUrl(),
    },
]);

const editorTabs = computed<Array<{ value: FlowEditorTab; label: string }>>(
    () => {
        return [
            { value: 'overview', label: t('flows.editor.tabs.overview') },
            { value: 'editor', label: t('flows.editor.tabs.code') },
            { value: 'chat', label: t('flows.editor.tabs.chat') },
            { value: 'storage', label: t('flows.editor.tabs.storage') },
            { value: 'discovery', label: t('flows.editor.tabs.discovery') },
            { value: 'changes', label: t('flows.editor.tabs.changes') },
        ];
    },
);

const currentDeploymentLabel = computed(() => {
    return activeDeploymentType.value === 'production'
        ? t('environments.production')
        : t('environments.development');
});

const currentDeploymentLogsCount = computed(() => {
    return activeDeploymentType.value === 'production'
        ? props.productionLogsCount
        : displayDeploymentLogs.value.length;
});

const isWorkspaceTab = (
    value: FlowEditorTab,
): value is FlowEditorWorkspaceTab => {
    return WORKSPACE_TABS.includes(value as FlowEditorWorkspaceTab);
};

const activeWorkspaceTab = computed<FlowEditorWorkspaceTab>({
    get: () => {
        return isWorkspaceTab(activeEditorTab.value)
            ? activeEditorTab.value
            : 'editor';
    },
    set: (value) => {
        setActiveEditorTab(value);
    },
});

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

const appendEditorQuery = (
    url: string,
    deployment: FlowEnvironment = activeDeploymentType.value,
    tab: FlowEditorTab = activeEditorTab.value,
): string => {
    const separator = url.includes('?') ? '&' : '?';
    const query = new URLSearchParams({
        deployment,
        tab,
    });

    return `${url}${separator}${query.toString()}`;
};

const buildEditorUrl = (
    deployment: FlowEnvironment = activeDeploymentType.value,
    tab: FlowEditorTab = activeEditorTab.value,
): string => {
    return appendEditorQuery(
        flowShow({ flow: props.flow.id ?? 0 }).url,
        deployment,
        tab,
    );
};

const syncBrowserUrl = (replace = false): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const nextUrl = buildEditorUrl();
    const currentUrl = `${window.location.pathname}${window.location.search}`;

    if (nextUrl === currentUrl) {
        return;
    }

    const stateMethod = replace ? 'replaceState' : 'pushState';
    window.history[stateMethod](window.history.state, '', nextUrl);
};

const readEditorStateFromLocation = (): {
    deployment: FlowEnvironment;
    tab: FlowEditorTab;
} => {
    if (typeof window === 'undefined') {
        return {
            deployment: props.activeDeploymentType,
            tab: props.activeEditorTab,
        };
    }

    const query = new URLSearchParams(window.location.search);
    const deployment = query.get('deployment');
    const tab = query.get('tab');

    return {
        deployment:
            deployment === 'production' || deployment === 'development'
                ? deployment
                : 'development',
        tab:
            tab === 'overview' || isWorkspaceTab(tab as FlowEditorTab)
                ? (tab as FlowEditorTab)
                : 'overview',
    };
};

const setActiveDeployment = (
    deployment: FlowEnvironment,
    replace = false,
): void => {
    if (activeDeploymentType.value === deployment) {
        return;
    }

    activeDeploymentType.value = deployment;
    syncBrowserUrl(replace);
};

const setActiveEditorTab = (tab: FlowEditorTab, replace = false): void => {
    if (activeEditorTab.value === tab) {
        return;
    }

    activeEditorTab.value = tab;
    syncBrowserUrl(replace);
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

const latestDeploymentSnapshotAt = computed(() => {
    return (
        selectedDeployment.value?.updated_at ??
        selectedDeployment.value?.finished_at ??
        selectedDeployment.value?.started_at ??
        selectedDeployment.value?.created_at ??
        null
    );
});

const graphIsOutdated = computed(() => {
    const codeUpdated = parseDateMs(props.flow.code_updated_at);
    const graphGenerated = parseDateMs(latestDeploymentSnapshotAt.value);

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
        status: statusLabel(displayDeploymentStatus.value),
        freshnessLabel: graphIsOutdated.value
            ? t('common.outdated')
            : t('common.updated_at'),
        updatedAt: formatRecentDate(latestDeploymentSnapshotAt.value),
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

watch(
    () => props.activeDeploymentType,
    (nextDeployment) => {
        activeDeploymentType.value = nextDeployment;
        activeStorageEnvironment.value = nextDeployment;
        syncBrowserUrl(true);
    },
);

watch(
    () => props.activeEditorTab,
    (nextTab) => {
        activeEditorTab.value = nextTab;
        syncBrowserUrl(true);
    },
);

watch(activeDeploymentType, (nextDeployment) => {
    activeStorageEnvironment.value = nextDeployment;
});

watch(
    () => props.storage,
    (nextStorage) => {
        for (const environment of ['development', 'production'] as const) {
            const nextContent = stringifyStorageContent(
                nextStorage[environment],
            );

            if (
                storageDrafts.value[environment] ===
                syncedStorageDrafts.value[environment]
            ) {
                storageDrafts.value[environment] = nextContent;
            }

            syncedStorageDrafts.value[environment] = nextContent;
        }
    },
    { deep: true },
);

watch(
    storageDrafts,
    () => {
        storageForm.clearErrors();
    },
    { deep: true },
);

watch(activeStorageEnvironment, () => {
    storageForm.clearErrors();
});

const setActiveStorageEnvironment = (environment: FlowEnvironment): void => {
    activeStorageEnvironment.value = environment;
};

const setActiveStorageContent = (value: string): void => {
    activeStorageContent.value = value;
};

const handlePopstate = (): void => {
    const nextState = readEditorStateFromLocation();
    activeDeploymentType.value = nextState.deployment;
    activeEditorTab.value = nextState.tab;
    activeStorageEnvironment.value = nextState.deployment;
};

const refreshFlowView = (): void => {
    if (refreshInFlight.value) {
        return;
    }

    refreshInFlight.value = true;

    router.reload({
        preserveScroll: true,
        only: [...refreshOnlyProps],
        onFinish: () => {
            refreshInFlight.value = false;
        },
    });
};

const getMetaCsrfToken = (): string | null => {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
    );
};

const getCookieValue = (name: string): string | null => {
    const cookies = document.cookie
        .split('; ')
        .filter((cookie) => cookie.length > 0);

    const match = cookies.find((cookie) => cookie.startsWith(`${name}=`));

    if (!match) {
        return null;
    }

    return decodeURIComponent(match.slice(name.length + 1));
};

const getCsrfHeaders = (): Record<string, string> => {
    const xsrfToken = getCookieValue('XSRF-TOKEN');

    if (xsrfToken) {
        return {
            'X-XSRF-TOKEN': xsrfToken,
        };
    }

    const csrfToken = getMetaCsrfToken();

    if (!csrfToken) {
        return {};
    }

    return {
        'X-CSRF-TOKEN': csrfToken,
    };
};

const extractChatError = async (response: Response): Promise<string> => {
    if (response.status === 419) {
        return t('flows.editor.chat.page_expired');
    }

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

        if (payload.message === 'CSRF token mismatch.') {
            return t('flows.editor.chat.page_expired');
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
            ...getCsrfHeaders(),
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
    pendingChatHistory.value = [];
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

const createPendingChatHistoryEntry = (
    kind: FlowChatHistoryKind,
    sourceCode: string,
    proposedCode: string,
): PendingChatHistoryEntry => {
    return {
        clientId: `ui-history-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        kind,
        content: t(
            kind === 'apply_and_save_proposal'
                ? 'flows.editor.chat.history_applied_and_saved'
                : 'flows.editor.chat.history_applied',
        ),
        createdAt: new Date().toISOString(),
        sourceCode,
        proposedCode,
    };
};

const normalizeChatHistoryCode = (value: string): string => {
    return value.length > MAX_CHAT_HISTORY_CODE_LENGTH ? '' : value;
};

const appendPendingChatHistory = (
    kind: FlowChatHistoryKind,
    sourceCode: string,
    proposedCode: string,
): void => {
    const nextEntry = createPendingChatHistoryEntry(
        kind,
        normalizeChatHistoryCode(sourceCode),
        normalizeChatHistoryCode(proposedCode),
    );

    pendingChatHistory.value = [...pendingChatHistory.value, nextEntry].slice(
        -MAX_CHAT_HISTORY_ENTRIES,
    );
};

const submitChatMessageRequest = async (
    request: ChatMessageRequest,
): Promise<void> => {
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
            history: request.history.map((entry) => ({
                client_id: entry.clientId,
                kind: entry.kind,
                content: entry.content,
                source_code: entry.sourceCode,
                proposed_code: entry.proposedCode,
            })),
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
        history: [...pendingChatHistory.value],
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
    pendingChatHistory.value = [];
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
    pendingChatHistory.value = [];
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

const applyProposalToEditor = (
    message: FlowChatMessage,
): { sourceCode: string; proposedCode: string } | null => {
    if (!message.proposed_code || message.proposed_code === form.code) {
        return null;
    }

    const sourceCode = form.code ?? '';
    const proposedCode = message.proposed_code;

    form.code = proposedCode;
    chatError.value = null;

    return {
        sourceCode,
        proposedCode,
    };
};

const applyProposal = (message: FlowChatMessage): void => {
    const appliedSnapshot = applyProposalToEditor(message);

    if (appliedSnapshot === null) {
        return;
    }

    appendPendingChatHistory(
        'apply_proposal',
        appliedSnapshot.sourceCode,
        appliedSnapshot.proposedCode,
    );
};

const applyAndSaveProposal = (message: FlowChatMessage): void => {
    const appliedSnapshot = applyProposalToEditor(message);

    if (appliedSnapshot === null) {
        return;
    }

    const shouldSave = appliedSnapshot.proposedCode !== (props.flow.code ?? '');

    appendPendingChatHistory(
        shouldSave ? 'apply_and_save_proposal' : 'apply_proposal',
        appliedSnapshot.sourceCode,
        appliedSnapshot.proposedCode,
    );

    if (shouldSave) {
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

    form.put(appendEditorQuery(flowUpdate({ flow: props.flow.id ?? 0 }).url), {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
        },
    });
};

const saveStorage = (): void => {
    const environment = activeStorageEnvironment.value;

    if (
        activeStorageReadonlyReason.value !== null ||
        activeStorageErrorMessage.value !== null ||
        storageSaveInFlight.value
    ) {
        return;
    }

    storageSaveInFlight.value = true;
    storageForm.environment = environment;
    storageForm.content = storageDrafts.value[environment];

    storageForm.put(
        appendEditorQuery(flowStorageUpdate({ flow: props.flow.id ?? 0 }).url),
        {
            preserveScroll: true,
            only: [...refreshOnlyProps],
            onSuccess: () => {
                syncedStorageDrafts.value[environment] =
                    storageDrafts.value[environment];
            },
            onFinish: () => {
                storageSaveInFlight.value = false;
            },
        },
    );
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

    form.put(appendEditorQuery(flowUpdate({ flow: props.flow.id ?? 0 }).url), {
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
        submitAction(
            'run',
            appendEditorQuery(
                activeDeploymentType.value === 'production'
                    ? flowDeploy({ flow: props.flow.id ?? 0 }).url
                    : flowRun({ flow: props.flow.id ?? 0 }).url,
            ),
        );
    });
};

const stopFlow = (): void => {
    if (!props.permissions.canRun) {
        return;
    }

    submitAction(
        'stop',
        appendEditorQuery(
            activeDeploymentType.value === 'production'
                ? flowUndeploy({ flow: props.flow.id ?? 0 }).url
                : flowStop({ flow: props.flow.id ?? 0 }).url,
        ),
    );
};

const archiveFlow = (): void => {
    if (!props.permissions.canUpdate) {
        return;
    }

    submitAction(
        'archive',
        appendEditorQuery(flowArchive({ flow: props.flow.id ?? 0 }).url),
    );
};

const restoreFlow = (): void => {
    if (!props.permissions.canUpdate) {
        return;
    }

    submitAction(
        'restore',
        appendEditorQuery(flowRestore({ flow: props.flow.id ?? 0 }).url),
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

    if (typeof window !== 'undefined') {
        window.removeEventListener('popstate', handlePopstate);
    }
});

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    const nextState = readEditorStateFromLocation();
    activeDeploymentType.value = nextState.deployment;
    activeEditorTab.value = nextState.tab;
    activeStorageEnvironment.value = nextState.deployment;
    syncBrowserUrl(true);
    window.addEventListener('popstate', handlePopstate);
});
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1600px] divide-y pt-3 pb-8">
            <FlowEditorHeader
                :name="form.name"
                :description="form.description"
                :active-deployment-type="activeDeploymentType"
                :can-run="props.permissions.canRun"
                :action-in-progress="actionInProgress"
                @update:deployment-type="setActiveDeployment"
            />

            <section class="px-4 py-5">
                <nav class="flex flex-wrap gap-2" aria-label="Editor tabs">
                    <a
                        v-for="tab in editorTabs"
                        :key="tab.value"
                        :href="buildEditorUrl(activeDeploymentType, tab.value)"
                        class="inline-flex items-center rounded-full border px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            activeEditorTab === tab.value
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border bg-background text-muted-foreground hover:border-primary/40 hover:text-foreground'
                        "
                        @click.prevent="setActiveEditorTab(tab.value)"
                    >
                        {{ tab.label }}
                    </a>
                </nav>
            </section>

            <FlowEditorSummary
                v-if="activeEditorTab === 'overview'"
                :flow-runs-count="props.flow.runs_count"
                :last-started-at="props.flow.last_started_at"
                :last-finished-at="props.flow.last_finished_at"
                :has-current-deployment="Boolean(selectedDeployment)"
                :current-deployment-label="currentDeploymentLabel"
                :current-deployment-status="displayDeploymentStatus"
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

            <FlowEditorWorkspace
                v-else
                v-model:code="form.code"
                v-model:chat-draft="chatDraft"
                v-model:active-tab="activeWorkspaceTab"
                v-model:storage-environment="activeStorageEnvironment"
                v-model:storage-content="activeStorageContent"
                :can-update="props.permissions.canUpdate"
                :can-run="props.permissions.canRun"
                :action-in-progress="actionInProgress"
                :chat-pending="chatPending"
                :current-deployment-active="Boolean(selectedDeployment?.active)"
                :current-deployment-status="displayDeploymentStatus"
                :status-tone="statusTone"
                :status-label="statusLabel"
                :code-updated-at="props.flow.code_updated_at"
                :code-error-messages="codeErrorMessages"
                :history-cards="historyCards"
                :active-chat="activeChat"
                :chat-messages="displayedChatMessages"
                :storage-readonly="activeStorageReadonlyReason !== null"
                :storage-readonly-reason="activeStorageReadonlyReason"
                :storage-saving="storageSaveInFlight"
                :storage-dirty="activeStorageIsDirty"
                :storage-error-message="activeStorageErrorMessage"
                :graph="displayGraph"
                :webhook-endpoints="discoveryWebhookEndpoints"
                :graph-meta="graphMeta"
                :graph-is-outdated="graphIsOutdated"
                :log-stream-key="selectedDeployment?.id ?? null"
                :deployment-logs="displayDeploymentLogs"
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
                @update:storage-environment="setActiveStorageEnvironment"
                @update:storage-content="setActiveStorageContent"
                @save-storage="saveStorage"
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
