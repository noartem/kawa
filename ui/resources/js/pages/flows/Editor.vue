<script setup lang="ts">
import FlowLogsPanel from '@/components/FlowLogsPanel.vue';
import FlowCodeEditor from '@/components/flows/FlowCodeEditor.vue';
import FlowGraph from '@/components/flows/FlowGraph.vue';
import type {
    DispatchPathHighlight,
    FlowGraphEdgeHighlight,
} from '@/components/flows/graphHighlights';
import {
    parseHiddenGraphNodeIds,
    setHiddenGraphNodeQueryParams,
} from '@/components/flows/graphVisibility';
import { logFlowGraphVisibility } from '@/components/flows/graphVisibilityDebug';
import FlowChangesPanel from '@/components/flows/editor/FlowChangesPanel.vue';
import FlowDiscoveryPanel from '@/components/flows/editor/FlowDiscoveryPanel.vue';
import FlowEditorChatPanel from '@/components/flows/editor/FlowEditorChatPanel.vue';
import StackedSidePanelsLayout from '@/components/flows/editor/StackedSidePanelsLayout.vue';
import FlowStoragePanel from '@/components/flows/editor/FlowStoragePanel.vue';
import { connectRelatedEventsInGraph } from '@/components/flows/editor/eventConnections';
import { formatFlowStorageContent } from '@/components/flows/editor/storageContent';
import {
    createDefaultStackedSidePanelsResizeState,
    readStackedSidePanelsResizeState,
    setStackedSidePanelsResizeQueryParams,
    stackedSidePanelsResizeStatesEqual,
    type StackedSidePanelsResizeState,
} from '@/components/flows/editor/stackedSidePanelsLayout';
import type {
    FlowChatConversation,
    FlowChatMessage,
    FlowChatRequestStatus,
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
    GraphMeta,
    Permissions,
    RunStat,
} from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    deploy as flowDeploy,
    editor as flowEditor,
    restart as flowRestart,
    run as flowRun,
    index as flowsIndex,
    show as flowShow,
    stop as flowStop,
    undeploy as flowUndeploy,
    update as flowUpdate,
} from '@/routes/flows';
import {
    compact as flowChatCompact,
    store as flowChatStore,
} from '@/routes/flows/chat';
import { storeMessage as flowChatStoreMessage } from '@/actions/App/Http/Controllers/FlowChatController';
import { update as flowStorageUpdate } from '@/routes/flows/storage';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    Logs,
    Play,
    RotateCcw,
    Save,
    Square,
    Workflow,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowCodeEditorExpose {
    focusLine: (line: number, flash?: boolean) => boolean;
}

interface DiscoverySelectionTarget {
    id: string;
    type: 'actor' | 'event';
    requestKey: number;
}

interface FlowGraphExpose {
    highlightDispatchPath: (payload: DispatchPathHighlight) => void;
    focusEdge: (payload: FlowGraphEdgeHighlight) => void;
    focusNode: (nodeId: string) => void;
    setHoveredEdgeHighlight: (payload: FlowGraphEdgeHighlight | null) => void;
}

const props = defineProps<{
    flow: FlowDetail;
    allChatsUrl: string;
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

interface ChatMessageRequest {
    message: string;
    currentCode: string;
}

interface ChatRequestErrorPayload {
    message: string;
    code: string;
    status: number;
}

interface ChatRequestResponse {
    activeChat: FlowChatConversation | null;
    pastChats: FlowChatConversation[];
    chatRequest: FlowChatRequestStatus | null;
    status: FlowChatRequestStatus['status'];
    error: ChatRequestErrorPayload | null;
}

interface FlowSyncState {
    name: string;
    description: string;
    code: string;
    timezone: string;
    codeUpdatedAt: string | null;
}

interface FlowSaveResponse {
    flow: {
        name: string;
        description: string;
        code: string;
        timezone: string;
        code_updated_at: string | null;
    };
}

interface JsonErrorPayload {
    message?: string;
    errors?: Record<string, string[]>;
}

class FlowSaveValidationError extends Error {
    constructor(public readonly errors: Record<string, string>) {
        super('Validation failed.');
    }
}

const WORKSPACE_TABS: FlowEditorWorkspaceTab[] = [
    'editor',
    'chat',
    'storage',
    'discovery',
    'changes',
];

const actionInProgress = ref<
    'run' | 'stop' | 'restart' | 'deploy' | 'undeploy' | null
>(null);
const saving = ref(false);
const refreshInFlight = ref(false);
const chatPending = ref(false);
const chatDraft = ref('');
const chatError = ref<string | null>(null);
const optimisticUserMessage = ref<FlowChatMessage | null>(null);
const failedChatRequest = ref<ChatMessageRequest | null>(null);
const activeChatRequest = ref<FlowChatRequestStatus | null>(null);
const activeChat = ref<FlowChatConversation | null>(props.activeChat);
let refreshTimer: ReturnType<typeof setInterval> | null = null;
let chatPollTimer: ReturnType<typeof setInterval> | null = null;
let removeBeforeVisitListener: (() => void) | null = null;

const form = useForm({
    name: props.flow.name,
    description: props.flow.description || '',
    code: props.flow.code || '',
    timezone: props.flow.timezone || 'UTC',
});

const createFlowSyncState = (flow: FlowDetail): FlowSyncState => {
    return {
        name: flow.name,
        description: flow.description || '',
        code: flow.code || '',
        timezone: flow.timezone || 'UTC',
        codeUpdatedAt: flow.code_updated_at,
    };
};

const syncedFlowState = ref<FlowSyncState>(createFlowSyncState(props.flow));

const syncFlowFormBaseline = (nextState: FlowSyncState): void => {
    syncedFlowState.value = nextState;
    form.defaults({
        name: nextState.name,
        description: nextState.description,
        code: nextState.code,
        timezone: nextState.timezone,
    });
};

const storageDrafts = ref<Record<FlowEnvironment, string>>({
    development: formatFlowStorageContent(props.storage.development),
    production: formatFlowStorageContent(props.storage.production),
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
const graphPanelVisible = ref(true);
const logsPanelVisible = ref(true);
const sidePanelResizeState = ref<StackedSidePanelsResizeState>(
    createDefaultStackedSidePanelsResizeState(),
);
const storageSaveInFlight = ref(false);
const storageForm = useForm({
    environment: 'development' as FlowEnvironment,
    content: storageDrafts.value.development,
});

const workspaceSection = ref<HTMLElement | null>(null);
const codeEditor = ref<FlowCodeEditorExpose | null>(null);
const flowGraph = ref<FlowGraphExpose | null>(null);
const selectedDiscoveryTarget = ref<DiscoverySelectionTarget | null>(null);
const hiddenNodeIds = ref<string[]>([]);

let suppressWorkspaceScroll = false;
let restoreWorkspaceScrollTimer: ReturnType<typeof setTimeout> | null = null;

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
const discoveryWebhookEndpoints = computed<FlowWebhookEndpoint[]>(() => {
    return selectedDeployment.value?.webhooks ?? [];
});
const displayGraph = computed<Record<string, unknown> | null>(() => {
    return connectRelatedEventsInGraph(selectedDeployment.value?.graph ?? null);
});
const displayDeploymentStatus = computed(() => {
    return selectedDeployment.value?.status ?? null;
});
const displayDeploymentLogs = computed<FlowLog[]>(() => {
    return selectedDeployment.value?.logs ?? [];
});
const displayedChatMessages = computed<FlowChatMessage[]>(() => {
    const messages = [...(activeChat.value?.messages ?? [])];

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
const hasActiveDeploys = computed(() =>
    Boolean(
        currentProduction.value?.active || currentDevelopment.value?.active,
    ),
);

const hasUnsavedFlowChanges = computed(() => {
    return (
        form.name !== syncedFlowState.value.name ||
        form.description !== syncedFlowState.value.description ||
        form.code !== syncedFlowState.value.code ||
        form.timezone !== syncedFlowState.value.timezone
    );
});

const hasUnsavedCodeChanges = computed(() => {
    return form.code !== syncedFlowState.value.code;
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
        href: buildShowUrl(),
    },
    {
        title: t('flows.editor.title'),
        href: buildEditorUrl(),
    },
]);

const editorTabs = computed<
    Array<{ value: FlowEditorWorkspaceTab; label: string }>
>(() => {
    return [
        { value: 'editor', label: t('flows.editor.tabs.code') },
        { value: 'chat', label: t('flows.editor.tabs.chat') },
        { value: 'storage', label: t('flows.editor.tabs.storage') },
        { value: 'discovery', label: t('flows.editor.tabs.discovery') },
        { value: 'changes', label: t('flows.editor.tabs.changes') },
    ];
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

const currentDeploymentActive = computed(() => {
    return Boolean(selectedDeployment.value?.active);
});

const isStatusTransitioning = computed(() => {
    return (
        actionInProgress.value === 'run' ||
        actionInProgress.value === 'restart' ||
        actionInProgress.value === 'stop'
    );
});

const showStatusChip = computed(() => {
    const visibleStatuses = new Set([
        'creating',
        'created',
        'running',
        'stopping',
        'error',
        'failed',
        'lock_failed',
    ]);

    return (
        currentDeploymentActive.value ||
        isStatusTransitioning.value ||
        visibleStatuses.has(displayDeploymentStatus.value ?? '')
    );
});

const statusChipStatus = computed<string>(() => {
    if (actionInProgress.value === 'run') {
        return 'creating';
    }

    if (actionInProgress.value === 'restart') {
        return 'creating';
    }

    if (actionInProgress.value === 'stop') {
        return 'stopping';
    }

    if (displayDeploymentStatus.value) {
        return displayDeploymentStatus.value;
    }

    return currentDeploymentActive.value ? 'running' : 'unknown';
});

const statusChipLabel = computed(() => {
    return statusLabel(statusChipStatus.value);
});

const statusChipIcon = computed(() => {
    switch (statusChipStatus.value) {
        case 'error':
        case 'failed':
        case 'lock_failed':
            return AlertCircle;
        case 'running':
        case 'ready':
        case 'locked':
            return Play;
        case 'stopped':
        case 'success':
            return Square;
        default:
            return Spinner;
    }
});

const saveTooltipLabel = computed(() => {
    return t('actions.save');
});

const startTooltipLabel = computed(() => {
    return hasUnsavedFlowChanges.value
        ? t('flows.editor.actions.save_and_start')
        : t('actions.start');
});

const restartTooltipLabel = computed(() => {
    return hasUnsavedFlowChanges.value
        ? t('flows.editor.actions.save_and_restart')
        : t('actions.restart');
});

const stopTooltipLabel = computed(() => {
    return t('actions.stop');
});

const graphToggleTooltipLabel = computed(() => {
    return graphPanelVisible.value
        ? t('flows.editor.actions.close_graph')
        : t('flows.editor.actions.open_graph');
});

const logsToggleTooltipLabel = computed(() => {
    return logsPanelVisible.value
        ? t('flows.editor.actions.close_logs')
        : t('flows.editor.actions.open_logs');
});

const headerControlsDisabled = computed(() => {
    return actionInProgress.value !== null;
});

const saveActionDisabled = computed(() => {
    return (
        !props.permissions.canUpdate ||
        !hasUnsavedFlowChanges.value ||
        actionInProgress.value !== null ||
        chatPending.value ||
        saving.value
    );
});

const runActionDisabled = computed(() => {
    return (
        !props.permissions.canRun ||
        actionInProgress.value !== null ||
        chatPending.value ||
        saving.value ||
        form.processing
    );
});

const stopActionDisabled = computed(() => {
    return (
        !props.permissions.canRun ||
        actionInProgress.value !== null ||
        chatPending.value
    );
});

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
    origin: 'editor' | null = null,
    graphVisible: boolean = graphPanelVisible.value,
    logsVisible: boolean = logsPanelVisible.value,
    nextHiddenNodeIds: string[] = hiddenNodeIds.value,
    nextResizeState: StackedSidePanelsResizeState = sidePanelResizeState.value,
): string => {
    const separator = url.includes('?') ? '&' : '?';
    const query = new URLSearchParams({
        deployment,
        tab,
        graph: graphVisible ? '1' : '0',
        logs: logsVisible ? '1' : '0',
    });

    if (origin !== null) {
        query.set('origin', origin);
    }

    setHiddenGraphNodeQueryParams(query, nextHiddenNodeIds);
    setStackedSidePanelsResizeQueryParams(query, nextResizeState);

    logFlowGraphVisibility('Editor.appendEditorQuery', {
        url,
        deployment,
        tab,
        origin,
        graphVisible,
        logsVisible,
        hiddenNodeIds: [...nextHiddenNodeIds],
        resizeState: nextResizeState,
        queryString: query.toString(),
    });

    return `${url}${separator}${query.toString()}`;
};

const buildShowUrl = (
    deployment: FlowEnvironment = activeDeploymentType.value,
    tab: FlowEditorTab = activeEditorTab.value,
): string => {
    return appendEditorQuery(
        flowShow({ flow: props.flow.id ?? 0 }).url,
        deployment,
        tab,
    );
};

const buildEditorUrl = (
    deployment: FlowEnvironment = activeDeploymentType.value,
    tab: FlowEditorTab = activeEditorTab.value,
): string => {
    return appendEditorQuery(
        flowEditor({ flow: props.flow.id ?? 0 }).url,
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

    logFlowGraphVisibility('Editor.syncBrowserUrl', {
        replace,
        currentUrl,
        nextUrl,
        hiddenNodeIds: [...hiddenNodeIds.value],
        graphVisible: graphPanelVisible.value,
        logsVisible: logsPanelVisible.value,
        deployment: activeDeploymentType.value,
        tab: activeEditorTab.value,
    });

    if (nextUrl === currentUrl) {
        return;
    }

    const stateMethod = replace ? 'replaceState' : 'pushState';
    window.history[stateMethod](window.history.state, '', nextUrl);
};

const readEditorStateFromLocation = (): {
    deployment: FlowEnvironment;
    tab: FlowEditorTab;
    graphVisible: boolean;
    logsVisible: boolean;
    hiddenNodeIds: string[];
    resizeState: StackedSidePanelsResizeState;
} => {
    if (typeof window === 'undefined') {
        return {
            deployment: props.activeDeploymentType,
            tab: props.activeEditorTab,
            graphVisible: true,
            logsVisible: true,
            hiddenNodeIds: [],
            resizeState: createDefaultStackedSidePanelsResizeState(),
        };
    }

    const query = new URLSearchParams(window.location.search);
    const deployment = query.get('deployment');
    const tab = query.get('tab');
    const graph = query.get('graph');
    const logs = query.get('logs');

    const state = {
        deployment:
            deployment === 'production' || deployment === 'development'
                ? deployment
                : 'development',
        tab: isWorkspaceTab(tab as FlowEditorTab)
            ? (tab as FlowEditorTab)
            : 'editor',
        graphVisible: graph === '0' ? false : true,
        logsVisible: logs === '0' ? false : true,
        hiddenNodeIds: parseHiddenGraphNodeIds(query),
        resizeState: readStackedSidePanelsResizeState(query),
    };

    logFlowGraphVisibility('Editor.readEditorStateFromLocation', {
        search: window.location.search,
        state,
    });

    return state;
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

const setGraphPanelVisible = (visible: boolean, replace = false): void => {
    if (graphPanelVisible.value === visible) {
        return;
    }

    graphPanelVisible.value = visible;
    syncBrowserUrl(replace);
};

const setLogsPanelVisible = (visible: boolean, replace = false): void => {
    if (logsPanelVisible.value === visible) {
        return;
    }

    logsPanelVisible.value = visible;
    syncBrowserUrl(replace);
};

const setSidePanelResizeState = (
    nextResizeState: StackedSidePanelsResizeState,
    replace = true,
): void => {
    if (
        stackedSidePanelsResizeStatesEqual(
            sidePanelResizeState.value,
            nextResizeState,
        )
    ) {
        return;
    }

    sidePanelResizeState.value = nextResizeState;
    syncBrowserUrl(replace);
};

const toggleHiddenNodeVisibility = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    const nextHiddenNodeIds = hiddenNodeIds.value.includes(payload.id)
        ? hiddenNodeIds.value.filter((nodeId) => nodeId !== payload.id)
        : [...hiddenNodeIds.value, payload.id];

    logFlowGraphVisibility('Editor.toggleHiddenNodeVisibility', {
        payload,
        hiddenNodeIdsBefore: [...hiddenNodeIds.value],
        hiddenNodeIdsAfter: nextHiddenNodeIds,
    });

    hiddenNodeIds.value = nextHiddenNodeIds;
    syncBrowserUrl();
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
    const codeUpdated = parseDateMs(syncedFlowState.value.codeUpdatedAt);
    const graphGenerated = parseDateMs(latestDeploymentSnapshotAt.value);

    return (
        codeUpdated !== null &&
        graphGenerated !== null &&
        graphGenerated < codeUpdated
    );
});

const graphMeta = computed<GraphMeta>(() => {
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

const clearWorkspaceScrollSuppression = (): void => {
    suppressWorkspaceScroll = false;

    if (restoreWorkspaceScrollTimer === null) {
        return;
    }

    clearTimeout(restoreWorkspaceScrollTimer);
    restoreWorkspaceScrollTimer = null;
};

const suppressNextWorkspaceScroll = (): void => {
    suppressWorkspaceScroll = true;

    if (restoreWorkspaceScrollTimer !== null) {
        clearTimeout(restoreWorkspaceScrollTimer);
    }

    restoreWorkspaceScrollTimer = setTimeout(() => {
        clearWorkspaceScrollSuppression();
    }, 80);
};

const focusEditor = (): void => {
    if (suppressWorkspaceScroll) {
        return;
    }

    workspaceSection.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
};

const jumpToCode = async (line: number): Promise<void> => {
    suppressNextWorkspaceScroll();
    activeWorkspaceTab.value = 'editor';
    await nextTick();

    requestAnimationFrame(() => {
        codeEditor.value?.focusLine(line, true);
    });
};

const openDiscoveryNode = async (payload: {
    id: string;
    type: 'actor' | 'event';
}): Promise<void> => {
    suppressNextWorkspaceScroll();
    selectedDiscoveryTarget.value = {
        ...payload,
        requestKey: Date.now(),
    };
    activeWorkspaceTab.value = 'discovery';
    await nextTick();
};

const highlightDispatchPath = (payload: DispatchPathHighlight): void => {
    flowGraph.value?.highlightDispatchPath(payload);
};

const setHoveredEdgeHighlight = (
    payload: FlowGraphEdgeHighlight | null,
): void => {
    flowGraph.value?.setHoveredEdgeHighlight(payload);
};

const focusEdgeHighlight = (payload: FlowGraphEdgeHighlight): void => {
    flowGraph.value?.focusEdge(payload);
};

const focusDiscoveryNode = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    flowGraph.value?.focusNode(payload.id);
};

const handleLogNodeSelection = (payload: {
    id: string;
    type: 'actor' | 'event';
}): void => {
    void openDiscoveryNode(payload);
    focusDiscoveryNode(payload);
};

watch(
    () => props.activeChat,
    (nextChat) => {
        activeChat.value = nextChat;
    },
    { deep: true },
);

watch(
    () => props.flow,
    (nextFlow) => {
        syncFlowFormBaseline(createFlowSyncState(nextFlow));
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
            const nextContent = formatFlowStorageContent(
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

const handlePopstate = (): void => {
    const nextState = readEditorStateFromLocation();

    logFlowGraphVisibility('Editor.handlePopstate', {
        state: nextState,
    });

    activeDeploymentType.value = nextState.deployment;
    activeEditorTab.value = nextState.tab;
    activeStorageEnvironment.value = nextState.deployment;
    graphPanelVisible.value = nextState.graphVisible;
    logsPanelVisible.value = nextState.logsVisible;
    hiddenNodeIds.value = nextState.hiddenNodeIds;
    sidePanelResizeState.value = nextState.resizeState;
};

const confirmLeaveWithUnsavedCode = (): boolean => {
    if (!hasUnsavedCodeChanges.value || typeof window === 'undefined') {
        return true;
    }

    return window.confirm(t('flows.editor.unsaved_code_confirm'));
};

const handleBeforeUnload = (event: BeforeUnloadEvent): void => {
    if (!hasUnsavedCodeChanges.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = t('flows.editor.unsaved_code_confirm');
};

const handleBeforeVisit = (event: {
    detail: {
        visit: {
            url: string | URL;
        };
    };
}): boolean | void => {
    if (!hasUnsavedCodeChanges.value || typeof window === 'undefined') {
        return;
    }

    const nextUrl = new URL(
        String(event.detail.visit.url),
        window.location.origin,
    );

    if (nextUrl.pathname === window.location.pathname) {
        return;
    }

    return confirmLeaveWithUnsavedCode();
};

const refreshFlowView = (): void => {
    if (refreshInFlight.value) {
        return;
    }

    refreshInFlight.value = true;

    // Keep Inertia refreshes aligned with the URL we maintain via history state.
    router.visit(buildEditorUrl(), {
        showProgress: false,
        replace: true,
        preserveState: true,
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

const normalizeJsonErrors = (
    errors: Record<string, string[]> | undefined,
): Record<string, string> => {
    if (!errors) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(errors).map(([field, messages]) => [
            field,
            messages.join('\n'),
        ]),
    );
};

const extractRequestError = async (response: Response): Promise<string> => {
    if (response.status === 419) {
        return t('flows.editor.chat.page_expired');
    }

    try {
        const payload = (await response.json()) as JsonErrorPayload;

        return (
            Object.values(payload.errors ?? {})[0]?.[0] ??
            payload.message ??
            response.statusText ??
            'Request failed.'
        );
    } catch {
        return response.statusText || 'Request failed.';
    }
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

const putJson = async <T,>(
    url: string,
    payload: Record<string, unknown>,
): Promise<T> => {
    const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders(),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422) {
        const payload = (await response.json()) as JsonErrorPayload;

        throw new FlowSaveValidationError(normalizeJsonErrors(payload.errors));
    }

    if (!response.ok) {
        throw new Error(await extractRequestError(response));
    }

    return response.json() as Promise<T>;
};

const createChat = async (): Promise<FlowChatConversation> => {
    const payload = await postChatJson<ChatRequestResponse>(
        flowChatStore({ flow: props.flow.id ?? 0 }).url,
        {},
    );

    activeChat.value = payload.activeChat;
    chatError.value = null;

    if (payload.activeChat === null) {
        throw new Error(t('flows.editor.chat.error_fallback'));
    }

    return payload.activeChat;
};

const ensureActiveChat = async (): Promise<FlowChatConversation> => {
    return activeChat.value ?? createChat();
};

const syncChatState = (payload: {
    activeChat: FlowChatConversation | null;
    pastChats: FlowChatConversation[];
}): void => {
    activeChat.value = payload.activeChat;
    chatError.value = null;
    optimisticUserMessage.value = null;
    failedChatRequest.value = null;
    activeChatRequest.value = null;
    stopChatRequestPolling();
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

const getChatJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders(),
        },
    });

    if (!response.ok) {
        throw new Error(await extractChatError(response));
    }

    return response.json() as Promise<T>;
};

const stopChatRequestPolling = (): void => {
    if (chatPollTimer !== null) {
        clearInterval(chatPollTimer);
        chatPollTimer = null;
    }
};

const handleQueuedChatResponse = (payload: ChatRequestResponse): void => {
    activeChat.value = payload.activeChat;
    activeChatRequest.value = payload.chatRequest;

    if (payload.status === 'completed') {
        syncChatState(payload);
        chatPending.value = false;

        return;
    }

    if (payload.status === 'failed') {
        stopChatRequestPolling();
        chatPending.value = false;
        chatError.value =
            payload.error?.message ?? t('flows.editor.chat.error_fallback');

        return;
    }

    chatError.value = null;
};

const pollActiveChatRequest = async (): Promise<void> => {
    if (activeChatRequest.value === null) {
        stopChatRequestPolling();

        return;
    }

    try {
        const payload = await getChatJson<ChatRequestResponse>(
            activeChatRequest.value.poll_url,
        );

        handleQueuedChatResponse(payload);
    } catch (error) {
        stopChatRequestPolling();
        chatPending.value = false;
        chatError.value =
            error instanceof Error
                ? error.message
                : t('flows.editor.chat.error_fallback');
    }
};

const startChatRequestPolling = (chatRequest: FlowChatRequestStatus): void => {
    activeChatRequest.value = chatRequest;
    stopChatRequestPolling();
    chatPollTimer = setInterval(() => {
        void pollActiveChatRequest();
    }, 1500);
};

const submitChatMessageRequest = async (
    request: ChatMessageRequest,
): Promise<void> => {
    chatError.value = null;
    chatPending.value = true;
    failedChatRequest.value = request;
    let shouldKeepPending = false;

    try {
        const chat = await ensureActiveChat();
        const payload = await postChatJson<ChatRequestResponse>(
            flowChatStoreMessage({
                flow: props.flow.id ?? 0,
                chat: chat.id,
            }).url,
            {
                message: request.message,
                current_code: request.currentCode,
            },
        );

        if (payload.chatRequest !== null && payload.status !== 'completed') {
            shouldKeepPending = true;
            activeChat.value = payload.activeChat;
            chatError.value = null;
            startChatRequestPolling(payload.chatRequest);

            return;
        }

        syncChatState(payload);
    } catch (error) {
        chatError.value =
            error instanceof Error
                ? error.message
                : t('flows.editor.chat.error_fallback');
    } finally {
        if (!shouldKeepPending) {
            chatPending.value = false;
        }
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
    activeChatRequest.value = null;
    stopChatRequestPolling();
    chatPending.value = true;

    try {
        await createChat();
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
    activeChatRequest.value = null;
    stopChatRequestPolling();
    chatPending.value = true;

    try {
        const payload = await postChatJson<ChatRequestResponse>(
            flowChatCompact({
                flow: props.flow.id ?? 0,
                chat: activeChat.value.id,
            }).url,
            {
                current_code: form.code ?? '',
            },
        );

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
    applyProposalToEditor(message);
};

const applyAndSaveProposal = (message: FlowChatMessage): void => {
    const appliedSnapshot = applyProposalToEditor(message);

    if (appliedSnapshot === null) {
        return;
    }

    const shouldSave =
        appliedSnapshot.proposedCode !== syncedFlowState.value.code;

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

const saveFlow = async (refreshView = true): Promise<boolean> => {
    if (!canSave.value || saving.value) {
        return false;
    }

    saving.value = true;
    form.clearErrors();

    try {
        const payload = await putJson<FlowSaveResponse>(
            appendEditorQuery(
                flowUpdate({ flow: props.flow.id ?? 0 }).url,
                activeDeploymentType.value,
                activeEditorTab.value,
                'editor',
            ),
            {
                name: form.name,
                description: form.description,
                code: form.code,
                timezone: form.timezone,
            },
        );

        form.name = payload.flow.name;
        form.description = payload.flow.description || '';
        form.code = payload.flow.code || '';
        form.timezone = payload.flow.timezone || 'UTC';

        syncFlowFormBaseline({
            name: payload.flow.name,
            description: payload.flow.description || '',
            code: payload.flow.code || '',
            timezone: payload.flow.timezone || 'UTC',
            codeUpdatedAt: payload.flow.code_updated_at,
        });

        if (refreshView) {
            refreshFlowView();
        }

        return true;
    } catch (error) {
        if (error instanceof FlowSaveValidationError) {
            form.setError(error.errors);

            return false;
        }

        form.setError({
            code:
                error instanceof Error ? error.message : 'Unable to save flow.',
        });

        return false;
    } finally {
        saving.value = false;
    }
};

const save = (): void => {
    void saveFlow();
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
        appendEditorQuery(
            flowStorageUpdate({ flow: props.flow.id ?? 0 }).url,
            activeDeploymentType.value,
            activeEditorTab.value,
            'editor',
        ),
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

    void saveFlow(false).then((saved) => {
        if (saved) {
            onSuccess();
        }
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
                activeDeploymentType.value,
                activeEditorTab.value,
                'editor',
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
            activeDeploymentType.value,
            activeEditorTab.value,
            'editor',
        ),
    );
};

const restartFlow = (): void => {
    if (!props.permissions.canRun || form.processing || saving.value) {
        return;
    }

    saveBeforeAction(() => {
        submitAction(
            'restart',
            appendEditorQuery(
                flowRestart({ flow: props.flow.id ?? 0 }).url,
                activeDeploymentType.value,
                activeEditorTab.value,
                'editor',
            ),
        );
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

    stopChatRequestPolling();

    if (restoreWorkspaceScrollTimer !== null) {
        clearTimeout(restoreWorkspaceScrollTimer);
        restoreWorkspaceScrollTimer = null;
    }

    if (typeof window !== 'undefined') {
        window.removeEventListener('popstate', handlePopstate);
        window.removeEventListener('beforeunload', handleBeforeUnload);
    }

    removeBeforeVisitListener?.();
    removeBeforeVisitListener = null;
});

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    const nextState = readEditorStateFromLocation();

    logFlowGraphVisibility('Editor.onMounted', {
        state: nextState,
    });

    activeDeploymentType.value = nextState.deployment;
    activeEditorTab.value = nextState.tab;
    activeStorageEnvironment.value = nextState.deployment;
    graphPanelVisible.value = nextState.graphVisible;
    logsPanelVisible.value = nextState.logsVisible;
    hiddenNodeIds.value = nextState.hiddenNodeIds;
    sidePanelResizeState.value = nextState.resizeState;
    syncBrowserUrl(true);
    window.addEventListener('popstate', handlePopstate);
    window.addEventListener('beforeunload', handleBeforeUnload);
    removeBeforeVisitListener = router.on('before', handleBeforeVisit);
});
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #header>
            <Badge
                v-if="showStatusChip"
                variant="outline"
                :class="[
                    'z-1 ml-1 inline-flex items-center gap-1.5',
                    statusTone(statusChipStatus),
                ]"
            >
                <Spinner v-if="isStatusTransitioning" class="size-3.5" />
                <component :is="statusChipIcon" v-else class="size-3.5" />
                {{ statusChipLabel }}
            </Badge>

            <div
                class="z-0 flex h-12 items-center justify-center gap-3 px-4 py-2 lg:absolute lg:top-0 lg:left-0 lg:w-full"
            >
                <div class="w-21" />

                <nav
                    aria-label="Edit tabs"
                    class="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/30 p-1"
                >
                    <button
                        v-for="tab in editorTabs"
                        :key="tab.value"
                        type="button"
                        class="rounded-md px-3 py-0.5 text-sm transition"
                        :class="
                            activeWorkspaceTab === tab.value
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeWorkspaceTab = tab.value"
                    >
                        {{ tab.label }}
                    </button>
                </nav>

                <div
                    class="inline-flex w-21 items-center justify-center gap-1.5 rounded-lg border border-border bg-muted/30 p-1"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="graphToggleTooltipLabel"
                                :aria-pressed="graphPanelVisible"
                                :class="
                                    cn(
                                        'h-6 w-8',
                                        graphPanelVisible
                                            ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30 hover:bg-emerald-400/20 hover:text-emerald-400'
                                            : 'text-muted-foreground hover:bg-background/80 hover:text-foreground',
                                    )
                                "
                                @click="
                                    setGraphPanelVisible(!graphPanelVisible)
                                "
                            >
                                <Workflow class="size-3" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ graphToggleTooltipLabel }}
                        </TooltipContent>
                    </Tooltip>

                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="logsToggleTooltipLabel"
                                :aria-pressed="logsPanelVisible"
                                :class="
                                    cn(
                                        'h-6 w-8',
                                        logsPanelVisible
                                            ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30 hover:bg-emerald-400/20 hover:text-emerald-400'
                                            : 'text-muted-foreground hover:bg-background/80 hover:text-foreground',
                                    )
                                "
                                @click="setLogsPanelVisible(!logsPanelVisible)"
                            >
                                <Logs class="size-3" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ logsToggleTooltipLabel }}
                        </TooltipContent>
                    </Tooltip>
                </div>
            </div>

            <div class="grow" />

            <div
                class="z-1 inline-flex items-center gap-1 rounded-lg border border-border/80 bg-muted/40 p-1"
            >
                <button
                    type="button"
                    class="rounded-md px-3 py-1 text-sm font-medium transition"
                    :class="
                        activeDeploymentType === 'development'
                            ? 'bg-sky-500/15 text-sky-300 shadow-sm ring-1 ring-sky-500/30'
                            : 'text-muted-foreground hover:bg-sky-500/10'
                    "
                    :disabled="headerControlsDisabled"
                    @click="setActiveDeployment('development')"
                >
                    {{ t('environments.development') }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1 text-sm font-medium transition"
                    :class="
                        activeDeploymentType === 'production'
                            ? 'bg-amber-500/15 text-amber-300 shadow-sm ring-1 ring-amber-500/30'
                            : 'text-muted-foreground hover:bg-amber-500/10'
                    "
                    :disabled="headerControlsDisabled"
                    @click="setActiveDeployment('production')"
                >
                    {{ t('environments.production') }}
                </button>
            </div>

            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        size="icon"
                        variant="secondary"
                        class="z-1"
                        :disabled="saveActionDisabled"
                        :aria-label="saveTooltipLabel"
                        @click="save"
                    >
                        <Save class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    {{ saveTooltipLabel }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="currentDeploymentActive">
                <TooltipTrigger as-child>
                    <Button
                        class="z-1"
                        size="icon"
                        :disabled="runActionDisabled"
                        :aria-label="restartTooltipLabel"
                        @click="restartFlow"
                    >
                        <RotateCcw class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    {{ restartTooltipLabel }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="currentDeploymentActive">
                <TooltipTrigger as-child>
                    <Button
                        class="z-1"
                        size="icon"
                        variant="outline"
                        :disabled="stopActionDisabled"
                        :aria-label="stopTooltipLabel"
                        @click="stopFlow"
                    >
                        <Square class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    {{ stopTooltipLabel }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-else>
                <TooltipTrigger as-child>
                    <Button
                        class="z-1"
                        size="icon"
                        :disabled="runActionDisabled"
                        :aria-label="startTooltipLabel"
                        @click="runFlow"
                    >
                        <Play class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    {{ startTooltipLabel }}
                </TooltipContent>
            </Tooltip>
        </template>

        <div
            class="mx-auto flex h-full max-h-[calc(100vh-64px)] w-full flex-1 flex-col"
        >
            <section ref="workspaceSection" class="h-full" @click="focusEditor">
                <StackedSidePanelsLayout
                    :top-active="graphPanelVisible"
                    :bottom-active="logsPanelVisible"
                    :main-ratio="1.3"
                    :side-ratio="1"
                    :top-ratio="1"
                    :bottom-ratio="1"
                    :resizable="true"
                    :resize-state="sidePanelResizeState"
                    @update:resize-state="setSidePanelResizeState"
                >
                    <template #main>
                        <div
                            v-if="activeWorkspaceTab === 'editor'"
                            class="flex h-full flex-col"
                        >
                            <div
                                class="relative h-full overflow-hidden bg-linear-to-br from-background to-muted/25"
                            >
                                <FlowCodeEditor
                                    ref="codeEditor"
                                    id="flow-code"
                                    v-model="form.code"
                                    :disabled="!props.permissions.canUpdate"
                                    class="h-full"
                                    bottom-padding="3rem"
                                />

                                <div
                                    class="pointer-events-none absolute right-3 bottom-3 flex items-center gap-2 rounded-md bg-background/85 px-2 py-1 text-xs text-muted-foreground shadow-sm backdrop-blur-sm"
                                >
                                    <span>
                                        {{ t('common.updated_at') }}:
                                        {{
                                            formatRecentDate(
                                                syncedFlowState.codeUpdatedAt,
                                            )
                                        }}
                                    </span>

                                    <TooltipProvider
                                        v-if="codeErrorMessages.length"
                                        :delay-duration="0"
                                    >
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <span
                                                    class="pointer-events-auto inline-flex cursor-help items-center rounded-sm border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 font-medium text-destructive"
                                                >
                                                    {{
                                                        codeErrorMessages.length
                                                    }}
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                class="max-w-xs space-y-1 text-xs"
                                            >
                                                <p
                                                    v-for="(
                                                        message, index
                                                    ) in codeErrorMessages"
                                                    :key="`code-error-${index}`"
                                                    class="break-words whitespace-pre-wrap"
                                                >
                                                    {{ message }}
                                                </p>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else-if="activeWorkspaceTab === 'chat'"
                            class="h-full"
                        >
                            <FlowEditorChatPanel
                                class="h-full rounded-none border-0"
                                v-model:draft="chatDraft"
                                :chat="activeChat"
                                :messages="displayedChatMessages"
                                :can-update="props.permissions.canUpdate"
                                :pending="chatPending"
                                :current-code="form.code"
                                :all-chats-url="props.allChatsUrl"
                                :format-recent-date="formatRecentDate"
                                variant="plain"
                                @send="sendChatMessage"
                                @retry="retryChatMessage"
                                @new-chat="startNewChat"
                                @compact="compactChat"
                                @apply-proposal="applyProposal"
                                @apply-and-save-proposal="applyAndSaveProposal"
                            />
                        </div>

                        <div
                            v-else-if="activeWorkspaceTab === 'discovery'"
                            class="h-full"
                        >
                            <div class="h-full overflow-hidden bg-muted/15">
                                <FlowDiscoveryPanel
                                    :graph="displayGraph"
                                    :hidden-node-ids="hiddenNodeIds"
                                    :webhook-endpoints="
                                        discoveryWebhookEndpoints
                                    "
                                    :outdated="graphIsOutdated"
                                    :selected-target="selectedDiscoveryTarget"
                                    @jump-to-code="jumpToCode"
                                    @node-select="focusDiscoveryNode"
                                    @toggle-node-visibility="
                                        toggleHiddenNodeVisibility
                                    "
                                />
                            </div>
                        </div>

                        <FlowStoragePanel
                            v-else-if="activeWorkspaceTab === 'storage'"
                            v-model:content="activeStorageContent"
                            editor-id="flow-storage"
                            :readonly="activeStorageReadonlyReason !== null"
                            :readonly-reason="activeStorageReadonlyReason"
                            :saving="storageSaveInFlight"
                            :dirty="activeStorageIsDirty"
                            :error-message="activeStorageErrorMessage"
                            show-save-button
                            @save="saveStorage"
                        />

                        <template v-else-if="activeWorkspaceTab === 'changes'">
                            <FlowChangesPanel
                                :history="props.history"
                                :current-code="form.code ?? ''"
                            />
                        </template>
                    </template>

                    <template #top>
                        <FlowGraph
                            ref="flowGraph"
                            class="h-full min-h-0"
                            :graph="displayGraph"
                            :hidden-node-ids="hiddenNodeIds"
                            :meta="graphMeta"
                            :webhook-endpoints="discoveryWebhookEndpoints"
                            :outdated="graphIsOutdated"
                            variant="plain"
                            @jump-to-code="jumpToCode"
                            @node-select="openDiscoveryNode"
                            @toggle-node-visibility="toggleHiddenNodeVisibility"
                        />
                    </template>

                    <template #bottom>
                        <FlowLogsPanel
                            class="h-full min-h-0"
                            :logs="displayDeploymentLogs"
                            :stream-key="selectedDeployment?.id ?? null"
                            :empty-message="
                                activeStorageEnvironment === 'production'
                                    ? t('flows.logs.empty_prod')
                                    : t('flows.logs.empty_dev')
                            "
                            variant="plain"
                            compact
                            @dispatch-edge-highlight="highlightDispatchPath"
                            @log-edge-focus="focusEdgeHighlight"
                            @log-edge-hover="setHoveredEdgeHighlight"
                            @select-node="handleLogNodeSelection"
                        />
                    </template>
                </StackedSidePanelsLayout>
            </section>
        </div>
    </AppLayout>
</template>
