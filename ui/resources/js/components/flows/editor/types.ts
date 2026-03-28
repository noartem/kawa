import type { FlowSidebarItem } from '@/types';

export interface FlowLog {
    id: number;
    level?: string | null;
    message?: string | null;
    node_key?: string | null;
    context?: Record<string, unknown> | null;
    created_at: string;
}

export interface FlowRun {
    id: number;
    type: 'development' | 'production';
    active: boolean;
    status?: string | null;
    lock?: string | null;
    actors?: unknown[] | null;
    events?: unknown[] | null;
    started_at?: string | null;
    finished_at?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface FlowWebhookEndpoint {
    slug: string;
    source_line?: number | null;
    production_url?: string | null;
    development_url?: string | null;
}

export interface FlowDeployment extends FlowRun {
    container_id?: string | null;
    code?: string | null;
    graph?: Record<string, unknown> | null;
    webhooks?: FlowWebhookEndpoint[] | null;
    logs: FlowLog[];
}

export interface FlowHistory {
    id: number;
    code?: string | null;
    diff?: string | null;
    created_at: string;
}

export interface FlowChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    created_at?: string | null;
    kind?:
        | 'prompt'
        | 'assistant_reply'
        | 'code_suggestion'
        | 'compact_summary'
        | null;
    response_mode?: 'message_only' | 'message_with_code' | null;
    status?: 'pending' | 'error' | null;
    transient?: boolean;
    retryable?: boolean;
    source_code?: string | null;
    proposed_code?: string | null;
    diff?: string | null;
    has_code_changes: boolean;
}

export interface FlowChatConversation {
    id: string;
    title: string;
    created_at?: string | null;
    updated_at?: string | null;
    preview?: string | null;
    messages_count: number;
    messages: FlowChatMessage[];
}

export interface FlowChatsPaginator {
    data: FlowChatConversation[];
    current_page: number;
    from?: number | null;
    last_page: number;
    per_page: number;
    total: number;
    to?: number | null;
}

export type FlowChatsSortKey =
    | 'id'
    | 'title'
    | 'created_at'
    | 'updated_at'
    | 'messages_count';

export type FlowChatsSortDirection = 'asc' | 'desc';

export interface FlowDetail extends Omit<FlowSidebarItem, 'id' | 'slug'> {
    id?: number | null;
    slug?: string | null;
    description?: string | null;
    code?: string | null;
    code_updated_at?: string | null;
    runs_count?: number;
    container_id?: string | null;
    entrypoint?: string | null;
    image?: string | null;
    timezone?: string | null;
    last_started_at?: string | null;
    last_finished_at?: string | null;
    archived_at?: string | null;
    user?: {
        name?: string | null;
    };
}

export interface Permissions {
    canRun: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}

export interface RunStat {
    status: string;
    total: number;
}

export interface GraphMeta {
    actors: number;
    events: number;
    status: string;
    freshnessLabel: string;
    updatedAt: string;
}

export interface HistoryCard {
    item: FlowHistory;
    diffChanges: {
        added: number;
        removed: number;
    };
    originalCode: string;
    modifiedCode: string;
}

export interface DeploymentCard {
    deployment: FlowDeployment;
    graphMeta: GraphMeta;
}

export interface FlowDeploymentsPaginator {
    data: FlowDeployment[];
    current_page: number;
    from?: number | null;
    last_page: number;
    per_page: number;
    total: number;
    to?: number | null;
}

export type FlowDeploymentsSortKey =
    | 'id'
    | 'started_at'
    | 'finished_at'
    | 'created_at'
    | 'updated_at';

export type FlowDeploymentsSortDirection = 'asc' | 'desc';
