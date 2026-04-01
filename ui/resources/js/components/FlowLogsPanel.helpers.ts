import type { DispatchPathHighlight } from './flows/graphHighlights';

interface FlowLogRecord {
    id: number;
    message?: string | null;
    context?: Record<string, unknown> | null;
}

const eventMessagePattern = /^Event:\s*(.+)$/i;

const asRecord = (value: unknown): Record<string, unknown> | null => {
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
        return value as Record<string, unknown>;
    }

    return null;
};

const stringValue = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed ? trimmed : null;
};

const extractEventKey = (message?: string | null): string | null => {
    const trimmed = message?.trim();

    if (!trimmed) {
        return null;
    }

    const match = trimmed.match(eventMessagePattern);

    if (!match?.[1]) {
        return null;
    }

    return match[1].trim();
};

export const resolveDispatchPathHighlight = (
    log: FlowLogRecord,
): DispatchPathHighlight | null => {
    if (extractEventKey(log.message) !== 'flow_runtime_event') {
        return null;
    }

    const context = asRecord(log.context);
    if (stringValue(context?.kind) !== 'event_dispatched') {
        return null;
    }

    const actor = stringValue(context?.actor);
    const event = stringValue(context?.event);

    if (!actor || !event) {
        return null;
    }

    return {
        actor,
        event,
        triggerEvent: stringValue(context?.trigger_event),
    };
};

export const resolveNewLogs = <TLog extends { id: number }>(
    nextLogs: TLog[],
    previousNewestId: number | null | undefined,
): TLog[] => {
    if (typeof previousNewestId !== 'number') {
        return [];
    }

    const previousNewestIndex = nextLogs.findIndex(
        (log) => log.id === previousNewestId,
    );

    if (previousNewestIndex === 0) {
        return [];
    }

    if (previousNewestIndex > 0) {
        return nextLogs.slice(0, previousNewestIndex);
    }

    return nextLogs.filter((log) => log.id > previousNewestId);
};
