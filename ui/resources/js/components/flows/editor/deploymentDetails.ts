import type {
    FlowDeployment,
    FlowRun,
    GraphMeta,
} from '@/components/flows/editor/types';

type Translate = (key: string, params?: Record<string, unknown>) => string;

export const countGraphNodesByType = (
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

export const createDeploymentDetailsHelpers = (t: Translate) => {
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

    const formatDuration = (
        start?: string | null,
        end?: string | null,
    ): string => {
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
            return t('common.duration.hours', {
                hours,
                minutes: minutes % 60,
            });
        }

        if (minutes > 0) {
            return t('common.duration.minutes', {
                minutes,
                seconds: totalSeconds % 60,
            });
        }

        return t('common.duration.seconds', { seconds: totalSeconds });
    };

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

    return {
        formatDate,
        formatDuration,
        runTypeLabel,
        statusLabel,
        statusTone,
    };
};

export const buildDeploymentGraphMeta = (
    deployment: FlowDeployment,
    t: Translate,
    statusLabel: (status?: string | null) => string,
    formatDate: (value?: string | null) => string,
): GraphMeta => {
    return {
        actors: countGraphNodesByType(deployment.graph, 'actor'),
        events: countGraphNodesByType(deployment.graph, 'event'),
        status: statusLabel(deployment.status),
        freshnessLabel: t('common.updated_at'),
        updatedAt: formatDate(
            deployment.finished_at ??
                deployment.started_at ??
                deployment.created_at,
        ),
    };
};
