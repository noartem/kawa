export interface DispatchPathHighlight {
    actor: string;
    event: string;
    triggerEvent: string | null;
}

export interface DispatchHighlightTarget {
    highlightDispatchPath: (payload: DispatchPathHighlight) => void;
}

interface NormalizedEdge {
    id: string;
    from: string;
}

interface EdgeHighlightAttributesOptions {
    edgeId: string;
    baseColor: string;
    baseSize: number;
    hoveredNodeId: string | null;
    hoverHighlightedEdgeIds: Set<string>;
    programmaticHighlightStrength: number;
}

export const PROGRAMMATIC_HIGHLIGHT_COLOR = '#22c55e';

const dimmedEdgeColor = 'rgba(148, 163, 184, 0.12)';

const clamp = (value: number, min: number, max: number): number => {
    return Math.min(max, Math.max(min, value));
};

const resolveGraphId = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim().length > 0) {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

const resolveGraphNodeIds = (
    graph: Record<string, unknown> | null | undefined,
): Set<string> => {
    const rawNodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    const nodeIds = new Set<string>();

    for (const rawNode of rawNodes) {
        if (!rawNode || typeof rawNode !== 'object') {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const id = resolveGraphId(node.id ?? node.name);

        if (id) {
            nodeIds.add(id);
        }
    }

    return nodeIds;
};

const resolveNormalizedEdges = (
    graph: Record<string, unknown> | null | undefined,
): NormalizedEdge[] => {
    const rawEdges = Array.isArray(graph?.edges) ? graph.edges : [];
    const nodeIds = resolveGraphNodeIds(graph);
    const seenEdgeIds = new Set<string>();
    const edges: NormalizedEdge[] = [];

    for (const rawEdge of rawEdges) {
        if (!rawEdge || typeof rawEdge !== 'object') {
            continue;
        }

        const edge = rawEdge as Record<string, unknown>;
        const from = resolveGraphId(edge.from);
        const to = resolveGraphId(edge.to);

        if (!from || !to || !nodeIds.has(from) || !nodeIds.has(to)) {
            continue;
        }

        const edgeId = `${from}->${to}`;
        if (seenEdgeIds.has(edgeId)) {
            continue;
        }

        seenEdgeIds.add(edgeId);
        edges.push({
            id: edgeId,
            from,
        });
    }

    return edges;
};

const parseHexColor = (
    value: string,
): { r: number; g: number; b: number } | null => {
    const normalized = value.trim();
    const match = normalized.match(/^#([0-9a-f]{6})$/i);

    if (!match) {
        return null;
    }

    return {
        r: Number.parseInt(match[1].slice(0, 2), 16),
        g: Number.parseInt(match[1].slice(2, 4), 16),
        b: Number.parseInt(match[1].slice(4, 6), 16),
    };
};

const mixEdgeColor = (
    baseColor: string,
    overlayColor: string,
    strength: number,
): string => {
    const normalizedStrength = clamp(strength, 0, 1);
    const base = parseHexColor(baseColor);
    const overlay = parseHexColor(overlayColor);

    if (!base || !overlay) {
        return normalizedStrength >= 0.5 ? overlayColor : baseColor;
    }

    const mixChannel = (from: number, to: number): number => {
        return Math.round(from + (to - from) * normalizedStrength);
    };

    return `rgb(${mixChannel(base.r, overlay.r)}, ${mixChannel(base.g, overlay.g)}, ${mixChannel(base.b, overlay.b)})`;
};

export const resolveDispatchHighlightEdgeIds = (
    graph: Record<string, unknown> | null | undefined,
    highlight: DispatchPathHighlight,
): Set<string> => {
    const edges = resolveNormalizedEdges(graph);
    const edgeIds = new Set<string>();
    const actorId = resolveGraphId(highlight.actor);
    const eventId = resolveGraphId(highlight.event);
    const triggerEventId = resolveGraphId(highlight.triggerEvent);

    if (!actorId || !eventId) {
        return edgeIds;
    }

    const addEdgeId = (edgeId: string): void => {
        if (edges.some((edge) => edge.id === edgeId)) {
            edgeIds.add(edgeId);
        }
    };

    if (triggerEventId) {
        addEdgeId(`${triggerEventId}->${actorId}`);
    }

    addEdgeId(`${actorId}->${eventId}`);

    for (const edge of edges) {
        if (edge.from === eventId) {
            edgeIds.add(edge.id);
        }
    }

    return edgeIds;
};

export const propagateDispatchPathHighlight = (
    targets: Array<DispatchHighlightTarget | null | undefined>,
    payload: DispatchPathHighlight,
): void => {
    for (const target of targets) {
        target?.highlightDispatchPath(payload);
    }
};

export const flushPendingDispatchPathHighlight = (
    target: DispatchHighlightTarget | null | undefined,
    pendingHighlight: DispatchPathHighlight | null,
): DispatchPathHighlight | null => {
    if (!target || !pendingHighlight) {
        return pendingHighlight;
    }

    target.highlightDispatchPath(pendingHighlight);

    return null;
};

export const resolveEdgeHighlightAttributes = ({
    edgeId,
    baseColor,
    baseSize,
    hoveredNodeId,
    hoverHighlightedEdgeIds,
    programmaticHighlightStrength,
}: EdgeHighlightAttributesOptions): Record<string, number | string> => {
    const hoverHighlighted = hoverHighlightedEdgeIds.has(edgeId);

    if (
        hoveredNodeId &&
        !hoverHighlighted &&
        programmaticHighlightStrength <= 0
    ) {
        return {
            color: dimmedEdgeColor,
            zIndex: 0,
        };
    }

    const nextAttributes: Record<string, number | string> = {};

    if (hoverHighlighted) {
        nextAttributes.size = Math.max(baseSize, 3.2);
        nextAttributes.zIndex = 1;
    }

    if (programmaticHighlightStrength > 0) {
        nextAttributes.color = mixEdgeColor(
            baseColor,
            PROGRAMMATIC_HIGHLIGHT_COLOR,
            programmaticHighlightStrength,
        );
        nextAttributes.size = Math.max(
            typeof nextAttributes.size === 'number'
                ? nextAttributes.size
                : baseSize,
            baseSize + 3.2 * programmaticHighlightStrength,
        );
        nextAttributes.zIndex = Math.max(
            typeof nextAttributes.zIndex === 'number'
                ? nextAttributes.zIndex
                : 0,
            3,
        );
    }

    return nextAttributes;
};
