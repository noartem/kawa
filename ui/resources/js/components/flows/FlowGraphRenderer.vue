<script setup lang="ts">
import Graph from 'graphology';
import forceAtlas2 from 'graphology-layout-forceatlas2';
import Sigma from 'sigma';
import {
    resolveDispatchHighlightEdgeIds,
    resolveEdgeHighlightAttributes,
    type DispatchPathHighlight,
} from './graphHighlights';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
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

const containerRef = ref<HTMLDivElement | null>(null);

let sigmaRenderer: Sigma | null = null;
let releaseCameraListener: (() => void) | null = null;
let releaseInteractionListeners: (() => void) | null = null;
let intersectionObserver: IntersectionObserver | null = null;
let mountRetryFrame: number | null = null;
let mountRetryCount = 0;
let hoveredNodeId: string | null = null;
let hoverHighlightedEdgeIds = new Set<string>();
let programmaticEdgeHighlights = new Map<string, number>();
let programmaticHighlightFrame: number | null = null;
let pendingDispatchHighlight: DispatchPathHighlight | null = null;
const hasBeenVisible = ref(false);
const renderedGraphSignature = ref<string | null>(null);

const hasRenderableGraph = computed(() => {
    return buildNodes(props.graph).length > 0;
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
const DEFAULT_CAMERA_STATE: CameraStateSnapshot = {
    x: 0.5,
    y: 0.5,
    ratio: DEFAULT_CAMERA_RATIO,
    angle: 0,
};
const LAYOUT_PADDING = 0.22;
const MAX_MOUNT_RETRIES = 30;
const PROGRAMMATIC_HIGHLIGHT_FLASH_MS = 180;
const PROGRAMMATIC_HIGHLIGHT_FADE_MS = 1320;

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
        const seed = seedPosition(node);
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
        const x = layoutGraph.getNodeAttribute(node.id, 'x');
        const y = layoutGraph.getNodeAttribute(node.id, 'y');

        return {
            ...node,
            x: toPaddedRange(x, xAxis.min, xAxis.max),
            y: toPaddedRange(y, yAxis.min, yAxis.max),
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

const clamp = (value: number, min: number, max: number): number => {
    return Math.min(max, Math.max(min, value));
};

const getProgrammaticHighlightStrength = (edgeId: string): number => {
    const startedAt = programmaticEdgeHighlights.get(edgeId);

    if (typeof startedAt !== 'number') {
        return 0;
    }

    const elapsed = performance.now() - startedAt;
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

const pruneProgrammaticHighlights = (): boolean => {
    const now = performance.now();
    let hasActiveHighlights = false;

    for (const [edgeId, startedAt] of programmaticEdgeHighlights) {
        if (
            now - startedAt >=
            PROGRAMMATIC_HIGHLIGHT_FLASH_MS + PROGRAMMATIC_HIGHLIGHT_FADE_MS
        ) {
            programmaticEdgeHighlights.delete(edgeId);
            continue;
        }

        hasActiveHighlights = true;
    }

    return hasActiveHighlights;
};

const scheduleProgrammaticHighlightRefresh = (): void => {
    if (programmaticHighlightFrame !== null) {
        return;
    }

    programmaticHighlightFrame = requestAnimationFrame(() => {
        programmaticHighlightFrame = null;

        const hasActiveHighlights = pruneProgrammaticHighlights();
        sigmaRenderer?.refresh();

        if (hasActiveHighlights) {
            scheduleProgrammaticHighlightRefresh();
        }
    });
};

const highlightDispatchPath = (highlight: DispatchPathHighlight): void => {
    if (!sigmaRenderer) {
        pendingDispatchHighlight = highlight;
        return;
    }

    pendingDispatchHighlight = null;

    const edgeIds = resolveDispatchHighlightEdgeIds(props.graph, highlight);
    if (edgeIds.size === 0) {
        return;
    }

    const startedAt = performance.now();

    for (const edgeId of edgeIds) {
        if (!sigmaRenderer.getGraph().hasEdge(edgeId)) {
            continue;
        }

        programmaticEdgeHighlights.set(edgeId, startedAt);
    }

    sigmaRenderer.refresh();
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

    return `n:${nodeSignature.join('|')}|e:${edgeSignature.join('|')}`;
};

const destroyRenderer = (): void => {
    clearMountRetry();
    clearProgrammaticHighlightFrame();

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

    renderedGraphSignature.value = null;

    hoveredNodeId = null;
    hoverHighlightedEdgeIds = new Set<string>();
    programmaticEdgeHighlights = new Map<string, number>();
};

const buildSigmaGraph = (): {
    sigmaGraph: Graph;
    edgeIdsByNode: Map<string, Set<string>>;
    nodeById: Map<string, BaseNode>;
} => {
    const nodes = buildNodes(props.graph);
    const edges = buildEdges(props.graph, nodes);
    const positionedNodes = buildLayoutNodes(nodes, edges);
    const sigmaGraph = new Graph({
        type: 'directed',
        multi: false,
        allowSelfLoops: false,
    });
    const edgeIdsByNode = new Map<string, Set<string>>();
    const nodeById = new Map(nodes.map((node) => [node.id, node]));

    for (const node of positionedNodes) {
        sigmaGraph.addNode(node.id, {
            x: node.x,
            y: node.y,
            size: 10,
            color: nodeColor(node.type),
            label: node.shortLabel,
            labelColor: '#0f172a',
        });
    }

    for (const edge of edges) {
        if (!sigmaGraph.hasNode(edge.from) || !sigmaGraph.hasNode(edge.to)) {
            continue;
        }

        sigmaGraph.addEdgeWithKey(edge.id, edge.from, edge.to, {
            type: 'arrow',
            size: 2,
            color: edgeColor(edge.tone),
        });

        const fromEdgeIds = edgeIdsByNode.get(edge.from) ?? new Set<string>();
        fromEdgeIds.add(edge.id);
        edgeIdsByNode.set(edge.from, fromEdgeIds);

        const toEdgeIds = edgeIdsByNode.get(edge.to) ?? new Set<string>();
        toEdgeIds.add(edge.id);
        edgeIdsByNode.set(edge.to, toEdgeIds);
    }

    return { sigmaGraph, edgeIdsByNode, nodeById };
};

const mountRenderer = (cameraState?: CameraStateSnapshot | null): void => {
    if (!hasRenderableGraph.value) {
        destroyRenderer();
        emit('zoom-change', 100);
        return;
    }

    const container = containerRef.value;
    if (!container) {
        return;
    }

    const rect = container.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
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

    const { sigmaGraph, edgeIdsByNode, nodeById } = buildSigmaGraph();
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
        edgeReducer: (edge, data) => {
            const programmaticHighlightStrength =
                getProgrammaticHighlightStrength(edge);

            return resolveEdgeHighlightAttributes({
                edgeId: edge,
                baseColor:
                    typeof data.color === 'string'
                        ? data.color
                        : edgeColor('other'),
                baseSize: typeof data.size === 'number' ? data.size : 2,
                hoveredNodeId,
                hoverHighlightedEdgeIds,
                programmaticHighlightStrength,
            });
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

    const camera = sigmaRenderer.getCamera();
    const onCameraUpdate = (): void => {
        emit('zoom-change', toZoomPercent(camera.getState().ratio));
    };

    camera.on('updated', onCameraUpdate);
    releaseCameraListener = () => {
        camera.off('updated', onCameraUpdate);
    };

    const onEnterNode = ({ node }: { node: string }): void => {
        hoveredNodeId = node;
        hoverHighlightedEdgeIds = new Set(edgeIdsByNode.get(node) ?? []);
        sigmaRenderer?.refresh();
    };

    const onLeaveNode = (): void => {
        if (!hoveredNodeId) {
            return;
        }

        hoveredNodeId = null;
        hoverHighlightedEdgeIds = new Set<string>();
        sigmaRenderer?.refresh();
    };

    const onClickNode = ({ node }: { node: string }): void => {
        const selectedNode = nodeById.get(node);
        if (!selectedNode) {
            return;
        }

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
};

const withCamera = (callback: (ratio: number) => void): void => {
    const camera = sigmaRenderer?.getCamera();
    if (!camera) {
        return;
    }

    callback(camera.getState().ratio);
};

const zoomIn = (): void => {
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
    sigmaRenderer?.getCamera().animate(DEFAULT_CAMERA_STATE, { duration: 180 });
};

defineExpose({
    zoomIn,
    zoomOut,
    resetView,
    highlightDispatchPath,
});

watch(
    () => props.graph,
    async () => {
        pendingDispatchHighlight = null;

        if (!hasRenderableGraph.value) {
            destroyRenderer();
            emit('zoom-change', 100);
            return;
        }

        if (!hasBeenVisible.value) {
            return;
        }

        const nextGraphSignature = buildGraphSignature(props.graph);
        if (nextGraphSignature === renderedGraphSignature.value) {
            return;
        }

        const currentCameraState = sigmaRenderer
            ? { ...sigmaRenderer.getCamera().getState() }
            : null;

        await nextTick();
        mountRenderer(currentCameraState);
    },
    { deep: true, immediate: false },
);

onMounted(() => {
    const container = containerRef.value;
    if (!container) {
        return;
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

    destroyRenderer();
});
</script>

<template>
    <div ref="containerRef" class="h-full w-full" />
</template>
