<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlowLog {
    id: number;
    level?: string | null;
    message?: string | null;
    node_key?: string | null;
    context?: Record<string, unknown> | null;
    created_at: string;
}

interface ParsedLogEvent {
    label: string;
    message: string | null;
    statusState: string | null;
}

interface DisplayLog extends FlowLog {
    levelLabel: string;
    levelClass: string;
    eventLabel: string | null;
    renderedMessage: string | null;
}

const props = withDefaults(
    defineProps<{
        logs: FlowLog[];
        emptyMessage: string;
        compact?: boolean;
        dense?: boolean;
    }>(),
    {
        compact: false,
        dense: false,
    },
);

const { t, te } = useI18n();

const logsContainerRef = ref<HTMLElement | null>(null);
const scrollBottomThreshold = 12;

const eventMessagePattern = /^Event:\s*(.+)$/i;

const asRecord = (value: unknown): Record<string, unknown> | null => {
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
        return value as Record<string, unknown>;
    }

    return null;
};

const toReadableEventLabel = (value: string): string => {
    return value
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z\d])([A-Z])/g, '$1 $2')
        .trim()
        .replace(/\s+/g, ' ')
        .split(' ')
        .map((word) =>
            word ? `${word.charAt(0).toUpperCase()}${word.slice(1)}` : word,
        )
        .join(' ');
};

const resolveEventLabel = (eventKey: string): string => {
    const translationKey = `flows.logs.events.${eventKey}`;

    if (te(translationKey)) {
        return t(translationKey);
    }

    return toReadableEventLabel(eventKey);
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

const extractActorMessageText = (
    context: Record<string, unknown> | null,
): string | null => {
    const rawMessage = context?.message;

    if (typeof rawMessage !== 'string') {
        return null;
    }

    const trimmed = rawMessage.trim();

    return trimmed ? trimmed : null;
};

const normalizeStatusValue = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim().toLowerCase();

    return normalized ? normalized : null;
};

const resolveStatusLabel = (status: string): string => {
    const translationKey = `statuses.${status}`;

    if (te(translationKey)) {
        return t(translationKey);
    }

    return toReadableEventLabel(status);
};

const formatStatusTransition = (
    fromStatus: string,
    toStatus: string,
): string => {
    return `${resolveStatusLabel(fromStatus)} -> ${resolveStatusLabel(toStatus)}`;
};

const extractContextStatusState = (
    context: Record<string, unknown> | null,
): string | null => {
    const statusRecord = asRecord(context?.status);

    return (
        normalizeStatusValue(statusRecord?.state) ??
        normalizeStatusValue(context?.to_status) ??
        normalizeStatusValue(context?.status)
    );
};

const extractExplicitStatusTransitionText = (
    context: Record<string, unknown> | null,
): string | null => {
    const details = asRecord(context?.details);
    const fromStatus =
        normalizeStatusValue(details?.from_status) ??
        normalizeStatusValue(context?.from_status);
    const toStatus =
        normalizeStatusValue(details?.to_status) ??
        normalizeStatusValue(context?.to_status);

    if (!fromStatus || !toStatus) {
        return null;
    }

    return formatStatusTransition(fromStatus, toStatus);
};

const isInlineOperationalMessage = (message: string): boolean => {
    const normalized = message.trim();

    if (!normalized || normalized.length > 120 || normalized.includes('\n')) {
        return false;
    }

    return (
        /\bdeployment\b/i.test(normalized) &&
        /\b(requested|started|stopped|finished|completed|cancelled)\b/i.test(
            normalized,
        )
    );
};

const extractActivityMessageText = (
    activityType: string,
    context: Record<string, unknown> | null,
): string | null => {
    if (activityType !== 'container_message') {
        return null;
    }

    const details = asRecord(context?.details);
    const rawPreview = details?.message_preview;

    if (typeof rawPreview !== 'string') {
        return null;
    }

    const trimmed = rawPreview.trim();

    return trimmed ? trimmed : null;
};

const stringValue = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed ? trimmed : null;
};

const resolveActivityLabel = (
    activityType: string,
    details: Record<string, unknown> | null,
): string => {
    if (activityType === 'actor_invoked') {
        const actor = stringValue(details?.actor) ?? t('common.unknown');
        const triggerEvent =
            stringValue(details?.trigger_event) ?? t('common.unknown');

        return t('flows.logs.events.actor_invoked_label', {
            actor,
            event: triggerEvent,
        });
    }

    if (activityType === 'actor_dispatched') {
        const actor = stringValue(details?.actor) ?? t('common.unknown');
        const dispatchedEvent =
            stringValue(details?.dispatched_event) ?? t('common.unknown');

        return t('flows.logs.events.actor_dispatched_label', {
            actor,
            event: dispatchedEvent,
        });
    }

    return resolveEventLabel(activityType);
};

const resolveActivityMessage = (
    activityType: string,
    context: Record<string, unknown> | null,
    details: Record<string, unknown> | null,
    explicitTransition: string | null,
): string | null => {
    if (explicitTransition) {
        return explicitTransition;
    }

    if (activityType === 'cron_system_event') {
        const dispatchCount = details?.dispatch_count;
        const timezoneName = stringValue(details?.timezone) ?? 'UTC';

        if (typeof dispatchCount === 'number') {
            return t('flows.logs.events.cron_system_event_message', {
                count: dispatchCount,
                timezone: timezoneName,
            });
        }
    }

    return extractActivityMessageText(activityType, context);
};

const resolveRuntimeEventLabel = (
    context: Record<string, unknown> | null,
): string => {
    const kind = stringValue(context?.kind);
    const actor = stringValue(context?.actor) ?? t('common.unknown');
    const event = stringValue(context?.event) ?? t('common.unknown');
    const triggerEvent =
        stringValue(context?.trigger_event) ?? t('common.unknown');

    if (kind === 'actor_invoked') {
        return t('flows.logs.events.runtime_actor_invoked_label', {
            actor,
            event: triggerEvent,
        });
    }

    if (kind === 'event_dispatched') {
        return t('flows.logs.events.runtime_event_dispatched_label', {
            actor,
            event,
        });
    }

    if (kind === 'actor_error') {
        return t('flows.logs.events.runtime_actor_error_label', {
            actor,
        });
    }

    if (kind === 'runtime_error') {
        return t('flows.logs.events.runtime_runtime_error_label');
    }

    if (kind === 'cron_template_error') {
        return t('flows.logs.events.runtime_cron_template_error_label', {
            actor,
        });
    }

    return resolveEventLabel('flow_runtime_event');
};

const resolveRuntimeEventMessage = (
    context: Record<string, unknown> | null,
): string | null => {
    const payload = asRecord(context?.payload);
    const kind = stringValue(context?.kind);
    const event = stringValue(context?.event);

    if (kind === 'event_dispatched' && event === 'Message') {
        return stringValue(payload?.message);
    }

    if (
        kind === 'actor_error' ||
        kind === 'runtime_error' ||
        kind === 'cron_template_error'
    ) {
        return stringValue(payload?.error);
    }

    return null;
};

const parseLogEvent = (log: FlowLog): ParsedLogEvent | null => {
    const eventKey = extractEventKey(log.message);

    if (!eventKey) {
        return null;
    }

    const context = asRecord(log.context);

    if (eventKey === 'activity_log' || eventKey === 'activity') {
        const activityType =
            typeof context?.type === 'string' ? context.type.trim() : '';
        const details = asRecord(context?.details);
        const activityStatus = normalizeStatusValue(details?.status);
        const explicitTransition =
            activityType === 'status_change'
                ? extractExplicitStatusTransitionText(context)
                : null;

        if (!activityType) {
            return {
                label: resolveEventLabel(eventKey),
                message: null,
                statusState: null,
            };
        }

        if (activityType === 'actor_event') {
            const details = asRecord(context?.details);
            const customEvent =
                typeof details?.event === 'string' ? details.event.trim() : '';

            if (customEvent) {
                return {
                    label: customEvent,
                    message: null,
                    statusState: null,
                };
            }
        }

        return {
            label: resolveActivityLabel(activityType, details),
            message: resolveActivityMessage(
                activityType,
                context,
                details,
                explicitTransition,
            ),
            statusState: activityStatus,
        };
    }

    if (eventKey === 'actor_message') {
        return {
            label: resolveEventLabel(eventKey),
            message: extractActorMessageText(context),
            statusState: null,
        };
    }

    if (eventKey === 'flow_runtime_event') {
        return {
            label: resolveRuntimeEventLabel(context),
            message: resolveRuntimeEventMessage(context),
            statusState: null,
        };
    }

    const statusState =
        eventKey === 'container_status_update' ||
        eventKey === 'status_changed' ||
        eventKey === 'status_change'
            ? extractContextStatusState(context)
            : null;

    const explicitTransition =
        eventKey === 'status_changed' || eventKey === 'status_change'
            ? extractExplicitStatusTransitionText(context)
            : null;

    return {
        label: resolveEventLabel(eventKey),
        message: explicitTransition,
        statusState,
    };
};

const resolveLogLevelClass = (level?: string | null): string => {
    switch ((level ?? '').toLowerCase()) {
        case 'error':
        case 'critical':
            return 'text-destructive/70';
        case 'warning':
        case 'warn':
            return 'text-amber-500/70';
        case 'info':
            return 'text-sky-500/70';
        default:
            return 'text-muted-foreground/80';
    }
};

const resolveLogLevelLabel = (level?: string | null): string => {
    const normalized = level?.trim();

    if (!normalized) {
        return t('common.unknown').toUpperCase();
    }

    return normalized.toUpperCase();
};

const resolveRenderedMessage = (
    log: FlowLog,
    parsedEvent: ParsedLogEvent | null,
    inlineLabel: string | null,
    statusTransitionMessage: string | null,
): string | null => {
    if (parsedEvent?.message) {
        return parsedEvent.message;
    }

    if (statusTransitionMessage) {
        return statusTransitionMessage;
    }

    if (parsedEvent || inlineLabel) {
        return null;
    }

    const trimmed = log.message?.trim();

    return trimmed ? trimmed : null;
};

const displayLogs = computed<DisplayLog[]>(() => {
    let previousStatusState: string | null = null;

    return [...props.logs].reverse().map((log) => {
        const parsedEvent = parseLogEvent(log);
        const plainMessage = log.message?.trim() ?? '';
        const inlineLabel =
            parsedEvent?.label ??
            (isInlineOperationalMessage(plainMessage) ? plainMessage : null);
        let statusTransitionMessage: string | null = null;

        if (parsedEvent?.statusState) {
            if (
                previousStatusState &&
                previousStatusState !== parsedEvent.statusState
            ) {
                statusTransitionMessage = formatStatusTransition(
                    previousStatusState,
                    parsedEvent.statusState,
                );
            }

            previousStatusState = parsedEvent.statusState;
        }

        return {
            ...log,
            levelLabel: resolveLogLevelLabel(log.level),
            levelClass: resolveLogLevelClass(log.level),
            eventLabel: inlineLabel,
            renderedMessage: resolveRenderedMessage(
                log,
                parsedEvent,
                inlineLabel,
                statusTransitionMessage,
            ),
        };
    });
});

const formatDate = (value?: string | null) => {
    if (!value) return t('common.empty');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const itemPaddingClass = computed(() =>
    props.dense ? 'p-1.5' : props.compact ? 'p-2' : 'p-3',
);

const messageClass = computed(() =>
    props.dense
        ? 'mt-1 text-xs leading-relaxed text-foreground'
        : 'mt-2 text-xs text-foreground',
);

const containerClass = computed(() => {
    return 'divide-y overflow-y-auto rounded-md border bg-muted/40';
});

const isScrolledToBottom = (): boolean => {
    const container = logsContainerRef.value;

    if (!container) {
        return true;
    }

    const distanceToBottom =
        container.scrollHeight - container.scrollTop - container.clientHeight;

    return distanceToBottom <= scrollBottomThreshold;
};

const scrollToBottom = (): void => {
    const container = logsContainerRef.value;

    if (!container) {
        return;
    }

    container.scrollTop = container.scrollHeight;
};

onMounted(async () => {
    await nextTick();
    scrollToBottom();
});

watch(
    () => ({
        length: props.logs.length,
        newestLogId: props.logs[0]?.id ?? null,
    }),
    async (state, previousState) => {
        const shouldStickToBottom = isScrolledToBottom();
        const previousLength = previousState?.length ?? 0;
        const hadLogsBeforeUpdate = previousLength > 0;
        const hasIncomingLogs =
            state.length > previousLength ||
            (hadLogsBeforeUpdate &&
                state.newestLogId !== previousState?.newestLogId);

        await nextTick();

        if (!hadLogsBeforeUpdate && state.length > 0) {
            scrollToBottom();
            return;
        }

        if (hasIncomingLogs && shouldStickToBottom) {
            scrollToBottom();
        }
    },
);
</script>

<template>
    <div v-if="logs.length" ref="logsContainerRef" :class="containerClass">
        <div v-for="log in displayLogs" :key="log.id" :class="itemPaddingClass">
            <div
                class="flex items-center justify-between text-xs text-muted-foreground"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <span
                        class="tracking-wide uppercase"
                        :class="log.levelClass"
                    >
                        {{ log.levelLabel }}
                    </span>
                    <span
                        v-if="log.eventLabel"
                        class="truncate text-muted-foreground"
                    >
                        {{ log.eventLabel }}
                    </span>
                </div>
                <span>{{ formatDate(log.created_at) }}</span>
            </div>
            <p v-if="log.renderedMessage" :class="messageClass">
                {{ log.renderedMessage }}
            </p>
            <p v-if="log.node_key" class="text-xs text-muted-foreground">
                {{ t('flows.logs.node', { node: log.node_key }) }}
            </p>
        </div>
    </div>
    <div
        v-else
        class="flex items-center justify-center rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
    >
        {{ emptyMessage }}
    </div>
</template>
