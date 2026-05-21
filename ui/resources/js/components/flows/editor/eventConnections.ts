const EVENT_VARIANT_MARKER = '.';

const resolveGraphId = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim().length > 0) {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

const isEventNode = (value: unknown): boolean => {
    if (!value || typeof value !== 'object') {
        return false;
    }

    return (value as Record<string, unknown>).type === 'event';
};

const resolveEventFamilyKey = (eventId: string): string => {
    const markerIndex = eventId.indexOf(EVENT_VARIANT_MARKER);
    if (markerIndex <= 0) {
        return eventId;
    }

    const familyKey = eventId.slice(0, markerIndex).trim();

    return familyKey.length > 0 ? familyKey : eventId;
};

const collectEventIds = (graph: Record<string, unknown> | null): string[] => {
    if (!graph) {
        return [];
    }

    const rawNodes = Array.isArray(graph.nodes) ? graph.nodes : [];
    const rawEvents = Array.isArray(graph.events) ? graph.events : [];
    const eventIds = new Set<string>();

    for (const rawNode of rawNodes) {
        if (!isEventNode(rawNode)) {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const eventId = resolveGraphId(node.id ?? node.name ?? node.label);
        if (eventId) {
            eventIds.add(eventId);
        }
    }

    for (const rawEvent of rawEvents) {
        if (!rawEvent || typeof rawEvent !== 'object') {
            continue;
        }

        const event = rawEvent as Record<string, unknown>;
        const eventId = resolveGraphId(event.id ?? event.name);
        if (eventId) {
            eventIds.add(eventId);
        }
    }

    return [...eventIds];
};

const resolveCanonicalEventId = (
    eventIds: string[],
    familyKey: string,
): string => {
    const exactMatch = eventIds.find((eventId) => eventId === familyKey);
    if (exactMatch) {
        return exactMatch;
    }

    return eventIds[0];
};

const collectEventFamilies = (
    graph: Record<string, unknown> | null,
): Array<{ canonicalId: string; eventIds: string[] }> => {
    const eventIds = collectEventIds(graph);
    const families = new Map<string, string[]>();

    for (const eventId of eventIds) {
        const familyKey = resolveEventFamilyKey(eventId);
        const familyEventIds = families.get(familyKey) ?? [];

        familyEventIds.push(eventId);
        families.set(familyKey, familyEventIds);
    }

    return [...families.entries()]
        .map(([familyKey, familyEventIds]) => {
            const uniqueEventIds = [...new Set(familyEventIds)];

            return {
                canonicalId: resolveCanonicalEventId(uniqueEventIds, familyKey),
                eventIds: [...uniqueEventIds].sort((left, right) => {
                    return left.localeCompare(right);
                }),
            };
        })
        .filter((family) => family.eventIds.length > 1);
};

export const collectRelatedEventIdsById = (
    graph: Record<string, unknown> | null,
): Map<string, string[]> => {
    const relatedEventIdsById = new Map<string, string[]>();

    for (const family of collectEventFamilies(graph)) {
        for (const eventId of family.eventIds) {
            relatedEventIdsById.set(
                eventId,
                family.eventIds.filter((candidateId) => candidateId !== eventId),
            );
        }
    }

    return relatedEventIdsById;
};

export const connectRelatedEventsInGraph = (
    graph: Record<string, unknown> | null,
): Record<string, unknown> | null => {
    if (!graph) {
        return null;
    }

    const rawEdges = Array.isArray(graph.edges) ? graph.edges : [];
    const existingEdgeKeys = new Set<string>();

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

        existingEdgeKeys.add(`${from}->${to}`);
    }

    const syntheticEdges: Array<Record<string, unknown>> = [];

    for (const family of collectEventFamilies(graph)) {
        for (const eventId of family.eventIds) {
            if (eventId === family.canonicalId) {
                continue;
            }

            const edgeKey = `${family.canonicalId}->${eventId}`;
            if (existingEdgeKeys.has(edgeKey)) {
                continue;
            }

            syntheticEdges.push({
                from: family.canonicalId,
                to: eventId,
            });
            existingEdgeKeys.add(edgeKey);
        }
    }

    if (syntheticEdges.length === 0) {
        return graph;
    }

    return {
        ...graph,
        edges: [...rawEdges, ...syntheticEdges],
    };
};
