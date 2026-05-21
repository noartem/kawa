import {
    FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA,
    FLOW_GRAPH_EDGE_HOVER_SIZE,
} from './graphStyle.ts';
import { resolveMatchingEventNodeId } from './eventNodeIds.ts';

export interface DispatchPathHighlight {
    actor: string;
    event: string;
    triggerEvent: string | null;
}

export interface FlowGraphEdgeHighlight {
    from: string;
    to: string;
}

export interface DispatchHighlightTarget {
    highlightDispatchPath: (payload: DispatchPathHighlight) => void;
}

export interface EdgeHoverHighlightTarget {
    setHoveredEdgeHighlight: (payload: FlowGraphEdgeHighlight | null) => void;
}

export interface EdgeFocusTarget {
    focusEdge: (payload: FlowGraphEdgeHighlight) => void;
}

interface NormalizedEdge {
    id: string;
    from: string;
}

interface EdgeHighlightAttributesOptions {
    edgeId: string;
    baseColor: string;
    baseSize: number;
    hoverActive: boolean;
    hoverHighlightedEdgeIds: Set<string>;
    programmaticHighlightStrength: number;
}

interface NodeHighlightAttributesOptions {
    baseColor: string;
    baseSize: number;
    hovered: boolean;
    programmaticHighlightStrength: number;
}

export const PROGRAMMATIC_HIGHLIGHT_COLOR = '#22c55e';
export const PROGRAMMATIC_HIGHLIGHT_FLASH_MS = 260;
export const PROGRAMMATIC_HIGHLIGHT_FADE_MS = 2100;
export const PROGRAMMATIC_NODE_HIGHLIGHT_SIZE_DELTA = 4;

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

export const getProgrammaticHighlightStrength = (
    highlights: Map<string, number>,
    highlightId: string,
    now: number = performance.now(),
): number => {
    const startedAt = highlights.get(highlightId);

    if (typeof startedAt !== 'number') {
        return 0;
    }

    const elapsed = now - startedAt;
    if (elapsed <= PROGRAMMATIC_HIGHLIGHT_FLASH_MS) {
        return 1;
    }

    return clamp(
        1 -
            (elapsed - PROGRAMMATIC_HIGHLIGHT_FLASH_MS) /
                PROGRAMMATIC_HIGHLIGHT_FADE_MS,
        0,
        1,
    );
};

export const pruneProgrammaticHighlights = (
    highlights: Map<string, number>,
    now: number = performance.now(),
): boolean => {
    let hasActiveHighlights = false;

    for (const [highlightId, startedAt] of highlights) {
        if (
            now - startedAt >=
            PROGRAMMATIC_HIGHLIGHT_FLASH_MS + PROGRAMMATIC_HIGHLIGHT_FADE_MS
        ) {
            highlights.delete(highlightId);
            continue;
        }

        hasActiveHighlights = true;
    }

    return hasActiveHighlights;
};

export const resolveHighlightedEdgeSize = ({
    baseSize,
    hoverHighlighted,
    programmaticHighlightStrength,
}: {
    baseSize: number;
    hoverHighlighted: boolean;
    programmaticHighlightStrength: number;
}): number => {
    let nextSize = baseSize;

    if (hoverHighlighted) {
        nextSize = Math.max(nextSize, FLOW_GRAPH_EDGE_HOVER_SIZE);
    }

    if (programmaticHighlightStrength > 0) {
        nextSize = Math.max(
            nextSize,
            baseSize +
                FLOW_GRAPH_EDGE_HIGHLIGHT_SIZE_DELTA *
                    programmaticHighlightStrength,
        );
    }

    return nextSize;
};

export const resolveNodeHighlightAttributes = ({
    baseColor,
    baseSize,
    hovered,
    programmaticHighlightStrength,
}: NodeHighlightAttributesOptions): Record<
    string,
    number | string | boolean
> => {
    const nextAttributes: Record<string, number | string | boolean> = {};
    const hasProgrammaticHighlight = programmaticHighlightStrength > 0;

    if (hovered || hasProgrammaticHighlight) {
        nextAttributes.size =
            baseSize +
            (hovered ? 1.25 : 0) +
            PROGRAMMATIC_NODE_HIGHLIGHT_SIZE_DELTA *
                programmaticHighlightStrength;
        nextAttributes.zIndex = hasProgrammaticHighlight ? 4 : 2;
    }

    if (hasProgrammaticHighlight) {
        nextAttributes.color = mixEdgeColor(
            baseColor,
            PROGRAMMATIC_HIGHLIGHT_COLOR,
            Math.max(0.35, programmaticHighlightStrength),
        );
        nextAttributes.forceLabel = true;
    }

    return nextAttributes;
};

export const resolveDispatchHighlightEdgeIds = (
    graph: Record<string, unknown> | null | undefined,
    highlight: DispatchPathHighlight,
): Set<string> => {
    const edges = resolveNormalizedEdges(graph);
    const nodeIds = resolveGraphNodeIds(graph);
    const edgeIds = new Set<string>();
    const resolveMatchingEventId = (value: unknown): string | null => {
        const nodeId = resolveGraphId(value);

        if (!nodeId) {
            return null;
        }

        return (
            resolveMatchingEventNodeId(nodeId, nodeIds.values()) ?? nodeId
        );
    };
    const actorId = resolveGraphId(highlight.actor);
    const eventId = resolveMatchingEventId(highlight.event);
    const triggerEventId = resolveMatchingEventId(highlight.triggerEvent);

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

export const resolveDirectHighlightEdgeIds = (
    graph: Record<string, unknown> | null | undefined,
    highlight: FlowGraphEdgeHighlight,
): Set<string> => {
    const edgeIds = new Set<string>();
    const nodeIds = resolveGraphNodeIds(graph);
    const resolveMatchingEventId = (value: unknown): string | null => {
        const nodeId = resolveGraphId(value);

        if (!nodeId) {
            return null;
        }

        return (
            resolveMatchingEventNodeId(nodeId, nodeIds.values()) ?? nodeId
        );
    };
    const fromId = resolveMatchingEventId(highlight.from) ?? resolveGraphId(highlight.from);
    const toId = resolveMatchingEventId(highlight.to) ?? resolveGraphId(highlight.to);

    if (!fromId || !toId) {
        return edgeIds;
    }

    const edgeId = `${fromId}->${toId}`;

    if (resolveNormalizedEdges(graph).some((edge) => edge.id === edgeId)) {
        edgeIds.add(edgeId);
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
    hoverActive,
    hoverHighlightedEdgeIds,
    programmaticHighlightStrength,
}: EdgeHighlightAttributesOptions): Record<string, number | string> => {
    const hoverHighlighted = hoverHighlightedEdgeIds.has(edgeId);

    if (hoverActive && !hoverHighlighted && programmaticHighlightStrength <= 0) {
        return {
            color: dimmedEdgeColor,
            zIndex: 0,
        };
    }

    const nextAttributes: Record<string, number | string> = {};

    if (hoverHighlighted) {
        nextAttributes.size = resolveHighlightedEdgeSize({
            baseSize,
            hoverHighlighted,
            programmaticHighlightStrength,
        });
        nextAttributes.zIndex = 1;
    }

    if (programmaticHighlightStrength > 0) {
        nextAttributes.color = mixEdgeColor(
            baseColor,
            PROGRAMMATIC_HIGHLIGHT_COLOR,
            programmaticHighlightStrength,
        );
        nextAttributes.size = resolveHighlightedEdgeSize({
            baseSize,
            hoverHighlighted,
            programmaticHighlightStrength,
        });
        nextAttributes.zIndex = Math.max(
            typeof nextAttributes.zIndex === 'number'
                ? nextAttributes.zIndex
                : 0,
            3,
        );
    }

    return nextAttributes;
};
