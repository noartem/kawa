<script setup lang="ts">
import type { FlowWebhookEndpoint } from '@/components/flows/editor/types';
import FlowWebhookQuickSender from '@/components/flows/editor/FlowWebhookQuickSender.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import cronstrue from 'cronstrue';
import 'cronstrue/locales/ru';
import { ArrowUpRight, ScanSearch } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

type DiscoveryItemType = 'actor' | 'event';
type SourceKind = 'main' | 'import';

interface DiscoverySelectionTarget {
    id: string;
    type: DiscoveryItemType;
    requestKey: number;
}

interface DiscoveryItemBase {
    id: string;
    name: string;
    type: DiscoveryItemType;
    sourceLine: number | null;
    sourceKind: SourceKind | null;
    sourceModule: string | null;
}

interface DiscoveryActor extends DiscoveryItemBase {
    type: 'actor';
    receives: string[];
    sends: string[];
}

interface DiscoveryEvent extends DiscoveryItemBase {
    type: 'event';
    consumedBy: string[];
    producedBy: string[];
    cronDescription: string | null;
}

interface DiscoveryEventView extends DiscoveryEvent {
    webhook: DiscoveryWebhookEndpoint | null;
    implementationLine: number | null;
}

interface DiscoveryWebhookEndpoint {
    slug: string;
    sourceLine: number | null;
    productionUrl: string | null;
    developmentUrl: string | null;
}

const props = withDefaults(
    defineProps<{
        graph?: Record<string, unknown> | null;
        webhookEndpoints?: FlowWebhookEndpoint[];
        selectedTarget?: DiscoverySelectionTarget | null;
        outdated?: boolean;
    }>(),
    {
        graph: null,
        webhookEndpoints: () => [],
        selectedTarget: null,
        outdated: false,
    },
);

const emit = defineEmits<{
    (event: 'jump-to-code', line: number): void;
}>();

const { locale, t } = useI18n();

const containerRef = ref<HTMLElement | null>(null);
const highlightedKey = ref<string | null>(null);

const itemRefs = new Map<string, HTMLElement>();

let highlightTimer: ReturnType<typeof setTimeout> | null = null;

const resolveGraphId = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim().length > 0) {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

const resolveSourceLine = (value: unknown): number | null => {
    if (!Number.isInteger(value)) {
        return null;
    }

    return value > 0 ? value : null;
};

const resolveSourceKind = (value: unknown): SourceKind | null => {
    return value === 'main' || value === 'import' ? value : null;
};

const resolveSourceModule = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const moduleName = value.trim();

    return moduleName.length > 0 ? moduleName : null;
};

const normalizeNames = (value: unknown): string[] => {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((item) => resolveGraphId(item))
        .filter((item): item is string => item !== null);
};

const uniqueNames = (items: string[]): string[] => {
    return [...new Set(items)];
};

const discoveryLocale = computed(() => {
    return locale.value === 'ru' ? 'ru' : 'en';
});

const extractCronExpression = (name: string): string | null => {
    const prefix = 'CronEvent.by(';
    if (!name.startsWith(prefix) || !name.endsWith(')')) {
        return null;
    }

    const rawExpression = name.slice(prefix.length, -1).trim();
    if (!rawExpression) {
        return null;
    }

    if (
        (rawExpression.startsWith('"') && rawExpression.endsWith('"')) ||
        (rawExpression.startsWith("'") && rawExpression.endsWith("'"))
    ) {
        const unwrappedExpression = rawExpression.slice(1, -1).trim();

        return unwrappedExpression.length > 0 ? unwrappedExpression : null;
    }

    return rawExpression;
};

const describeCronExpression = (name: string): string | null => {
    const cronExpression = extractCronExpression(name);
    if (!cronExpression) {
        return null;
    }

    try {
        return cronstrue.toString(cronExpression, {
            locale: discoveryLocale.value,
        });
    } catch {
        return null;
    }
};

const splitCronEventName = (
    name: string,
): { prefix: string; expression: string; suffix: string } | null => {
    const cronExpression = extractCronExpression(name);
    if (!cronExpression) {
        return null;
    }

    const expressionIndex = name.indexOf(cronExpression);
    if (expressionIndex === -1) {
        return null;
    }

    return {
        prefix: name.slice(0, expressionIndex),
        expression: cronExpression,
        suffix: name.slice(expressionIndex + cronExpression.length),
    };
};

const extractWebhookSlug = (name: string): string | null => {
    const match = name.match(/^Webhook(?:Event)?\.by\(\s*(.+?)\s*\)$/);

    if (!match) {
        return null;
    }

    let slug = match[1]?.trim() ?? '';

    if (
        (slug.startsWith('"') && slug.endsWith('"')) ||
        (slug.startsWith("'") && slug.endsWith("'"))
    ) {
        slug = slug.slice(1, -1).trim();
    }

    return slug.length > 0 ? slug : null;
};

const buildItemKey = (type: DiscoveryItemType, id: string): string => {
    return `${type}:${id}`;
};

const setItemRef = (
    type: DiscoveryItemType,
    id: string,
    element: Element | null,
): void => {
    const key = buildItemKey(type, id);

    if (!(element instanceof HTMLElement)) {
        itemRefs.delete(key);
        return;
    }

    itemRefs.set(key, element);
};

const nodeMetadataById = computed(() => {
    const rawNodes = Array.isArray(props.graph?.nodes) ? props.graph.nodes : [];
    const metadata = new Map<
        string,
        {
            sourceLine: number | null;
            sourceKind: SourceKind | null;
            sourceModule: string | null;
        }
    >();

    for (const rawNode of rawNodes) {
        if (!rawNode || typeof rawNode !== 'object') {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const id = resolveGraphId(node.id ?? node.name);
        if (!id) {
            continue;
        }

        metadata.set(id, {
            sourceLine: resolveSourceLine(node.source_line),
            sourceKind: resolveSourceKind(node.source_kind),
            sourceModule: resolveSourceModule(node.source_module),
        });
    }

    return metadata;
});

const edgeLinks = computed(() => {
    const rawEdges = Array.isArray(props.graph?.edges) ? props.graph.edges : [];
    const eventToActors = new Map<string, string[]>();
    const actorToEvents = new Map<string, string[]>();

    for (const rawEdge of rawEdges) {
        if (!rawEdge || typeof rawEdge !== 'object') {
            continue;
        }

        const edge = rawEdge as Record<string, unknown>;
        const from = resolveGraphId(edge.from);
        const to = resolveGraphId(edge.to);
        if (!from || !to) {
            continue;
        }

        const eventConsumers = eventToActors.get(from) ?? [];
        eventConsumers.push(to);
        eventToActors.set(from, eventConsumers);

        const actorOutputs = actorToEvents.get(from) ?? [];
        actorOutputs.push(to);
        actorToEvents.set(from, actorOutputs);
    }

    return {
        eventToActors,
        actorToEvents,
    };
});

const actors = computed<DiscoveryActor[]>(() => {
    const rawActors = Array.isArray(props.graph?.actors)
        ? props.graph.actors
        : [];

    if (rawActors.length === 0) {
        const rawNodes = Array.isArray(props.graph?.nodes)
            ? props.graph.nodes
            : [];

        return rawNodes
            .flatMap((rawNode) => {
                if (!rawNode || typeof rawNode !== 'object') {
                    return [];
                }

                const node = rawNode as Record<string, unknown>;
                if (node.type !== 'actor') {
                    return [];
                }

                const id = resolveGraphId(node.id ?? node.name);
                if (!id) {
                    return [];
                }

                return [
                    {
                        id,
                        name:
                            resolveGraphId(
                                node.name ?? node.label ?? node.id,
                            ) ?? id,
                        type: 'actor' as const,
                        receives: uniqueNames(
                            [...edgeLinks.value.eventToActors.entries()]
                                .filter(([, targets]) => targets.includes(id))
                                .map(([eventId]) => eventId),
                        ),
                        sends: uniqueNames(
                            edgeLinks.value.actorToEvents.get(id) ?? [],
                        ),
                        sourceLine: resolveSourceLine(node.source_line),
                        sourceKind: resolveSourceKind(node.source_kind),
                        sourceModule: resolveSourceModule(node.source_module),
                    },
                ];
            })
            .sort((left, right) => left.name.localeCompare(right.name));
    }

    return rawActors
        .flatMap((rawActor) => {
            if (!rawActor || typeof rawActor !== 'object') {
                return [];
            }

            const actor = rawActor as Record<string, unknown>;
            const id = resolveGraphId(actor.id ?? actor.name);
            if (!id) {
                return [];
            }

            const sourceMetadata = nodeMetadataById.value.get(id);

            return [
                {
                    id,
                    name: resolveGraphId(actor.name ?? actor.id) ?? id,
                    type: 'actor' as const,
                    receives: uniqueNames([
                        ...normalizeNames(actor.receives),
                        ...[...edgeLinks.value.eventToActors.entries()]
                            .filter(([, targets]) => targets.includes(id))
                            .map(([eventId]) => eventId),
                    ]),
                    sends: uniqueNames([
                        ...normalizeNames(actor.sends),
                        ...(edgeLinks.value.actorToEvents.get(id) ?? []),
                    ]),
                    sourceLine:
                        resolveSourceLine(actor.source_line) ??
                        sourceMetadata?.sourceLine ??
                        null,
                    sourceKind:
                        resolveSourceKind(actor.source_kind) ??
                        sourceMetadata?.sourceKind ??
                        null,
                    sourceModule:
                        resolveSourceModule(actor.source_module) ??
                        sourceMetadata?.sourceModule ??
                        null,
                },
            ];
        })
        .sort((left, right) => left.name.localeCompare(right.name));
});

const events = computed<DiscoveryEvent[]>(() => {
    const rawEvents = Array.isArray(props.graph?.events)
        ? props.graph.events
        : [];

    if (rawEvents.length === 0) {
        const rawNodes = Array.isArray(props.graph?.nodes)
            ? props.graph.nodes
            : [];

        return rawNodes
            .flatMap((rawNode) => {
                if (!rawNode || typeof rawNode !== 'object') {
                    return [];
                }

                const node = rawNode as Record<string, unknown>;
                if (node.type !== 'event') {
                    return [];
                }

                const id = resolveGraphId(node.id ?? node.name);
                if (!id) {
                    return [];
                }

                return [
                    {
                        id,
                        name:
                            resolveGraphId(
                                node.name ?? node.label ?? node.id,
                            ) ?? id,
                        type: 'event' as const,
                        consumedBy: uniqueNames(
                            edgeLinks.value.eventToActors.get(id) ?? [],
                        ),
                        producedBy: uniqueNames(
                            [...edgeLinks.value.actorToEvents.entries()]
                                .filter(([, targets]) => targets.includes(id))
                                .map(([actorId]) => actorId),
                        ),
                        sourceLine: resolveSourceLine(node.source_line),
                        sourceKind: resolveSourceKind(node.source_kind),
                        sourceModule: resolveSourceModule(node.source_module),
                        cronDescription: describeCronExpression(id),
                    },
                ];
            })
            .sort((left, right) => left.name.localeCompare(right.name));
    }

    return rawEvents
        .flatMap((rawEvent) => {
            const event =
                typeof rawEvent === 'object' && rawEvent !== null
                    ? (rawEvent as Record<string, unknown>)
                    : { id: rawEvent };
            const id = resolveGraphId(event.id ?? event.name);
            if (!id) {
                return [];
            }

            const sourceMetadata = nodeMetadataById.value.get(id);

            return [
                {
                    id,
                    name: resolveGraphId(event.name ?? event.id) ?? id,
                    type: 'event' as const,
                    consumedBy: uniqueNames(
                        edgeLinks.value.eventToActors.get(id) ?? [],
                    ),
                    producedBy: uniqueNames(
                        [...edgeLinks.value.actorToEvents.entries()]
                            .filter(([, targets]) => targets.includes(id))
                            .map(([actorId]) => actorId),
                    ),
                    sourceLine:
                        resolveSourceLine(event.source_line) ??
                        sourceMetadata?.sourceLine ??
                        null,
                    sourceKind:
                        resolveSourceKind(event.source_kind) ??
                        sourceMetadata?.sourceKind ??
                        null,
                    sourceModule:
                        resolveSourceModule(event.source_module) ??
                        sourceMetadata?.sourceModule ??
                        null,
                    cronDescription: describeCronExpression(id),
                },
            ];
        })
        .sort((left, right) => left.name.localeCompare(right.name));
});

const webhooks = computed<DiscoveryWebhookEndpoint[]>(() => {
    return props.webhookEndpoints
        .map((endpoint) => ({
            slug: endpoint.slug,
            sourceLine: resolveSourceLine(endpoint.source_line),
            productionUrl:
                typeof endpoint.production_url === 'string' &&
                endpoint.production_url.trim().length > 0
                    ? endpoint.production_url.trim()
                    : null,
            developmentUrl:
                typeof endpoint.development_url === 'string' &&
                endpoint.development_url.trim().length > 0
                    ? endpoint.development_url.trim()
                    : null,
        }))
        .sort((left, right) => left.slug.localeCompare(right.slug));
});

const webhookBySlug = computed(() => {
    return new Map(webhooks.value.map((webhook) => [webhook.slug, webhook]));
});

const resolveWebhookEndpoint = (
    name: string,
): DiscoveryWebhookEndpoint | null => {
    const slug = extractWebhookSlug(name);

    if (!slug) {
        return null;
    }

    return webhookBySlug.value.get(slug) ?? null;
};

const displayEvents = computed<DiscoveryEventView[]>(() => {
    const discoveredEvents = events.value.map((event) => {
        const webhook = resolveWebhookEndpoint(event.name);

        return {
            ...event,
            webhook,
            implementationLine: webhook?.sourceLine ?? event.sourceLine,
        };
    });
    const representedWebhooks = new Set(
        discoveredEvents
            .map((event) => event.webhook?.slug ?? null)
            .filter((slug): slug is string => slug !== null),
    );

    for (const webhook of webhooks.value) {
        if (representedWebhooks.has(webhook.slug)) {
            continue;
        }

        discoveredEvents.push({
            id: `Webhook.by(${webhook.slug})`,
            name: `Webhook.by(${webhook.slug})`,
            type: 'event',
            consumedBy: [],
            producedBy: [],
            cronDescription: null,
            sourceLine: webhook.sourceLine,
            sourceKind: 'main',
            sourceModule: null,
            webhook,
            implementationLine: webhook.sourceLine,
        });
    }

    return discoveredEvents.sort((left, right) =>
        left.name.localeCompare(right.name),
    );
});

const hasDiscoveryItems = computed(() => {
    return actors.value.length > 0 || displayEvents.value.length > 0;
});

const clearHighlightTimer = (): void => {
    if (highlightTimer === null) {
        return;
    }

    clearTimeout(highlightTimer);
    highlightTimer = null;
};

const focusItem = async (
    type: DiscoveryItemType,
    id: string,
): Promise<void> => {
    const key = buildItemKey(type, id);
    highlightedKey.value = key;
    clearHighlightTimer();

    await nextTick();

    const container = containerRef.value;
    const element = itemRefs.get(key);
    if (container && element) {
        const top =
            element.getBoundingClientRect().top -
            container.getBoundingClientRect().top +
            container.scrollTop -
            container.clientHeight / 2 +
            element.clientHeight / 2;

        container.scrollTo({
            top: Math.max(top, 0),
            behavior: 'smooth',
        });
    }

    highlightTimer = setTimeout(() => {
        highlightedKey.value = null;
        highlightTimer = null;
    }, 1800);
};

const handleItemClick = (type: DiscoveryItemType, id: string): void => {
    void focusItem(type, id);
};

const openLinkedItem = (type: DiscoveryItemType, id: string): void => {
    void focusItem(type, id);
};

const openImplementation = (line: number | null): void => {
    if (line === null) {
        return;
    }

    emit('jump-to-code', line);
};

const implementationLabel = (line: number | null): string => {
    if (line === null) {
        return t('common.empty');
    }

    return t('flows.editor.discovery.implementation_line', {
        line,
    });
};

watch(
    () => props.selectedTarget?.requestKey,
    (requestKey) => {
        if (!requestKey || !props.selectedTarget) {
            return;
        }

        void focusItem(props.selectedTarget.type, props.selectedTarget.id);
    },
    { flush: 'post', immediate: true },
);

onBeforeUnmount(() => {
    clearHighlightTimer();
});
</script>

<template>
    <section
        v-if="hasDiscoveryItems"
        ref="containerRef"
        class="h-full divide-y overflow-y-auto rounded-xl border border-border"
        :class="props.outdated ? 'opacity-70 grayscale saturate-0' : ''"
    >
        <template v-if="actors.length">
            <h3
                class="px-4 pt-3 pb-2 text-xs font-semibold tracking-wide text-muted-foreground"
            >
                {{ t('flows.editor.discovery.actors_title') }}
                ({{ actors.length }})
            </h3>

            <div
                v-for="actor in actors"
                :key="`actor-${actor.id}`"
                :ref="(element) => setItemRef('actor', actor.id, element)"
                class="grid gap-1 px-4 py-2 transition-colors"
                :class="
                    highlightedKey === `actor:${actor.id}`
                        ? 'bg-amber-400/10'
                        : ''
                "
                @click="handleItemClick('actor', actor.id)"
            >
                <button
                    type="button"
                    class="mb-1 w-fit max-w-full truncate font-mono text-sm font-semibold text-foreground transition hover:text-foreground/80 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none"
                    @click.stop="handleItemClick('actor', actor.id)"
                >
                    {{ actor.name }}
                </button>

                <div
                    v-if="actor.receives.length > 0"
                    class="flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-[11px] text-muted-foreground">
                        {{ t('flows.editor.discovery.receives') }}
                    </span>
                    <button
                        v-for="eventName in actor.receives"
                        :key="`${actor.id}-receives-${eventName}`"
                        type="button"
                        class="rounded-md border border-sky-500/30 bg-sky-500/10 px-1.5 py-0.5 font-mono text-[11px] text-sky-700 transition hover:bg-sky-500/15 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none dark:text-sky-300"
                        @click.stop="openLinkedItem('event', eventName)"
                    >
                        {{ eventName }}
                    </button>
                </div>

                <div
                    v-if="actor.sends.length > 0"
                    class="flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-[11px] text-muted-foreground">
                        {{ t('flows.editor.discovery.sends') }}
                    </span>
                    <button
                        v-for="eventName in actor.sends"
                        :key="`${actor.id}-sends-${eventName}`"
                        type="button"
                        class="rounded-md border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 font-mono text-[11px] text-amber-700 transition hover:bg-amber-500/15 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none dark:text-amber-300"
                        @click.stop="openLinkedItem('event', eventName)"
                    >
                        {{ eventName }}
                    </button>
                </div>

                <button
                    v-if="actor.sourceLine !== null"
                    type="button"
                    class="mt-0.5 flex gap-1 text-[11px] text-muted-foreground hover:bg-transparent hover:text-foreground"
                    @click.stop="openImplementation(actor.sourceLine)"
                >
                    {{ implementationLabel(actor.sourceLine) }}
                    <ArrowUpRight class="mt-0.5 size-3" aria-hidden="true" />
                </button>
            </div>
        </template>

        <template v-if="displayEvents.length">
            <h3
                class="px-4 pt-4 pb-2 text-xs font-semibold tracking-wide text-muted-foreground"
            >
                {{ t('flows.editor.discovery.events_title') }}
                ({{ displayEvents.length }})
            </h3>

            <div
                v-for="event in displayEvents"
                :key="`event-${event.id}`"
                :ref="(element) => setItemRef('event', event.id, element)"
                class="grid gap-1 px-4 py-2 transition-colors"
                :class="
                    highlightedKey === `event:${event.id}`
                        ? 'bg-amber-400/10'
                        : ''
                "
                @click="handleItemClick('event', event.id)"
            >
                <TooltipProvider v-if="event.cronDescription" :delay-duration="0">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="mb-1 w-fit max-w-full truncate text-left font-mono text-sm font-semibold text-foreground transition hover:text-foreground/80 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none"
                                @click.stop="handleItemClick('event', event.id)"
                            >
                                <span>{{
                                    splitCronEventName(event.name)?.prefix
                                }}</span>
                                <span
                                    class="border-b border-current px-0.5 transition-colors hover:bg-amber-300/35 data-[state=open]:bg-amber-300/35"
                                >
                                    {{
                                        splitCronEventName(event.name)
                                            ?.expression
                                    }}
                                </span>
                                <span>{{
                                    splitCronEventName(event.name)?.suffix
                                }}</span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" class="max-w-xs text-xs">
                            {{ event.cronDescription }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <button
                    v-else
                    type="button"
                    class="mb-1 flex min-w-0 w-fit max-w-full flex-wrap items-center gap-2 text-left font-mono text-sm font-semibold text-foreground transition hover:text-foreground/80 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none"
                    @click.stop="handleItemClick('event', event.id)"
                >
                    <span class="min-w-0 truncate">{{ event.name }}</span>
                </button>

                <div
                    v-if="event.consumedBy.length > 0"
                    class="flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-[11px] text-muted-foreground">
                        {{ t('flows.editor.discovery.consumed_by') }}
                    </span>
                    <button
                        v-for="actorName in event.consumedBy"
                        :key="`${event.id}-consumed-${actorName}`"
                        type="button"
                        class="rounded-md border border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0.5 font-mono text-[11px] text-emerald-700 transition hover:bg-emerald-500/15 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none dark:text-emerald-300"
                        @click.stop="openLinkedItem('actor', actorName)"
                    >
                        {{ actorName }}
                    </button>
                </div>

                <div
                    v-if="event.producedBy.length > 0"
                    class="flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-[11px] text-muted-foreground">
                        {{ t('flows.editor.discovery.produced_by') }}
                    </span>
                    <button
                        v-for="actorName in event.producedBy"
                        :key="`${event.id}-produced-${actorName}`"
                        type="button"
                        class="rounded-md border border-orange-500/30 bg-orange-500/10 px-1.5 py-0.5 font-mono text-[11px] text-orange-700 transition hover:bg-orange-500/15 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:outline-none dark:text-orange-300"
                        @click.stop="openLinkedItem('actor', actorName)"
                    >
                        {{ actorName }}
                    </button>
                </div>

                <div
                    v-if="event.webhook"
                    class="mt-1 grid gap-2 rounded-lg border border-border/70 bg-muted/40 p-3"
                >
                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                        <span
                            class="rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 font-medium tracking-[0.04em] text-emerald-700 uppercase dark:text-emerald-300"
                        >
                            Webhook
                        </span>
                        <span class="text-muted-foreground">
                            {{ event.webhook.slug }}
                        </span>
                    </div>

                    <FlowWebhookQuickSender
                        v-if="event.webhook.productionUrl"
                        :label="t('flows.editor.discovery.production_webhook')"
                        :endpoint="event.webhook.productionUrl"
                    />

                    <FlowWebhookQuickSender
                        v-if="event.webhook.developmentUrl"
                        :label="t('flows.editor.discovery.development_webhook')"
                        :endpoint="event.webhook.developmentUrl"
                    />
                </div>

                <button
                    v-if="event.implementationLine !== null"
                    type="button"
                    class="mt-0.5 flex gap-1 text-[11px] text-muted-foreground hover:bg-transparent hover:text-foreground"
                    @click.stop="openImplementation(event.implementationLine)"
                >
                    {{ implementationLabel(event.implementationLine) }}
                    <ArrowUpRight class="mt-0.5 size-3" aria-hidden="true" />
                </button>
            </div>
        </template>
    </section>
    <div
        v-else
        class="flex h-full min-h-[320px] flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center"
    >
        <ScanSearch class="mb-4 size-10 text-muted-foreground/70" />
        <p class="text-sm font-semibold text-foreground">
            {{ t('flows.editor.discovery.empty_title') }}
        </p>
        <p class="mt-2 max-w-md text-sm text-muted-foreground">
            {{ t('flows.editor.discovery.empty_description') }}
        </p>
    </div>
</template>
