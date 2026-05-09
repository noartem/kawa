import { PROGRAMMATIC_HIGHLIGHT_COLOR } from './graphHighlights.ts';

export interface SvgPoint {
    x: number;
    y: number;
}

export interface SvgLine {
    x1: number;
    y1: number;
    x2: number;
    y2: number;
}

export interface SvgLabelFrame {
    x: number;
    y: number;
    width: number;
    height: number;
    textY: number;
}

export interface SvgViewport {
    x: number;
    y: number;
    width: number;
    height: number;
}

export interface SvgBounds {
    minX: number;
    minY: number;
    maxX: number;
    maxY: number;
}

export type SvgWheelZoomMode = 'pinch' | 'wheel';

const SVG_WHEEL_LINE_DELTA_PIXELS = 16;
const SVG_WHEEL_PAGE_DELTA_PIXELS = 120;

export const SVG_VIEWBOX_WIDTH = 1000;
export const SVG_VIEWBOX_HEIGHT = 700;
export const SVG_NODE_RADIUS = 16;
export const MIN_SVG_ZOOM = 0.5;
export const MAX_SVG_ZOOM = 2.5;
const LABEL_HEIGHT = 28;
const LABEL_OFFSET = 18;
const PINCH_WHEEL_ZOOM_SENSITIVITY = 0.0085;
const MOUSE_WHEEL_ZOOM_SENSITIVITY = 0.0028;
const PINCH_WHEEL_MIN_SCALE = 0.4;
const PINCH_WHEEL_MAX_SCALE = 2.4;
const MOUSE_WHEEL_MIN_SCALE = 0.72;
const MOUSE_WHEEL_MAX_SCALE = 1.38;

interface ZoomSvgViewportOptions {
    anchor?: SvgPoint;
    baseViewport?: SvgViewport;
}

const clamp = (value: number, min: number, max: number): number => {
    return Math.min(max, Math.max(min, value));
};

export const createDefaultSvgViewport = (): SvgViewport => {
    return {
        x: 0,
        y: 0,
        width: SVG_VIEWBOX_WIDTH,
        height: SVG_VIEWBOX_HEIGHT,
    };
};

export const resolveSvgViewportFromBounds = (
    bounds: SvgBounds,
    padding: number = 72,
    aspectRatio: number = SVG_VIEWBOX_WIDTH / SVG_VIEWBOX_HEIGHT,
): SvgViewport => {
    if (
        !Number.isFinite(bounds.minX) ||
        !Number.isFinite(bounds.minY) ||
        !Number.isFinite(bounds.maxX) ||
        !Number.isFinite(bounds.maxY)
    ) {
        return createDefaultSvgViewport();
    }

    const paddedWidth = Math.max(1, bounds.maxX - bounds.minX + padding * 2);
    const paddedHeight = Math.max(1, bounds.maxY - bounds.minY + padding * 2);
    let width = paddedWidth;
    let height = paddedHeight;

    if (width / height > aspectRatio) {
        height = width / aspectRatio;
    } else {
        width = height * aspectRatio;
    }

    const centerX = (bounds.minX + bounds.maxX) / 2;
    const centerY = (bounds.minY + bounds.maxY) / 2;

    return {
        x: centerX - width / 2,
        y: centerY - height / 2,
        width,
        height,
    };
};

export const expandSvgBounds = (
    bounds: SvgBounds,
    margin: number,
): SvgBounds => {
    return {
        minX: bounds.minX - margin,
        minY: bounds.minY - margin,
        maxX: bounds.maxX + margin,
        maxY: bounds.maxY + margin,
    };
};

export const resolveSvgViewportZoomPercent = (
    viewport: SvgViewport,
    baseViewport: SvgViewport = createDefaultSvgViewport(),
): number => {
    return Math.round((baseViewport.width / viewport.width) * 100);
};

export const scaleSvgViewport = (
    viewport: SvgViewport,
    scale: number,
    options: ZoomSvgViewportOptions = {},
): SvgViewport => {
    const baseViewport = options.baseViewport ?? createDefaultSvgViewport();
    const currentZoom = baseViewport.width / viewport.width;
    const nextZoom = clamp(currentZoom * scale, MIN_SVG_ZOOM, MAX_SVG_ZOOM);
    const nextWidth = baseViewport.width / nextZoom;
    const nextHeight = baseViewport.height / nextZoom;
    const anchor =
        options.anchor ?? {
            x: viewport.x + viewport.width / 2,
            y: viewport.y + viewport.height / 2,
        };
    const anchorOffsetX = (anchor.x - viewport.x) / viewport.width;
    const anchorOffsetY = (anchor.y - viewport.y) / viewport.height;

    return {
        x: anchor.x - nextWidth * anchorOffsetX,
        y: anchor.y - nextHeight * anchorOffsetY,
        width: nextWidth,
        height: nextHeight,
    };
};

export const resolveSvgWheelZoomScale = (
    pixelDelta: number,
    mode: SvgWheelZoomMode,
): number => {
    const sensitivity =
        mode === 'pinch'
            ? PINCH_WHEEL_ZOOM_SENSITIVITY
            : MOUSE_WHEEL_ZOOM_SENSITIVITY;
    const minScale =
        mode === 'pinch' ? PINCH_WHEEL_MIN_SCALE : MOUSE_WHEEL_MIN_SCALE;
    const maxScale =
        mode === 'pinch' ? PINCH_WHEEL_MAX_SCALE : MOUSE_WHEEL_MAX_SCALE;

    return clamp(Math.exp(-pixelDelta * sensitivity), minScale, maxScale);
};

export const resolveSvgWheelZoomMode = (
    ctrlKey: boolean,
): SvgWheelZoomMode => {
    return ctrlKey ? 'pinch' : 'wheel';
};

export const resolveSvgWheelPixelDelta = (
    deltaY: number,
    deltaMode: number,
): number => {
    if (deltaMode === 1) {
        return deltaY * SVG_WHEEL_LINE_DELTA_PIXELS;
    }

    if (deltaMode === 2) {
        return deltaY * SVG_WHEEL_PAGE_DELTA_PIXELS;
    }

    return deltaY;
};

export const zoomSvgViewport = (
    viewport: SvgViewport,
    direction: 'in' | 'out',
    options: ZoomSvgViewportOptions = {},
): SvgViewport => {
    const factor = direction === 'in' ? 1.2 : 1 / 1.2;

    return scaleSvgViewport(viewport, factor, options);
};

export const panSvgViewport = (
    viewport: SvgViewport,
    deltaX: number,
    deltaY: number,
): SvgViewport => {
    return {
        ...viewport,
        x: viewport.x - deltaX,
        y: viewport.y - deltaY,
    };
};

export const centerSvgViewportOnPoint = (
    viewport: SvgViewport,
    point: SvgPoint,
    baseViewport: SvgViewport = createDefaultSvgViewport(),
): SvgViewport => {
    const minX = baseViewport.x;
    const minY = baseViewport.y;
    const maxX = baseViewport.x + Math.max(baseViewport.width - viewport.width, 0);
    const maxY = baseViewport.y + Math.max(baseViewport.height - viewport.height, 0);

    return {
        ...viewport,
        x: clamp(point.x - viewport.width / 2, minX, maxX),
        y: clamp(point.y - viewport.height / 2, minY, maxY),
    };
};

export const resolveFocusedSvgViewportOnPoint = (
    point: SvgPoint,
    zoomPercent: number,
    baseViewport: SvgViewport = createDefaultSvgViewport(),
): SvgViewport => {
    const zoomFactor = clamp(zoomPercent / 100, MIN_SVG_ZOOM, MAX_SVG_ZOOM);
    const zoomedViewport = {
        x: baseViewport.x,
        y: baseViewport.y,
        width: baseViewport.width / zoomFactor,
        height: baseViewport.height / zoomFactor,
    };

    return centerSvgViewportOnPoint(zoomedViewport, point, baseViewport);
};

export const interpolateSvgViewport = (
    from: SvgViewport,
    to: SvgViewport,
    progress: number,
): SvgViewport => {
    const clampedProgress = clamp(progress, 0, 1);

    return {
        x: from.x + (to.x - from.x) * clampedProgress,
        y: from.y + (to.y - from.y) * clampedProgress,
        width: from.width + (to.width - from.width) * clampedProgress,
        height: from.height + (to.height - from.height) * clampedProgress,
    };
};

export const toSvgPoint = (x: number, y: number): SvgPoint => {
    return {
        x: x * SVG_VIEWBOX_WIDTH,
        y: y * SVG_VIEWBOX_HEIGHT,
    };
};

export const resolveSvgLine = (
    from: SvgPoint,
    to: SvgPoint,
    nodeRadius: number = SVG_NODE_RADIUS,
): SvgLine => {
    const deltaX = to.x - from.x;
    const deltaY = to.y - from.y;
    const distance = Math.hypot(deltaX, deltaY);

    if (distance < 1e-6) {
        return {
            x1: from.x,
            y1: from.y,
            x2: to.x,
            y2: to.y,
        };
    }

    const offsetX = (deltaX / distance) * nodeRadius;
    const offsetY = (deltaY / distance) * nodeRadius;

    return {
        x1: from.x + offsetX,
        y1: from.y + offsetY,
        x2: to.x - offsetX,
        y2: to.y - offsetY,
    };
};

export const estimateSvgLabelFrame = (
    label: string,
    point: SvgPoint,
    nodeRadius: number = SVG_NODE_RADIUS,
): SvgLabelFrame => {
    const width = Math.max(72, label.length * 7 + 18);
    const x = point.x - width / 2;
    const y = point.y - nodeRadius - LABEL_OFFSET - LABEL_HEIGHT;

    return {
        x,
        y,
        width,
        height: LABEL_HEIGHT,
        textY: y + LABEL_HEIGHT / 2 + 0.5,
    };
};

export const resolveFallbackEdgeColor = (
    baseColor: string,
    hoverActive: boolean,
    hoverHighlighted: boolean,
    programmaticHighlightStrength: number,
): string => {
    if (programmaticHighlightStrength > 0) {
        return PROGRAMMATIC_HIGHLIGHT_COLOR;
    }

    if (hoverActive && !hoverHighlighted) {
        return 'rgba(148, 163, 184, 0.18)';
    }

    return baseColor;
};
