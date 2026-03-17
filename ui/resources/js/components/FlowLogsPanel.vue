<script setup lang="ts">
import {
    computed,
    nextTick,
    onMounted,
    ref,
    useAttrs,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface FlowLog {
    id: number;
    level?: string | null;
    message?: string | null;
    node_key?: string | null;
    context?: Record<string, unknown> | null;
    created_at: string;
}

interface FlowTarget {
    id: string;
    type: 'actor' | 'event';
}

interface PayloadPreviewEntry {
    key: string;
    value: string;
}

interface LogLabelSegment {
    kind: 'text' | 'actor' | 'event';
    text: string;
    target?: FlowTarget;
    payloadPreview?: PayloadPreviewEntry[] | null;
}

interface ParsedLogEvent {
    labelSegments: LogLabelSegment[];
    message: string | null;
    statusState: string | null;
}

interface DisplayLog extends FlowLog {
    levelLabel: string;
    levelClass: string;
    labelSegments: LogLabelSegment[];
    renderedMessage: string | null;
}

const props = withDefaults(
    defineProps<{
        logs: FlowLog[];
        emptyMessage: string;
        compact?: boolean;
        dense?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        compact: false,
        dense: false,
    },
);

const emit = defineEmits<{
    'select-node': [payload: FlowTarget];
}>();

const { t, te } = useI18n();
const attrs = useAttrs();

const logsContainerRef = ref<HTMLElement | null>(null);
const scrollBottomThreshold = 12;
const payloadPreviewLimit = 6;
const inlineValueMaxLength = 80;

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

const truncateInlineValue = (value: string): string => {
    const compactValue = value.replace(/\s+/g, ' ').trim();

    if (compactValue.length <= inlineValueMaxLength) {
        return compactValue;
    }

    return `${compactValue.slice(0, inlineValueMaxLength - 1)}...`;
};

const formatPayloadValue = (value: unknown, depth = 0): string | null => {
    if (typeof value === 'string') {
        return truncateInlineValue(value);
    }

    if (
        typeof value === 'number' ||
        typeof value === 'boolean' ||
        value === null
    ) {
        return String(value);
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return '[]';
        }

        if (depth > 0) {
            return `[${value.length}]`;
        }

        return truncateInlineValue(
            `[${value
                .slice(0, 3)
                .map((item) => formatPayloadValue(item, depth + 1) ?? '...')
                .join(', ')}${value.length > 3 ? ', ...' : ''}]`,
        );
    }

    const record = asRecord(value);

    if (!record) {
        return null;
    }

    const entries = Object.entries(record);

    if (entries.length === 0) {
        return '{}';
    }

    if (depth > 0) {
        return `{${entries.length}}`;
    }

    return truncateInlineValue(
        `{ ${entries
            .slice(0, 3)
            .map(([key, nestedValue]) => {
                const renderedValue = formatPayloadValue(nestedValue, depth + 1);

                return renderedValue === null ? key : `${key}: ${renderedValue}`;
            })
            .join(', ')}${entries.length > 3 ? ', ...' : ''} }`,
    );
};

const extractPayloadPreview = (value: unknown): PayloadPreviewEntry[] | null => {
    const payload = asRecord(value);

    if (!payload) {
        return null;
    }

    const preview = Object.entries(payload)
        .map(([key, payloadValue]) => {
            const formattedValue = formatPayloadValue(payloadValue);

            if (formattedValue === null) {
                return null;
            }

            return {
                key,
                value: formattedValue,
            };
        })
        .filter((entry): entry is PayloadPreviewEntry => entry !== null)
        .slice(0, payloadPreviewLimit);

    return preview.length > 0 ? preview : null;
};

const createTextSegment = (text: string): LogLabelSegment => ({
    kind: 'text',
    text,
});

const createActorSegment = (actor: string): LogLabelSegment => ({
    kind: 'actor',
    text: actor,
    target: {
        id: actor,
        type: 'actor',
    },
});

const createEventSegment = (
    event: string,
    payloadPreview: PayloadPreviewEntry[] | null,
): LogLabelSegment => ({
    kind: 'event',
    text: event,
    target: {
        id: event,
        type: 'event',
    },
    payloadPreview,
});

const createPlainLabelSegments = (label: string): LogLabelSegment[] => {
    return [createTextSegment(label)];
};

const createActorInvokedSegments = (
    actor: string,
    event: string,
    payloadPreview: PayloadPreviewEntry[] | null,
): LogLabelSegment[] => {
    return [
        createTextSegment(t('flows.logs.inline.actor_prefix')),
        createActorSegment(actor),
        createTextSegment(t('flows.logs.inline.invoked_by')),
        createEventSegment(event, payloadPreview),
    ];
};

const createActorDispatchedSegments = (
    actor: string,
    event: string,
    payloadPreview: PayloadPreviewEntry[] | null,
): LogLabelSegment[] => {
    return [
        createTextSegment(t('flows.logs.inline.actor_prefix')),
        createActorSegment(actor),
        createTextSegment(t('flows.logs.inline.dispatched_event')),
        createEventSegment(event, payloadPreview),
    ];
};

const createActorFailedSegments = (actor: string): LogLabelSegment[] => {
    return [
        createTextSegment(t('flows.logs.inline.actor_prefix')),
        createActorSegment(actor),
        createTextSegment(t('flows.logs.inline.failed_suffix')),
    ];
};

const createCronTemplateErrorSegments = (actor: string): LogLabelSegment[] => {
    return [
        createTextSegment(t('flows.logs.inline.cron_template_error_prefix')),
        createActorSegment(actor),
    ];
};

const resolveActivityLabelSegments = (
    activityType: string,
    details: Record<string, unknown> | null,
    context: Record<string, unknown> | null,
): LogLabelSegment[] => {
    const payloadPreview = extractPayloadPreview(context?.payload);

    if (activityType === 'actor_invoked') {
        const actor = stringValue(details?.actor) ?? t('common.unknown');
        const triggerEvent =
            stringValue(details?.trigger_event) ?? t('common.unknown');

        return createActorInvokedSegments(actor, triggerEvent, payloadPreview);
    }

    if (activityType === 'actor_dispatched') {
        const actor = stringValue(details?.actor) ?? t('common.unknown');
        const dispatchedEvent =
            stringValue(details?.dispatched_event) ?? t('common.unknown');

        return createActorDispatchedSegments(
            actor,
            dispatchedEvent,
            payloadPreview,
        );
    }

    if (activityType === 'actor_event') {
        const customEvent = stringValue(details?.event);

        if (customEvent) {
            return [createEventSegment(customEvent, payloadPreview)];
        }
    }

    return createPlainLabelSegments(resolveEventLabel(activityType));
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

const resolveRuntimeEventLabelSegments = (
    context: Record<string, unknown> | null,
): LogLabelSegment[] => {
    const payloadPreview = extractPayloadPreview(context?.payload);
    const kind = stringValue(context?.kind);
    const actor = stringValue(context?.actor) ?? t('common.unknown');
    const event = stringValue(context?.event) ?? t('common.unknown');
    const triggerEvent =
        stringValue(context?.trigger_event) ?? t('common.unknown');

    if (kind === 'actor_invoked') {
        return createActorInvokedSegments(actor, triggerEvent, payloadPreview);
    }

    if (kind === 'event_dispatched') {
        return createActorDispatchedSegments(actor, event, payloadPreview);
    }

    if (kind === 'actor_error') {
        return createActorFailedSegments(actor);
    }

    if (kind === 'runtime_error') {
        return createPlainLabelSegments(
            t('flows.logs.events.runtime_runtime_error_label'),
        );
    }

    if (kind === 'cron_template_error') {
        return createCronTemplateErrorSegments(actor);
    }

    return createPlainLabelSegments(resolveEventLabel('flow_runtime_event'));
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
                labelSegments: createPlainLabelSegments(
                    resolveEventLabel(eventKey),
                ),
                message: null,
                statusState: null,
            };
        }

        return {
            labelSegments: resolveActivityLabelSegments(
                activityType,
                details,
                context,
            ),
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
            labelSegments: createPlainLabelSegments(resolveEventLabel(eventKey)),
            message: extractActorMessageText(context),
            statusState: null,
        };
    }

    if (eventKey === 'flow_runtime_event') {
        return {
            labelSegments: resolveRuntimeEventLabelSegments(context),
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
        labelSegments: createPlainLabelSegments(resolveEventLabel(eventKey)),
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
    inlineLabelSegments: LogLabelSegment[],
    statusTransitionMessage: string | null,
): string | null => {
    if (parsedEvent?.message) {
        return parsedEvent.message;
    }

    if (statusTransitionMessage) {
        return statusTransitionMessage;
    }

    if (parsedEvent || inlineLabelSegments.length > 0) {
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
        const inlineLabelSegments =
            parsedEvent?.labelSegments ??
            (isInlineOperationalMessage(plainMessage)
                ? createPlainLabelSegments(plainMessage)
                : []);
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
            labelSegments: inlineLabelSegments,
            renderedMessage: resolveRenderedMessage(
                log,
                parsedEvent,
                inlineLabelSegments,
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

const isNodeSelectionEnabled = computed(() => {
    return Boolean(attrs.onSelectNode);
});

const nodeTokenClass =
    'inline rounded-sm border-b border-transparent text-foreground';

const nodeButtonClass =
    'inline rounded-sm border-b border-transparent text-foreground decoration-transparent transition hover:border-border hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/60';

const nodeLabelClass = 'cursor-default';

const selectNode = (target: FlowTarget | undefined, event: MouseEvent): void => {
    if (!target || !isNodeSelectionEnabled.value) {
        return;
    }

    event.stopPropagation();
    emit('select-node', target);
};

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
    <TooltipProvider v-if="logs.length" :delay-duration="120">
        <div ref="logsContainerRef" :class="cn(containerClass, props.class)">
            <div v-for="log in displayLogs" :key="log.id" :class="itemPaddingClass">
                <div
                    class="flex items-start justify-between gap-3 text-xs text-muted-foreground"
                >
                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                        <span
                            class="tracking-wide uppercase"
                            :class="log.levelClass"
                        >
                            {{ log.levelLabel }}
                        </span>
                        <span
                            v-if="log.labelSegments.length"
                            class="min-w-0 flex-1 text-muted-foreground"
                        >
                            <template
                                v-for="(segment, segmentIndex) in log.labelSegments"
                                :key="`${log.id}-${segment.kind}-${segmentIndex}`"
                            >
                                <span
                                    v-if="segment.kind === 'text'"
                                    class="whitespace-pre-wrap"
                                >
                                    {{ segment.text }}
                                </span>
                                <button
                                    v-else-if="segment.kind === 'actor' && isNodeSelectionEnabled"
                                    type="button"
                                    :class="nodeButtonClass"
                                    @click="selectNode(segment.target, $event)"
                                >
                                    {{ segment.text }}
                                </button>
                                <span
                                    v-else-if="segment.kind === 'actor'"
                                    :class="[nodeTokenClass, nodeLabelClass]"
                                >
                                    {{ segment.text }}
                                </span>
                                <Tooltip v-else-if="segment.payloadPreview?.length">
                                    <TooltipTrigger as-child>
                                        <component
                                            :is="isNodeSelectionEnabled ? 'button' : 'span'"
                                            :type="isNodeSelectionEnabled ? 'button' : undefined"
                                            :class="[
                                                nodeTokenClass,
                                                isNodeSelectionEnabled
                                                    ? nodeButtonClass
                                                    : nodeLabelClass,
                                            ]"
                                            @click="
                                                isNodeSelectionEnabled
                                                    ? selectNode(segment.target, $event)
                                                    : undefined
                                            "
                                        >
                                            {{ segment.text }}
                                        </component>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        side="bottom"
                                        align="start"
                                        class="max-w-sm space-y-1 text-xs"
                                    >
                                        <div
                                            v-for="entry in segment.payloadPreview"
                                            :key="`${segment.text}-${entry.key}`"
                                            class="grid grid-cols-[auto_1fr] gap-x-2"
                                        >
                                            <span class="font-medium text-foreground/90">
                                                {{ entry.key }}:
                                            </span>
                                            <span class="break-words text-muted-foreground">
                                                {{ entry.value }}
                                            </span>
                                        </div>
                                    </TooltipContent>
                                </Tooltip>
                                <button
                                    v-else-if="isNodeSelectionEnabled"
                                    type="button"
                                    :class="nodeButtonClass"
                                    @click="selectNode(segment.target, $event)"
                                >
                                    {{ segment.text }}
                                </button>
                                <span
                                    v-else
                                    :class="[nodeTokenClass, nodeLabelClass]"
                                >
                                    {{ segment.text }}
                                </span>
                            </template>
                        </span>
                    </div>
                    <span class="shrink-0">{{ formatDate(log.created_at) }}</span>
                </div>
                <p v-if="log.renderedMessage" :class="messageClass">
                    {{ log.renderedMessage }}
                </p>
                <p v-if="log.node_key" class="text-xs text-muted-foreground">
                    {{ t('flows.logs.node', { node: log.node_key }) }}
                </p>
            </div>
        </div>
    </TooltipProvider>
    <div
        v-else
        class="flex items-center justify-center rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
    >
        {{ emptyMessage }}
    </div>
</template>
