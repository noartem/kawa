<script setup lang="ts">
import Graph from 'graphology';
import forceAtlas2 from 'graphology-layout-forceatlas2';
import Sigma from 'sigma';
import { animateNodes } from 'sigma/utils';
import {
    createDefaultSvgViewport,
    expandSvgBounds,
    estimateSvgLabelFrame,
    interpolateSvgViewport,
    panSvgViewport,
    resolveFallbackEdgeColor,
    resolveFocusedSvgViewportOnPoint,
    resolveSvgWheelPixelDelta,
    resolveSvgWheelZoomMode,
    resolveSvgWheelZoomScale,
    scaleSvgViewport,
    resolveSvgLine,
    resolveSvgViewportFromBounds,
    resolveSvgViewportZoomPercent,
    SVG_NODE_RADIUS,
    toSvgPoint,
    zoomSvgViewport,
    type SvgViewport,
} from './graphFallback';
import {
    getProgrammaticHighlightStrength,
    PROGRAMMATIC_HIGHLIGHT_COLOR,
    pruneProgrammaticHighlights,
    resolveDirectHighlightEdgeIds,
    resolveDispatchHighlightEdgeIds,
    resolveEdgeHighlightAttributes,
    resolveHighlightedEdgeSize,
    resolveNodeHighlightAttributes,
    type DispatchPathHighlight,
    type FlowGraphEdgeHighlight,
} from './graphHighlights';
import {
    FLOW_GRAPH_EDGE_BASE_SIZE,
    FLOW_GRAPH_FALLBACK_ARROW_MARKER,
} from './graphStyle';
import {
    hasRenderableContainerSize,
    shouldMountRendererOnResize,
} from './graphRendererState';
import {
    logFlowGraphVisibility,
    summarizeGraphForDebug,
} from './graphVisibilityDebug';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';

type GraphNodeType = 'event' | 'actor' | 'other';
type GraphSourceKind = 'main' | 'import';

interface BaseNode {
    id: string;
    label: string;
    shortLabel: string;
    type: GraphNodeType;
    order: number;
    sourceLine: number | null;
    sourceKind: GraphSourceKind | null;
    sourceModule: string | null;
}

interface NormalizedNode extends BaseNode {
    x: number;
    y: number;
}

interface NormalizedEdge {
    id: string;
    from: string;
    to: string;
    tone: GraphNodeType;
}

interface BuiltGraphState {
    sigmaGraph: Graph;
    nodes: NormalizedNode[];
    edges: NormalizedEdge[];
    edgeIdsByNode: Map<string, Set<string>>;
    nodeById: Map<string, BaseNode>;
    positionedNodeById: Map<string, NormalizedNode>;
}

interface GraphNodePosition {
    x: number;
    y: number;
}

const summarizeBuiltGraphStateForDebug = (
    graphState: BuiltGraphState | null,
): Record<string, unknown> => {
    if (!graphState) {
        return {
            nodeCount: 0,
            edgeCount: 0,
            nodeIds: [],
            edgeIds: [],
        };
    }

    return {
        nodeCount: graphState.nodes.length,
        edgeCount: graphState.edges.length,
        nodeIds: graphState.nodes.map((node) => node.id),
        edgeIds: graphState.edges.map((edge) => edge.id),
    };
};

const props = withDefaults(
    defineProps<{
        graph?: Record<string, unknown> | null;
    }>(),
    {
        graph: null,
    },
);

const emit = defineEmits<{
    (event: 'zoom-change', value: number): void;
    (event: 'node-click', value: BaseNode): void;
}>();

const svgIdPrefix = useId().replace(/:/g, '-');
const arrowMarkerId = `${svgIdPrefix}-flow-graph-arrow`;
const softShadowFilterId = `${svgIdPrefix}-flow-graph-soft-shadow`;

const rootRef = ref<HTMLDivElement | null>(null);
const containerRef = ref<HTMLDivElement | null>(null);
const fallbackGraph = ref<BuiltGraphState | null>(null);
const fallbackRenderVersion = ref(0);
const fallbackBaseViewport = ref<SvgViewport>(createDefaultSvgViewport());
const fallbackViewport = ref<SvgViewport>({ ...fallbackBaseViewport.value });
const fallbackDragState = ref<{
    pointerId: number;
    startClientX: number;
    startClientY: number;
    startViewport: SvgViewport;
} | null>(null);

let sigmaRenderer: Sigma | null = null;
let releaseCameraListener: (() => void) | null = null;
let releaseInteractionListeners: (() => void) | null = null;
let intersectionObserver: IntersectionObserver | null = null;
let resizeObserver: ResizeObserver | null = null;
let mountRetryFrame: number | null = null;
let mountRetryCount = 0;
const hoveredNodeId = ref<string | null>(null);
const hoverHighlightedEdgeIds = ref(new Set<string>());
const externallyHoveredEdgeIds = ref(new Set<string>());
const programmaticEdgeHighlights = ref(new Map<string, number>());
const programmaticNodeHighlights = ref(new Map<string, number>());
let programmaticHighlightFrame: number | null = null;
let pendingDispatchHighlight: DispatchPathHighlight | null = null;
let currentHoveredEdgeHighlight: FlowGraphEdgeHighlight | null = null;
let fallbackViewportAnimationFrame: number | null = null;
const hasBeenVisible = ref(false);
const renderedGraphSignature = ref<string | null>(null);
const activeGraphState = ref<BuiltGraphState | null>(null);

const hasRenderableGraph = computed(() => {
    return buildNodes(props.graph).length > 0;
});

const isUsingFallbackRenderer = computed(() => {
    return fallbackGraph.value !== null;
});

const isFallbackDragging = computed(() => {
    return fallbackDragState.value !== null;
});

const activeHoverHighlightedEdgeIds = computed(() => {
    return new Set<string>([
        ...hoverHighlightedEdgeIds.value,
        ...externallyHoveredEdgeIds.value,
    ]);
});

const hasActiveEdgeHover = computed(() => {
    return (
        hoveredNodeId.value !== null || externallyHoveredEdgeIds.value.size > 0
    );
});

const fallbackViewBox = computed(() => {
    const viewport = fallbackViewport.value;

    return `${viewport.x} ${viewport.y} ${viewport.width} ${viewport.height}`;
});

const fallbackZoomPercent = computed(() => {
    return resolveSvgViewportZoomPercent(
        fallbackViewport.value,
        fallbackBaseViewport.value,
    );
});

interface CameraStateSnapshot {
    x: number;
    y: number;
    ratio: number;
    angle: number;
}

const ZOOM_STEP = 1.2;
const MIN_RATIO = 0.2;
const MAX_RATIO = 8;
const DEFAULT_CAMERA_RATIO = 100 / 83;
const FOCUS_ZOOM_PERCENT = 150;
const FOCUS_TRANSITION_DURATION_MS = 260;
const DEFAULT_CAMERA_STATE: CameraStateSnapshot = {
    x: 0.5,
    y: 0.5,
    ratio: DEFAULT_CAMERA_RATIO,
    angle: 0,
};
const LAYOUT_PADDING = 0.22;
const MAX_MOUNT_RETRIES = 30;
const FALLBACK_SHADOW_BOUNDS_MARGIN = 36;
const SIGMA_LAYOUT_ANIMATION_DURATION_MS = 260;

let cancelNodeAnimation: (() => void) | null = null;
let pendingSigmaGraphCleanup: (() => void) | null = null;
let sigmaAnimationRefreshFrame: number | null = null;

const clearMountRetry = (): void => {
    if (mountRetryFrame === null) {
        return;
    }

    cancelAnimationFrame(mountRetryFrame);
    mountRetryFrame = null;
};

const clearProgrammaticHighlightFrame = (): void => {
    if (programmaticHighlightFrame === null) {
        return;
    }

    cancelAnimationFrame(programmaticHighlightFrame);
    programmaticHighlightFrame = null;
};

const clearFallbackViewportAnimation = (): void => {
    if (fallbackViewportAnimationFrame === null) {
        return;
    }

    cancelAnimationFrame(fallbackViewportAnimationFrame);
    fallbackViewportAnimationFrame = null;
};

const clearSigmaAnimationRefreshFrame = (): void => {
    if (sigmaAnimationRefreshFrame === null) {
        return;
    }

    cancelAnimationFrame(sigmaAnimationRefreshFrame);
    sigmaAnimationRefreshFrame = null;
};

const clearNodeAnimation = (): void => {
    clearSigmaAnimationRefreshFrame();

    if (cancelNodeAnimation) {
        cancelNodeAnimation();
        cancelNodeAnimation = null;
    }
};

const scheduleSigmaAnimationRefresh = (): void => {
    if (
        sigmaAnimationRefreshFrame !== null ||
        !sigmaRenderer ||
        !cancelNodeAnimation
    ) {
        return;
    }

    sigmaAnimationRefreshFrame = requestAnimationFrame(() => {
        sigmaAnimationRefreshFrame = null;

        if (!sigmaRenderer) {
            return;
        }

        sigmaRenderer.scheduleRefresh();

        if (cancelNodeAnimation) {
            scheduleSigmaAnimationRefresh();
        }
    });
};

const flushPendingSigmaGraphCleanup = (): void => {
    if (!pendingSigmaGraphCleanup) {
        return;
    }

    const cleanup = pendingSigmaGraphCleanup;
    pendingSigmaGraphCleanup = null;
    cleanup();
};

const easeInOutCubic = (progress: number): number => {
    if (progress < 0.5) {
        return 4 * progress * progress * progress;
    }

    return 1 - Math.pow(-2 * progress + 2, 3) / 2;
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

const shortenLabel = (label: string): string => {
    return label.length > 24 ? `${label.slice(0, 21)}...` : label;
};

const resolveSourceLine = (value: unknown): number | null => {
    if (typeof value !== 'number' || !Number.isInteger(value)) {
        return null;
    }

    return value > 0 ? value : null;
};

const resolveSourceKind = (value: unknown): GraphSourceKind | null => {
    return value === 'main' || value === 'import' ? value : null;
};

const resolveSourceModule = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const moduleName = value.trim();

    return moduleName.length > 0 ? moduleName : null;
};

const buildNodes = (graph?: Record<string, unknown> | null): BaseNode[] => {
    const rawNodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    const normalizedNodes: BaseNode[] = [];
    const usedIds = new Set<string>();
    let order = 0;

    for (const rawNode of rawNodes) {
        if (!rawNode || typeof rawNode !== 'object') {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const id = resolveGraphId(node.id ?? node.name);
        if (!id || usedIds.has(id)) {
            continue;
        }

        const rawType =
            typeof node.type === 'string' ? node.type.toLowerCase() : null;
        const type: GraphNodeType =
            rawType === 'event' || rawType === 'actor' ? rawType : 'other';
        const label = resolveGraphId(node.label ?? node.id ?? node.name) ?? id;

        normalizedNodes.push({
            id,
            label,
            shortLabel: shortenLabel(label),
            type,
            order,
            sourceLine: resolveSourceLine(node.source_line),
            sourceKind: resolveSourceKind(node.source_kind),
            sourceModule: resolveSourceModule(node.source_module),
        });

        order += 1;
        usedIds.add(id);
    }

    return normalizedNodes;
};

const seedY = (order: number): number => {
    const normalized = ((order % 17) + 1) / 18;
    return LAYOUT_PADDING + normalized * (1 - LAYOUT_PADDING * 2);
};

const seedPosition = (node: BaseNode): { x: number; y: number } => {
    const baseX = node.type === 'event' ? 0.4 : 0.6;
    const xJitter = ((node.order % 11) - 5) * 0.01;

    return {
        x: Math.max(
            LAYOUT_PADDING,
            Math.min(1 - LAYOUT_PADDING, baseX + xJitter),
        ),
        y: seedY(node.order),
    };
};

const isFiniteGraphCoordinate = (value: unknown): value is number => {
    return typeof value === 'number' && Number.isFinite(value);
};

const resolveFiniteGraphPosition = (
    position: Partial<GraphNodePosition> | null | undefined,
    fallback: GraphNodePosition,
): GraphNodePosition => {
    return {
        x: isFiniteGraphCoordinate(position?.x) ? position.x : fallback.x,
        y: isFiniteGraphCoordinate(position?.y) ? position.y : fallback.y,
    };
};

const normalizeAxis = (values: number[]): { min: number; max: number } => {
    const min = Math.min(...values);
    const max = Math.max(...values);

    if (!Number.isFinite(min) || !Number.isFinite(max)) {
        return { min: 0, max: 1 };
    }

    return { min, max };
};

const toPaddedRange = (value: number, min: number, max: number): number => {
    if (max - min < 1e-9) {
        return 0.5;
    }

    const unit = (value - min) / (max - min);
    return LAYOUT_PADDING + unit * (1 - LAYOUT_PADDING * 2);
};

const buildLayoutNodes = (
    nodes: BaseNode[],
    edges: NormalizedEdge[],
    seedPositions: Map<string, GraphNodePosition> = new Map(),
): NormalizedNode[] => {
    if (nodes.length === 0) {
        return [];
    }

    const layoutGraph = new Graph({
        type: 'directed',
        multi: false,
        allowSelfLoops: false,
    });

    for (const node of nodes) {
        const seed = resolveFiniteGraphPosition(
            seedPositions.get(node.id),
            seedPosition(node),
        );
        layoutGraph.addNode(node.id, {
            x: seed.x,
            y: seed.y,
        });
    }

    for (const edge of edges) {
        if (!layoutGraph.hasNode(edge.from) || !layoutGraph.hasNode(edge.to)) {
            continue;
        }

        if (layoutGraph.hasEdge(edge.id)) {
            continue;
        }

        layoutGraph.addEdgeWithKey(edge.id, edge.from, edge.to, {
            weight: 1,
        });
    }

    if (layoutGraph.order > 1) {
        const inferredSettings = forceAtlas2.inferSettings(layoutGraph);
        forceAtlas2.assign(layoutGraph, {
            iterations: Math.min(300, Math.max(120, nodes.length * 8)),
            settings: {
                ...inferredSettings,
                gravity: 0.25,
                scalingRatio: 10,
                slowDown: 1.8,
                barnesHutOptimize: nodes.length > 300,
            },
        });
    }

    const nodeIds = layoutGraph.nodes();
    const xAxis = normalizeAxis(
        nodeIds.map((nodeId) => layoutGraph.getNodeAttribute(nodeId, 'x')),
    );
    const yAxis = normalizeAxis(
        nodeIds.map((nodeId) => layoutGraph.getNodeAttribute(nodeId, 'y')),
    );

    return nodes.map((node) => {
        const normalizedPosition = resolveFiniteGraphPosition(
            {
                x: layoutGraph.getNodeAttribute(node.id, 'x'),
                y: layoutGraph.getNodeAttribute(node.id, 'y'),
            },
            seedPosition(node),
        );

        return {
            ...node,
            x: toPaddedRange(normalizedPosition.x, xAxis.min, xAxis.max),
            y: toPaddedRange(normalizedPosition.y, yAxis.min, yAxis.max),
        };
    });
};

const buildEdges = (
    graph: Record<string, unknown> | null | undefined,
    nodes: BaseNode[],
): NormalizedEdge[] => {
    const rawEdges = Array.isArray(graph?.edges) ? graph.edges : [];
    const edgeSet = new Set<string>();
    const nodeIds = new Set(nodes.map((node) => node.id));
    const nodeTypeById = new Map(nodes.map((node) => [node.id, node.type]));
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

        const key = `${from}->${to}`;
        if (edgeSet.has(key)) {
            continue;
        }

        edges.push({
            id: key,
            from,
            to,
            tone: nodeTypeById.get(from) ?? 'other',
        });

        edgeSet.add(key);
    }

    return edges;
};

const nodeColor = (type: GraphNodeType): string => {
    switch (type) {
        case 'event':
            return '#0ea5e9';
        case 'actor':
            return '#10b981';
        default:
            return '#64748b';
    }
};

const edgeColor = (type: GraphNodeType): string => {
    switch (type) {
        case 'event':
            return '#38bdf8';
        case 'actor':
            return '#34d399';
        default:
            return '#94a3b8';
    }
};

const fallbackNodeFill = (type: GraphNodeType): string => {
    switch (type) {
        case 'event':
            return '#f0f9ff';
        case 'actor':
            return '#ecfdf5';
        default:
            return '#f8fafc';
    }
};

const fallbackNodeStroke = (type: GraphNodeType): string => {
    switch (type) {
        case 'event':
            return '#38bdf8';
        case 'actor':
            return '#34d399';
        default:
            return '#94a3b8';
    }
};

const scheduleProgrammaticHighlightRefresh = (): void => {
    if (programmaticHighlightFrame !== null) {
        return;
    }

    programmaticHighlightFrame = requestAnimationFrame(() => {
        programmaticHighlightFrame = null;

        const now = performance.now();
        const hasActiveEdgeHighlights = pruneProgrammaticHighlights(
            programmaticEdgeHighlights.value,
            now,
        );
        const hasActiveNodeHighlights = pruneProgrammaticHighlights(
            programmaticNodeHighlights.value,
            now,
        );

        refreshActiveRenderer();

        if (hasActiveEdgeHighlights || hasActiveNodeHighlights) {
            scheduleProgrammaticHighlightRefresh();
        }
    });
};

const refreshActiveRenderer = (): void => {
    logFlowGraphVisibility('FlowGraphRenderer.refreshActiveRenderer', {
        hasSigmaRenderer: sigmaRenderer !== null,
        hasFallbackGraph: fallbackGraph.value !== null,
        activeGraphState: summarizeBuiltGraphStateForDebug(activeGraphState.value),
    });

    if (sigmaRenderer) {
        const sigmaGraph = sigmaRenderer.getGraph();
        const graphState = activeGraphState.value;

        for (const nodeId of sigmaGraph.nodes()) {
            const fallbackNode =
                graphState?.positionedNodeById.get(nodeId) ??
                graphState?.nodeById.get(nodeId);

            if (!fallbackNode) {
                continue;
            }

            sigmaGraph.mergeNodeAttributes(
                nodeId,
                resolveFiniteGraphPosition(
                    {
                        x: sigmaGraph.getNodeAttribute(nodeId, 'x'),
                        y: sigmaGraph.getNodeAttribute(nodeId, 'y'),
                    },
                    seedPosition(fallbackNode),
                ),
            );
        }
    }

    sigmaRenderer?.refresh();

    if (fallbackGraph.value) {
        fallbackRenderVersion.value += 1;
    }
};

const setHoveredNode = (
    nodeId: string | null,
    edgeIdsByNode: Map<string, Set<string>> | null = null,
): void => {
    hoveredNodeId.value = nodeId;
    hoverHighlightedEdgeIds.value =
        nodeId && edgeIdsByNode
            ? new Set(edgeIdsByNode.get(nodeId) ?? [])
            : new Set<string>();

    refreshActiveRenderer();
};

const setHoveredEdgeHighlight = (
    highlight: FlowGraphEdgeHighlight | null,
): void => {
    currentHoveredEdgeHighlight = highlight;
    externallyHoveredEdgeIds.value = highlight
        ? resolveDirectHighlightEdgeIds(props.graph, highlight)
        : new Set<string>();

    if (!sigmaRenderer && !fallbackGraph.value) {
        return;
    }

    refreshActiveRenderer();
};

const highlightEdgeIds = (edgeIds: Iterable<string>): boolean => {
    const startedAt = performance.now();
    let hasHighlightedEdge = false;

    for (const edgeId of edgeIds) {
        if (sigmaRenderer && !sigmaRenderer.getGraph().hasEdge(edgeId)) {
            continue;
        }

        if (
            fallbackGraph.value &&
            !fallbackGraph.value.edges.some((edge) => edge.id === edgeId)
        ) {
            continue;
        }

        programmaticEdgeHighlights.value.set(edgeId, startedAt);
        hasHighlightedEdge = true;
    }

    if (!hasHighlightedEdge) {
        return false;
    }

    refreshActiveRenderer();
    scheduleProgrammaticHighlightRefresh();

    return true;
};

const highlightDispatchPath = (highlight: DispatchPathHighlight): void => {
    if (!sigmaRenderer && !fallbackGraph.value) {
        pendingDispatchHighlight = highlight;
        return;
    }

    pendingDispatchHighlight = null;

    const edgeIds = resolveDispatchHighlightEdgeIds(props.graph, highlight);
    if (edgeIds.size === 0) {
        return;
    }

    highlightEdgeIds(edgeIds);
};

const focusEdge = (highlight: FlowGraphEdgeHighlight): void => {
    const edgeIds = resolveDirectHighlightEdgeIds(props.graph, highlight);
    if (edgeIds.size === 0) {
        return;
    }

    highlightEdgeIds(edgeIds);
    highlightNode(highlight.from);
    highlightNode(highlight.to);

    if (!sigmaRenderer) {
        const fromPoint = resolveFallbackNodePoint(highlight.from);
        const toPoint = resolveFallbackNodePoint(highlight.to);

        if (!fromPoint || !toPoint) {
            return;
        }

        const rootBounds = rootRef.value?.getBoundingClientRect();
        const aspectRatio =
            rootBounds && rootBounds.width > 0 && rootBounds.height > 0
                ? rootBounds.width / rootBounds.height
                : undefined;

        animateFallbackViewport(
            resolveSvgViewportFromBounds(
                expandSvgBounds(
                    {
                        minX: Math.min(fromPoint.x, toPoint.x),
                        minY: Math.min(fromPoint.y, toPoint.y),
                        maxX: Math.max(fromPoint.x, toPoint.x),
                        maxY: Math.max(fromPoint.y, toPoint.y),
                    },
                    FALLBACK_SHADOW_BOUNDS_MARGIN,
                ),
                120,
                aspectRatio,
            ),
        );

        return;
    }

    const graph = sigmaRenderer.getGraph();
    if (!graph.hasNode(highlight.from) || !graph.hasNode(highlight.to)) {
        return;
    }

    const fromX = graph.getNodeAttribute(highlight.from, 'x');
    const fromY = graph.getNodeAttribute(highlight.from, 'y');
    const toX = graph.getNodeAttribute(highlight.to, 'x');
    const toY = graph.getNodeAttribute(highlight.to, 'y');

    sigmaRenderer.getCamera().animate(
        {
            x: (fromX + toX) / 2,
            y: (fromY + toY) / 2,
            ratio: DEFAULT_CAMERA_RATIO,
            angle: 0,
        },
        { duration: FOCUS_TRANSITION_DURATION_MS },
    );
};

const highlightNode = (nodeId: string): void => {
    const startedAt = performance.now();

    if (sigmaRenderer?.getGraph().hasNode(nodeId)) {
        programmaticNodeHighlights.value.set(nodeId, startedAt);
    }

    if (fallbackGraph.value?.positionedNodeById.has(nodeId)) {
        programmaticNodeHighlights.value.set(nodeId, startedAt);
    }

    if (!programmaticNodeHighlights.value.has(nodeId)) {
        return;
    }

    refreshActiveRenderer();
    scheduleProgrammaticHighlightRefresh();
};

const drawRoundedLabel = (
    context: CanvasRenderingContext2D,
    data: {
        x: number;
        y: number;
        size: number;
        label: string | null;
    },
    settings: {
        labelSize: number;
        labelFont: string;
        labelWeight: string;
    },
): void => {
    const label = data.label;
    if (!label) {
        return;
    }

    const fontSize = settings.labelSize;
    const font = `${settings.labelWeight} ${fontSize}px ${settings.labelFont}`;
    context.save();
    context.font = font;
    context.textAlign = 'center';
    context.textBaseline = 'middle';

    const textWidth = context.measureText(label).width;
    const paddingX = 7;
    const paddingY = 4;
    const boxWidth = textWidth + paddingX * 2;
    const boxHeight = fontSize + paddingY * 2;
    const boxX = data.x - boxWidth / 2;
    const boxY = data.y - data.size - boxHeight - 8;
    const radius = 7;

    context.beginPath();
    context.moveTo(boxX + radius, boxY);
    context.arcTo(
        boxX + boxWidth,
        boxY,
        boxX + boxWidth,
        boxY + boxHeight,
        radius,
    );
    context.arcTo(
        boxX + boxWidth,
        boxY + boxHeight,
        boxX,
        boxY + boxHeight,
        radius,
    );
    context.arcTo(boxX, boxY + boxHeight, boxX, boxY, radius);
    context.arcTo(boxX, boxY, boxX + boxWidth, boxY, radius);
    context.closePath();

    context.fillStyle = 'rgba(248, 250, 252, 0.94)';
    context.fill();
    context.lineWidth = 1;
    context.strokeStyle = 'rgba(15, 23, 42, 0.25)';
    context.stroke();

    context.fillStyle = '#0f172a';
    context.fillText(label, data.x, boxY + boxHeight / 2);
    context.restore();
};

const toZoomPercent = (ratio: number): number => {
    if (!Number.isFinite(ratio) || ratio <= 0) {
        return 100;
    }

    return Math.max(
        10,
        Math.min(800, Math.round((DEFAULT_CAMERA_RATIO / ratio) * 100)),
    );
};

const buildGraphSignature = (
    graph?: Record<string, unknown> | null,
): string => {
    const nodes = buildNodes(graph);
    const nodeSignature = nodes
        .map(
            (node) =>
                `${node.id}:${node.type}:${node.label}:${node.sourceLine ?? ''}:${node.sourceKind ?? ''}:${node.sourceModule ?? ''}`,
        )
        .sort();
    const edgeSignature = buildEdges(graph, nodes)
        .map((edge) => `${edge.from}->${edge.to}`)
        .sort();

    const signature = `n:${nodeSignature.join('|')}|e:${edgeSignature.join('|')}`;

    logFlowGraphVisibility('FlowGraphRenderer.buildGraphSignature', {
        graph: summarizeGraphForDebug(graph),
        signature,
    });

    return signature;
};

const destroyRenderer = (): void => {
    clearMountRetry();
    clearProgrammaticHighlightFrame();
    clearFallbackViewportAnimation();
    clearNodeAnimation();
    pendingSigmaGraphCleanup = null;

    if (releaseCameraListener) {
        releaseCameraListener();
        releaseCameraListener = null;
    }

    if (releaseInteractionListeners) {
        releaseInteractionListeners();
        releaseInteractionListeners = null;
    }

    if (sigmaRenderer) {
        sigmaRenderer.kill();
        sigmaRenderer = null;
    }

    fallbackGraph.value = null;
    fallbackBaseViewport.value = createDefaultSvgViewport();
    fallbackViewport.value = { ...fallbackBaseViewport.value };
    fallbackDragState.value = null;
    activeGraphState.value = null;

    renderedGraphSignature.value = null;

    hoveredNodeId.value = null;
    hoverHighlightedEdgeIds.value = new Set<string>();
    externallyHoveredEdgeIds.value = new Set<string>();
    programmaticEdgeHighlights.value = new Map<string, number>();
    programmaticNodeHighlights.value = new Map<string, number>();
};

const buildGraphState = (
    graph: Record<string, unknown> | null | undefined = props.graph,
    seedPositions: Map<string, GraphNodePosition> = new Map(),
): BuiltGraphState => {
    const nodes = buildNodes(graph);
    const edges = buildEdges(graph, nodes);
    const positionedNodes = buildLayoutNodes(nodes, edges, seedPositions);
    const sigmaGraph = new Graph({
        type: 'directed',
        multi: false,
        allowSelfLoops: false,
    });
    const edgeIdsByNode = new Map<string, Set<string>>();
    const nodeById = new Map(nodes.map((node) => [node.id, node]));
    const positionedNodeById = new Map(
        positionedNodes.map((node) => [node.id, node]),
    );

    for (const node of positionedNodes) {
        sigmaGraph.addNode(node.id, {
            x: node.x,
            y: node.y,
            size: 10,
            color: nodeColor(node.type),
            label: node.shortLabel,
            labelColor: '#0f172a',
            type: node.type,
        });
    }

    for (const edge of edges) {
        if (!sigmaGraph.hasNode(edge.from) || !sigmaGraph.hasNode(edge.to)) {
            continue;
        }

        sigmaGraph.addEdgeWithKey(edge.id, edge.from, edge.to, {
            type: 'arrow',
            size: FLOW_GRAPH_EDGE_BASE_SIZE,
            color: edgeColor(edge.tone),
        });

        const fromEdgeIds = edgeIdsByNode.get(edge.from) ?? new Set<string>();
        fromEdgeIds.add(edge.id);
        edgeIdsByNode.set(edge.from, fromEdgeIds);

        const toEdgeIds = edgeIdsByNode.get(edge.to) ?? new Set<string>();
        toEdgeIds.add(edge.id);
        edgeIdsByNode.set(edge.to, toEdgeIds);
    }

    const graphState = {
        sigmaGraph,
        nodes: positionedNodes,
        edges,
        edgeIdsByNode,
        nodeById,
        positionedNodeById,
    };

    logFlowGraphVisibility('FlowGraphRenderer.buildGraphState', {
        inputGraph: summarizeGraphForDebug(graph),
        seedPositionCount: seedPositions.size,
        graphState: summarizeBuiltGraphStateForDebug(graphState),
    });

    return graphState;
};

const readSigmaNodePositions = (graph: Graph): Map<string, GraphNodePosition> => {
    const positions = new Map<string, GraphNodePosition>();

    for (const nodeId of graph.nodes()) {
        const x = graph.getNodeAttribute(nodeId, 'x');
        const y = graph.getNodeAttribute(nodeId, 'y');

        if (!isFiniteGraphCoordinate(x) || !isFiniteGraphCoordinate(y)) {
            continue;
        }

        positions.set(nodeId, { x, y });
    }

    logFlowGraphVisibility('FlowGraphRenderer.readSigmaNodePositions', {
        nodeCount: positions.size,
        nodeIds: [...positions.keys()],
    });

    return positions;
};

const syncSigmaGraphState = (nextGraphState: BuiltGraphState): void => {
    if (!sigmaRenderer) {
        return;
    }

    const currentCameraState = sigmaRenderer.getCamera().getState();

    logFlowGraphVisibility('FlowGraphRenderer.syncSigmaGraphState', {
        cameraState: currentCameraState,
        nextGraphState: summarizeBuiltGraphStateForDebug(nextGraphState),
    });

    mountRenderer(currentCameraState, nextGraphState);
};

const syncFallbackGraphState = (nextGraphState: BuiltGraphState): void => {
    const nextNodeIds = new Set(nextGraphState.nodes.map((node) => node.id));

    if (hoveredNodeId.value && !nextNodeIds.has(hoveredNodeId.value)) {
        hoveredNodeId.value = null;
        hoverHighlightedEdgeIds.value = new Set<string>();
    }

    fallbackGraph.value = nextGraphState;
    activeGraphState.value = nextGraphState;
    renderedGraphSignature.value = buildGraphSignature(props.graph);

    refreshFallbackViewport();

    if (currentHoveredEdgeHighlight) {
        externallyHoveredEdgeIds.value = resolveDirectHighlightEdgeIds(
            props.graph,
            currentHoveredEdgeHighlight,
        );
    }

    logFlowGraphVisibility('FlowGraphRenderer.syncFallbackGraphState', {
        nextGraphState: summarizeBuiltGraphStateForDebug(nextGraphState),
        renderedGraphSignature: renderedGraphSignature.value,
    });

    refreshActiveRenderer();
};

const animateSigmaGraphExit = (): void => {
    if (!sigmaRenderer) {
        logFlowGraphVisibility('FlowGraphRenderer.animateSigmaGraphExit', {
            hasSigmaRenderer: false,
            activeGraphState: summarizeBuiltGraphStateForDebug(activeGraphState.value),
        });

        destroyRenderer();
        emit('zoom-change', 100);
        return;
    }

    clearNodeAnimation();
    flushPendingSigmaGraphCleanup();

    const sigmaGraph = sigmaRenderer.getGraph();
    const animationTargets: Record<string, Record<string, number>> = {};

    for (const nodeId of sigmaGraph.nodes()) {
        animationTargets[nodeId] = { size: 0 };
    }

    logFlowGraphVisibility('FlowGraphRenderer.animateSigmaGraphExit', {
        hasSigmaRenderer: true,
        nodeIds: sigmaGraph.nodes(),
        activeGraphState: summarizeBuiltGraphStateForDebug(activeGraphState.value),
    });

    pendingSigmaGraphCleanup = () => {
        destroyRenderer();
        emit('zoom-change', 100);
    };

    cancelNodeAnimation = animateNodes(
        sigmaGraph,
        animationTargets,
        {
            duration: SIGMA_LAYOUT_ANIMATION_DURATION_MS,
            easing: 'quadraticInOut',
        },
        () => {
            clearSigmaAnimationRefreshFrame();
            cancelNodeAnimation = null;
            flushPendingSigmaGraphCleanup();
        },
    );

    refreshActiveRenderer();
    scheduleSigmaAnimationRefresh();
};

const buildFallbackViewport = (graphState: BuiltGraphState): SvgViewport => {
    if (graphState.nodes.length === 0) {
        return createDefaultSvgViewport();
    }

    const rootBounds = rootRef.value?.getBoundingClientRect();
    const aspectRatio =
        rootBounds && rootBounds.width > 0 && rootBounds.height > 0
            ? rootBounds.width / rootBounds.height
            : undefined;

    let minX = Number.POSITIVE_INFINITY;
    let minY = Number.POSITIVE_INFINITY;
    let maxX = Number.NEGATIVE_INFINITY;
    let maxY = Number.NEGATIVE_INFINITY;

    for (const node of graphState.nodes) {
        const point = toSvgPoint(node.x, node.y);
        const labelFrame = estimateSvgLabelFrame(node.shortLabel, point);

        minX = Math.min(minX, point.x - SVG_NODE_RADIUS - 8, labelFrame.x);
        minY = Math.min(minY, point.y - SVG_NODE_RADIUS - 8, labelFrame.y);
        maxX = Math.max(
            maxX,
            point.x + SVG_NODE_RADIUS + 8,
            labelFrame.x + labelFrame.width,
        );
        maxY = Math.max(
            maxY,
            point.y + SVG_NODE_RADIUS + 8,
            labelFrame.y + labelFrame.height,
        );
    }

    return resolveSvgViewportFromBounds(
        expandSvgBounds(
            { minX, minY, maxX, maxY },
            FALLBACK_SHADOW_BOUNDS_MARGIN,
        ),
        72,
        aspectRatio,
    );
};

const refreshFallbackViewport = (): void => {
    if (!fallbackGraph.value) {
        return;
    }

    clearFallbackViewportAnimation();

    const fittedViewport = buildFallbackViewport(fallbackGraph.value);
    fallbackBaseViewport.value = fittedViewport;
    fallbackViewport.value = { ...fittedViewport };
    emit('zoom-change', fallbackZoomPercent.value);
};

const animateFallbackViewport = (targetViewport: SvgViewport): void => {
    clearFallbackViewportAnimation();

    const startViewport = { ...fallbackViewport.value };
    const startedAt = performance.now();

    const step = (now: number): void => {
        const progress = Math.min(
            (now - startedAt) / FOCUS_TRANSITION_DURATION_MS,
            1,
        );

        fallbackViewport.value = interpolateSvgViewport(
            startViewport,
            targetViewport,
            easeInOutCubic(progress),
        );
        emit('zoom-change', fallbackZoomPercent.value);

        if (progress >= 1) {
            fallbackViewportAnimationFrame = null;
            return;
        }

        fallbackViewportAnimationFrame = requestAnimationFrame(step);
    };

    fallbackViewportAnimationFrame = requestAnimationFrame(step);
};

const mountRenderer = (
    cameraState?: CameraStateSnapshot | null,
    graphStateOverride?: BuiltGraphState | null,
): void => {
    if (!hasRenderableGraph.value) {
        logFlowGraphVisibility('FlowGraphRenderer.mountRenderer.emptyGraph', {
            cameraState,
            graphStateOverride: summarizeBuiltGraphStateForDebug(
                graphStateOverride ?? null,
            ),
        });

        destroyRenderer();
        emit('zoom-change', 100);
        return;
    }

    const container = containerRef.value;
    if (!container) {
        logFlowGraphVisibility('FlowGraphRenderer.mountRenderer.noContainer', {
            cameraState,
        });

        return;
    }

    const rect = container.getBoundingClientRect();
    if (!hasRenderableContainerSize(rect.width, rect.height)) {
        logFlowGraphVisibility('FlowGraphRenderer.mountRenderer.noRenderableSize', {
            cameraState,
            width: rect.width,
            height: rect.height,
            mountRetryCount,
        });

        if (mountRetryCount >= MAX_MOUNT_RETRIES) {
            return;
        }

        mountRetryCount += 1;
        clearMountRetry();
        mountRetryFrame = requestAnimationFrame(() => {
            mountRenderer(cameraState);
        });

        return;
    }

    mountRetryCount = 0;
    clearMountRetry();

    destroyRenderer();

    const graphState = graphStateOverride ?? buildGraphState();
    const { sigmaGraph } = graphState;

    logFlowGraphVisibility('FlowGraphRenderer.mountRenderer.start', {
        cameraState,
        graphStateOverride: graphStateOverride
            ? summarizeBuiltGraphStateForDebug(graphStateOverride)
            : null,
        graphState: summarizeBuiltGraphStateForDebug(graphState),
    });

    try {
        sigmaRenderer = new Sigma(sigmaGraph, container, {
            hideEdgesOnMove: false,
            hideLabelsOnMove: false,
            renderLabels: true,
            renderEdgeLabels: false,
            zIndex: true,
            defaultNodeType: 'circle',
            defaultEdgeType: 'arrow',
            enableEdgeEvents: false,
            defaultDrawNodeHover: () => {},
            nodeReducer: (node, data) => {
                const programmaticHighlightStrength =
                    getProgrammaticHighlightStrength(
                        programmaticNodeHighlights.value,
                        node,
                    );

                return {
                    ...data,
                    ...resolveNodeHighlightAttributes({
                        baseColor:
                            typeof data.color === 'string'
                                ? data.color
                                : nodeColor('other'),
                        baseSize:
                            typeof data.size === 'number' ? data.size : 10,
                        hovered: hoveredNodeId.value === node,
                        programmaticHighlightStrength,
                    }),
                };
            },
            edgeReducer: (edge, data) => {
                const programmaticHighlightStrength =
                    getProgrammaticHighlightStrength(
                        programmaticEdgeHighlights.value,
                        edge,
                    );

                return {
                    ...data,
                    ...resolveEdgeHighlightAttributes({
                        edgeId: edge,
                        baseColor:
                            typeof data.color === 'string'
                                ? data.color
                                : edgeColor('other'),
                        baseSize:
                            typeof data.size === 'number'
                                ? data.size
                                : FLOW_GRAPH_EDGE_BASE_SIZE,
                        hoverActive: hasActiveEdgeHover.value,
                        hoverHighlightedEdgeIds:
                            activeHoverHighlightedEdgeIds.value,
                        programmaticHighlightStrength,
                    }),
                };
            },
            labelFont: 'IBM Plex Sans, Inter, system-ui, sans-serif',
            labelSize: 12,
            labelWeight: '600',
            labelColor: { attribute: 'labelColor', color: '#0f172a' },
            labelDensity: 0.9,
            labelGridCellSize: 48,
            labelRenderedSizeThreshold: 0,
            minCameraRatio: MIN_RATIO,
            maxCameraRatio: MAX_RATIO,
            defaultDrawNodeLabel: drawRoundedLabel,
        });
    } catch {
        destroyRenderer();
        const fittedViewport = buildFallbackViewport(graphState);
        fallbackGraph.value = graphState;
        fallbackBaseViewport.value = fittedViewport;
        fallbackViewport.value = { ...fittedViewport };
        activeGraphState.value = graphState;
        renderedGraphSignature.value = buildGraphSignature(props.graph);
        emit('zoom-change', fallbackZoomPercent.value);

        if (pendingDispatchHighlight) {
            highlightDispatchPath(pendingDispatchHighlight);
        }

        if (currentHoveredEdgeHighlight) {
            setHoveredEdgeHighlight(currentHoveredEdgeHighlight);
        }

        logFlowGraphVisibility('FlowGraphRenderer.mountRenderer.fallback', {
            cameraState,
            graphState: summarizeBuiltGraphStateForDebug(graphState),
        });

        return;
    }

    fallbackGraph.value = null;
    activeGraphState.value = graphState;

    const camera = sigmaRenderer.getCamera();
    const onCameraUpdate = (): void => {
        emit('zoom-change', toZoomPercent(camera.getState().ratio));
    };

    camera.on('updated', onCameraUpdate);
    releaseCameraListener = () => {
        camera.off('updated', onCameraUpdate);
    };

    const onEnterNode = ({ node }: { node: string }): void => {
        setHoveredNode(node, activeGraphState.value?.edgeIdsByNode ?? null);
    };

    const onLeaveNode = (): void => {
        if (!hoveredNodeId.value) {
            return;
        }

        setHoveredNode(null);
    };

    const onClickNode = ({ node }: { node: string }): void => {
        const selectedNode = activeGraphState.value?.nodeById.get(node);
        if (!selectedNode) {
            return;
        }

        focusNode(node);
        emit('node-click', selectedNode);
    };

    sigmaRenderer.on('enterNode', onEnterNode);
    sigmaRenderer.on('leaveNode', onLeaveNode);
    sigmaRenderer.on('clickNode', onClickNode);
    releaseInteractionListeners = () => {
        sigmaRenderer?.off('enterNode', onEnterNode);
        sigmaRenderer?.off('leaveNode', onLeaveNode);
        sigmaRenderer?.off('clickNode', onClickNode);
    };

    camera.setState(cameraState ?? DEFAULT_CAMERA_STATE);
    onCameraUpdate();
    renderedGraphSignature.value = buildGraphSignature(props.graph);

    if (pendingDispatchHighlight) {
        highlightDispatchPath(pendingDispatchHighlight);
    }

    if (currentHoveredEdgeHighlight) {
        setHoveredEdgeHighlight(currentHoveredEdgeHighlight);
    }

    logFlowGraphVisibility('FlowGraphRenderer.mountRenderer.sigma', {
        cameraState,
        graphState: summarizeBuiltGraphStateForDebug(graphState),
        renderedGraphSignature: renderedGraphSignature.value,
    });
};

const withCamera = (callback: (ratio: number) => void): void => {
    const camera = sigmaRenderer?.getCamera();
    if (!camera) {
        return;
    }

    callback(camera.getState().ratio);
};

const zoomIn = (): void => {
    if (!sigmaRenderer) {
        if (fallbackGraph.value) {
            clearFallbackViewportAnimation();
            fallbackViewport.value = zoomSvgViewport(
                fallbackViewport.value,
                'in',
                { baseViewport: fallbackBaseViewport.value },
            );
            emit('zoom-change', fallbackZoomPercent.value);
        }

        return;
    }

    withCamera((ratio) => {
        sigmaRenderer
            ?.getCamera()
            .animate(
                { ratio: Math.max(MIN_RATIO, ratio / ZOOM_STEP) },
                { duration: 150 },
            );
    });
};

const zoomOut = (): void => {
    if (!sigmaRenderer) {
        if (fallbackGraph.value) {
            clearFallbackViewportAnimation();
            fallbackViewport.value = zoomSvgViewport(
                fallbackViewport.value,
                'out',
                { baseViewport: fallbackBaseViewport.value },
            );
            emit('zoom-change', fallbackZoomPercent.value);
        }

        return;
    }

    withCamera((ratio) => {
        sigmaRenderer
            ?.getCamera()
            .animate(
                { ratio: Math.min(MAX_RATIO, ratio * ZOOM_STEP) },
                { duration: 150 },
            );
    });
};

const resetView = (): void => {
    if (!sigmaRenderer) {
        clearFallbackViewportAnimation();
        fallbackViewport.value = { ...fallbackBaseViewport.value };
        emit('zoom-change', fallbackZoomPercent.value);
        return;
    }

    sigmaRenderer?.getCamera().animate(DEFAULT_CAMERA_STATE, { duration: 180 });
};

const focusNode = (nodeId: string): void => {
    if (!nodeId) {
        return;
    }

    if (!sigmaRenderer) {
        const point = resolveFallbackNodePoint(nodeId);
        if (!point) {
            return;
        }

        highlightNode(nodeId);
        animateFallbackViewport(
            resolveFocusedSvgViewportOnPoint(
                point,
                FOCUS_ZOOM_PERCENT,
                fallbackBaseViewport.value,
            ),
        );
        return;
    }

    const graph = sigmaRenderer.getGraph();
    if (!graph.hasNode(nodeId)) {
        return;
    }

    highlightNode(nodeId);

    const camera = sigmaRenderer.getCamera();
    const focusRatio = Math.max(
        MIN_RATIO,
        Math.min(MAX_RATIO, DEFAULT_CAMERA_RATIO / (FOCUS_ZOOM_PERCENT / 100)),
    );

    camera.animate(
        {
            x: graph.getNodeAttribute(nodeId, 'x'),
            y: graph.getNodeAttribute(nodeId, 'y'),
            ratio: focusRatio,
            angle: 0,
        },
        { duration: FOCUS_TRANSITION_DURATION_MS },
    );
};

const resolveFallbackNodePoint = (nodeId: string) => {
    const node = fallbackGraph.value?.positionedNodeById.get(nodeId);

    return node ? toSvgPoint(node.x, node.y) : null;
};

const resolveFallbackEdgeLine = (edge: NormalizedEdge) => {
    const from = resolveFallbackNodePoint(edge.from);
    const to = resolveFallbackNodePoint(edge.to);

    if (!from || !to) {
        return null;
    }

    return resolveSvgLine(from, to, SVG_NODE_RADIUS + 2);
};

const resolveFallbackLabelFrame = (node: NormalizedNode) => {
    return estimateSvgLabelFrame(node.shortLabel, toSvgPoint(node.x, node.y));
};

const resolveFallbackEdgeStyle = (edge: NormalizedEdge) => {
    const programmaticHighlightStrength = getProgrammaticHighlightStrength(
        programmaticEdgeHighlights.value,
        edge.id,
    );
    const hoverHighlighted = activeHoverHighlightedEdgeIds.value.has(edge.id);
    const color = resolveFallbackEdgeColor(
        edgeColor(edge.tone),
        hasActiveEdgeHover.value,
        hoverHighlighted,
        programmaticHighlightStrength,
    );

    return {
        stroke: color,
        strokeWidth: resolveHighlightedEdgeSize({
            baseSize: FLOW_GRAPH_EDGE_BASE_SIZE,
            hoverHighlighted,
            programmaticHighlightStrength,
        }),
        strokeOpacity:
            hasActiveEdgeHover.value &&
            !hoverHighlighted &&
            programmaticHighlightStrength <= 0
                ? 0.6
                : 1,
    };
};

const handleFallbackNodeEnter = (nodeId: string): void => {
    setHoveredNode(nodeId, fallbackGraph.value?.edgeIdsByNode ?? null);
};

const handleFallbackNodeLeave = (): void => {
    if (!hoveredNodeId.value) {
        return;
    }

    setHoveredNode(null);
};

const handleFallbackNodeClick = (nodeId: string): void => {
    const node = fallbackGraph.value?.nodeById.get(nodeId);
    if (!node) {
        return;
    }

    focusNode(nodeId);
    emit('node-click', node);
};

const handleFallbackPointerDown = (event: PointerEvent): void => {
    if (event.button !== 0) {
        return;
    }

    clearFallbackViewportAnimation();

    const target = event.target;
    if (
        target instanceof Element &&
        target.closest('[data-fallback-node="true"]')
    ) {
        return;
    }

    const svg = event.currentTarget;
    if (!(svg instanceof SVGSVGElement)) {
        return;
    }

    fallbackDragState.value = {
        pointerId: event.pointerId,
        startClientX: event.clientX,
        startClientY: event.clientY,
        startViewport: { ...fallbackViewport.value },
    };

    svg.setPointerCapture(event.pointerId);
};

const handleFallbackPointerMove = (event: PointerEvent): void => {
    const dragState = fallbackDragState.value;
    if (!dragState || dragState.pointerId !== event.pointerId) {
        return;
    }

    const svg = event.currentTarget;
    if (!(svg instanceof SVGSVGElement)) {
        return;
    }

    const bounds = svg.getBoundingClientRect();
    if (bounds.width <= 0 || bounds.height <= 0) {
        return;
    }

    const deltaX =
        ((event.clientX - dragState.startClientX) / bounds.width) *
        dragState.startViewport.width;
    const deltaY =
        ((event.clientY - dragState.startClientY) / bounds.height) *
        dragState.startViewport.height;

    fallbackViewport.value = panSvgViewport(
        dragState.startViewport,
        deltaX,
        deltaY,
    );
};

const resolveFallbackSvgPoint = (
    svg: SVGSVGElement,
    clientX: number,
    clientY: number,
) => {
    const bounds = svg.getBoundingClientRect();
    if (bounds.width <= 0 || bounds.height <= 0) {
        return null;
    }

    return {
        x:
            fallbackViewport.value.x +
            ((clientX - bounds.left) / bounds.width) *
                fallbackViewport.value.width,
        y:
            fallbackViewport.value.y +
            ((clientY - bounds.top) / bounds.height) *
                fallbackViewport.value.height,
    };
};

const handleFallbackWheel = (event: WheelEvent): void => {
    if (!fallbackGraph.value) {
        return;
    }

    clearFallbackViewportAnimation();

    const svg = event.currentTarget;
    if (!(svg instanceof SVGSVGElement)) {
        return;
    }

    const anchor = resolveFallbackSvgPoint(svg, event.clientX, event.clientY);
    if (!anchor) {
        return;
    }

    const zoomMode = resolveSvgWheelZoomMode(event.ctrlKey);

    event.preventDefault();
    event.stopPropagation();

    const pixelDelta = resolveSvgWheelPixelDelta(event.deltaY, event.deltaMode);
    const zoomScale = resolveSvgWheelZoomScale(pixelDelta, zoomMode);

    fallbackViewport.value = scaleSvgViewport(
        fallbackViewport.value,
        zoomScale,
        {
            anchor,
            baseViewport: fallbackBaseViewport.value,
        },
    );
    emit('zoom-change', fallbackZoomPercent.value);
};

const handleFallbackPointerUp = (event: PointerEvent): void => {
    const dragState = fallbackDragState.value;
    if (!dragState || dragState.pointerId !== event.pointerId) {
        return;
    }

    const svg = event.currentTarget;
    if (
        svg instanceof SVGSVGElement &&
        svg.hasPointerCapture(event.pointerId)
    ) {
        svg.releasePointerCapture(event.pointerId);
    }

    fallbackDragState.value = null;
};

const fallbackEdgeViews = computed(() => {
    void fallbackRenderVersion.value;

    return (fallbackGraph.value?.edges ?? [])
        .map((edge) => {
            const line = resolveFallbackEdgeLine(edge);
            if (!line) {
                return null;
            }

            return {
                edge,
                line,
                style: resolveFallbackEdgeStyle(edge),
            };
        })
        .filter((edgeView): edgeView is NonNullable<typeof edgeView> => {
            return edgeView !== null;
        });
});

const fallbackNodeViews = computed(() => {
    void fallbackRenderVersion.value;

    return (fallbackGraph.value?.nodes ?? []).map((node) => {
        const point = toSvgPoint(node.x, node.y);
        const hovered = hoveredNodeId.value === node.id;
        const programmaticHighlightStrength = getProgrammaticHighlightStrength(
            programmaticNodeHighlights.value,
            node.id,
        );
        const highlightAttributes = resolveNodeHighlightAttributes({
            baseColor: fallbackNodeStroke(node.type),
            baseSize: 16,
            hovered,
            programmaticHighlightStrength,
        });
        const outerStroke =
            typeof highlightAttributes.color === 'string'
                ? highlightAttributes.color
                : hovered
                  ? '#0f172a'
                  : fallbackNodeStroke(node.type);
        const outerRadius =
            typeof highlightAttributes.size === 'number'
                ? highlightAttributes.size
                : 16;

        return {
            node,
            point,
            labelFrame: resolveFallbackLabelFrame(node),
            hovered,
            outerRadius,
            outerStroke,
            outerStrokeWidth:
                hovered || programmaticHighlightStrength > 0 ? 3 : 2,
            labelStroke:
                programmaticHighlightStrength > 0
                    ? `rgba(34, 197, 94, ${0.28 + programmaticHighlightStrength * 0.32})`
                    : 'rgba(148, 163, 184, 0.32)',
            glowOpacity: programmaticHighlightStrength * 0.35,
        };
    });
});

defineExpose({
    focusEdge,
    zoomIn,
    zoomOut,
    resetView,
    focusNode,
    highlightDispatchPath,
    setHoveredEdgeHighlight,
});

watch(
    () => props.graph,
    async () => {
        pendingDispatchHighlight = null;

        logFlowGraphVisibility('FlowGraphRenderer.watchGraph.start', {
            graph: summarizeGraphForDebug(props.graph),
            hasRenderableGraph: hasRenderableGraph.value,
            hasBeenVisible: hasBeenVisible.value,
            renderedGraphSignature: renderedGraphSignature.value,
            hasSigmaRenderer: sigmaRenderer !== null,
        });

        if (!hasRenderableGraph.value) {
            if (sigmaRenderer) {
                logFlowGraphVisibility('FlowGraphRenderer.watchGraph.emptyGraph', {
                    action: 'animateSigmaGraphExit',
                });

                animateSigmaGraphExit();
                return;
            }

            logFlowGraphVisibility('FlowGraphRenderer.watchGraph.emptyGraph', {
                action: 'destroyRenderer',
            });

            destroyRenderer();
            emit('zoom-change', 100);
            return;
        }

        if (!hasBeenVisible.value) {
            logFlowGraphVisibility('FlowGraphRenderer.watchGraph.skipNotVisible');
            return;
        }

        const nextGraphSignature = buildGraphSignature(props.graph);
        if (nextGraphSignature === renderedGraphSignature.value) {
            logFlowGraphVisibility(
                'FlowGraphRenderer.watchGraph.skipSameSignature',
                {
                    nextGraphSignature,
                    renderedGraphSignature: renderedGraphSignature.value,
                },
            );

            return;
        }

        logFlowGraphVisibility('FlowGraphRenderer.watchGraph.signatureChanged', {
            nextGraphSignature,
            renderedGraphSignature: renderedGraphSignature.value,
        });

        await nextTick();

        if (sigmaRenderer) {
            logFlowGraphVisibility('FlowGraphRenderer.watchGraph.syncSigma', {
                nextGraphSignature,
            });

            syncSigmaGraphState(
                buildGraphState(
                    props.graph,
                    readSigmaNodePositions(sigmaRenderer.getGraph()),
                ),
            );
            return;
        }

        if (fallbackGraph.value) {
            logFlowGraphVisibility('FlowGraphRenderer.watchGraph.syncFallback', {
                nextGraphSignature,
            });

            syncFallbackGraphState(buildGraphState(props.graph));
            return;
        }

        logFlowGraphVisibility('FlowGraphRenderer.watchGraph.mountRenderer', {
            nextGraphSignature,
        });

        mountRenderer();
    },
    { deep: true, immediate: false },
);

onMounted(() => {
    const root = rootRef.value;
    const container = containerRef.value;
    if (!root || !container) {
        return;
    }

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver((entries) => {
            const sizedEntry = entries.find((entry) => {
                return hasRenderableContainerSize(
                    entry.contentRect.width,
                    entry.contentRect.height,
                );
            });

            if (!sizedEntry || !hasBeenVisible.value) {
                return;
            }

            if (fallbackGraph.value) {
                refreshFallbackViewport();
                return;
            }

            if (
                shouldMountRendererOnResize({
                    width: sizedEntry.contentRect.width,
                    height: sizedEntry.contentRect.height,
                    hasBeenVisible: hasBeenVisible.value,
                    hasActiveRenderer: sigmaRenderer !== null,
                })
            ) {
                mountRenderer();
            }
        });

        resizeObserver.observe(root);
    }

    if (typeof IntersectionObserver === 'undefined') {
        hasBeenVisible.value = true;
        mountRenderer();
        return;
    }

    intersectionObserver = new IntersectionObserver(
        async (entries) => {
            const visibleEntry = entries.find((entry) => entry.isIntersecting);
            if (!visibleEntry || hasBeenVisible.value) {
                return;
            }

            hasBeenVisible.value = true;
            await nextTick();
            mountRenderer();
            intersectionObserver?.disconnect();
            intersectionObserver = null;
        },
        {
            root: null,
            rootMargin: '0px',
            threshold: 0.05,
        },
    );

    intersectionObserver.observe(container);

    if (container.getBoundingClientRect().height > 0) {
        requestAnimationFrame(() => {
            if (hasBeenVisible.value) {
                return;
            }

            const rect = container.getBoundingClientRect();
            const viewportHeight =
                window.innerHeight || document.documentElement.clientHeight;
            const isVisible = rect.bottom > 0 && rect.top < viewportHeight;
            if (!isVisible) {
                return;
            }

            hasBeenVisible.value = true;
            mountRenderer();
            intersectionObserver?.disconnect();
            intersectionObserver = null;
        });
    }
});

onBeforeUnmount(() => {
    if (intersectionObserver) {
        intersectionObserver.disconnect();
        intersectionObserver = null;
    }

    if (resizeObserver) {
        resizeObserver.disconnect();
        resizeObserver = null;
    }

    destroyRenderer();
});
</script>

<template>
    <div
        ref="rootRef"
        class="relative h-full w-full overflow-hidden rounded-[inherit] bg-linear-to-br from-background via-background to-muted/20"
    >
        <div
            v-show="!isUsingFallbackRenderer"
            ref="containerRef"
            class="h-full w-full"
        />

        <svg
            v-if="fallbackGraph"
            class="absolute inset-0 h-full w-full touch-none select-none"
            :class="isFallbackDragging ? 'cursor-grabbing' : 'cursor-grab'"
            :viewBox="fallbackViewBox"
            preserveAspectRatio="xMidYMid meet"
            @wheel="handleFallbackWheel"
            @pointercancel="handleFallbackPointerUp"
            @pointerdown="handleFallbackPointerDown"
            @pointermove="handleFallbackPointerMove"
            @pointerup="handleFallbackPointerUp"
        >
            <defs>
                <filter
                    :id="softShadowFilterId"
                    x="-40%"
                    y="-40%"
                    width="180%"
                    height="200%"
                >
                    <feDropShadow
                        dx="0"
                        dy="4"
                        stdDeviation="6"
                        flood-color="rgba(15, 23, 42, 0.08)"
                    />
                </filter>
                <marker
                    :id="arrowMarkerId"
                    :markerWidth="FLOW_GRAPH_FALLBACK_ARROW_MARKER.width"
                    :markerHeight="FLOW_GRAPH_FALLBACK_ARROW_MARKER.height"
                    :refX="FLOW_GRAPH_FALLBACK_ARROW_MARKER.refX"
                    :refY="FLOW_GRAPH_FALLBACK_ARROW_MARKER.refY"
                    orient="auto"
                    :markerUnits="FLOW_GRAPH_FALLBACK_ARROW_MARKER.units"
                >
                    <path
                        :d="FLOW_GRAPH_FALLBACK_ARROW_MARKER.path"
                        fill="context-stroke"
                    />
                </marker>
            </defs>

            <g v-for="edgeView in fallbackEdgeViews" :key="edgeView.edge.id">
                <line
                    :x1="edgeView.line.x1"
                    :y1="edgeView.line.y1"
                    :x2="edgeView.line.x2"
                    :y2="edgeView.line.y2"
                    :stroke="edgeView.style.stroke"
                    :stroke-width="edgeView.style.strokeWidth"
                    :stroke-opacity="edgeView.style.strokeOpacity"
                    :marker-end="`url(#${arrowMarkerId})`"
                    stroke-linecap="round"
                />
            </g>

            <g
                v-for="nodeView in fallbackNodeViews"
                :key="nodeView.node.id"
                data-fallback-node="true"
                class="cursor-pointer"
                @click="handleFallbackNodeClick(nodeView.node.id)"
                @mouseenter="handleFallbackNodeEnter(nodeView.node.id)"
                @mouseleave="handleFallbackNodeLeave"
            >
                <circle
                    v-if="nodeView.glowOpacity > 0"
                    :cx="nodeView.point.x"
                    :cy="nodeView.point.y"
                    :r="nodeView.outerRadius + 7"
                    :fill="PROGRAMMATIC_HIGHLIGHT_COLOR"
                    :fill-opacity="nodeView.glowOpacity"
                    :filter="`url(#${softShadowFilterId})`"
                />

                <circle
                    :cx="nodeView.point.x"
                    :cy="nodeView.point.y"
                    :r="nodeView.outerRadius"
                    :fill="fallbackNodeFill(nodeView.node.type)"
                    :stroke="nodeView.outerStroke"
                    :stroke-width="nodeView.outerStrokeWidth"
                    :filter="`url(#${softShadowFilterId})`"
                />

                <circle
                    :cx="nodeView.point.x"
                    :cy="nodeView.point.y"
                    :r="5"
                    :fill="fallbackNodeStroke(nodeView.node.type)"
                />

                <rect
                    :x="nodeView.labelFrame.x"
                    :y="nodeView.labelFrame.y"
                    :width="nodeView.labelFrame.width"
                    :height="nodeView.labelFrame.height"
                    rx="10"
                    fill="rgba(255, 255, 255, 0.96)"
                    :stroke="nodeView.labelStroke"
                    :filter="`url(#${softShadowFilterId})`"
                />

                <text
                    :x="nodeView.point.x"
                    :y="nodeView.labelFrame.textY"
                    fill="#0f172a"
                    font-family="IBM Plex Sans, Inter, system-ui, sans-serif"
                    font-size="13"
                    font-weight="600"
                    text-anchor="middle"
                    dominant-baseline="middle"
                >
                    {{ nodeView.node.shortLabel }}
                </text>
            </g>
        </svg>
    </div>
</template>
