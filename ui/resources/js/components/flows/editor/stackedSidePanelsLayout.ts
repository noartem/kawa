export type StackedSidePanelsState = 'none' | 'top' | 'bottom' | 'both';

export interface StackedSidePanelsResizeState {
    shellWidthPx: number | null;
    topHeightPx: number | null;
}

export const DEFAULT_STACKED_SIDE_PANELS_MAIN_RATIO = 1.3;
export const DEFAULT_STACKED_SIDE_PANELS_SIDE_RATIO = 1;
export const DEFAULT_STACKED_SIDE_PANELS_TOP_RATIO = 1;
export const DEFAULT_STACKED_SIDE_PANELS_BOTTOM_RATIO = 1;
export const DEFAULT_STACKED_SIDE_PANELS_MIN_MAIN_WIDTH_PX = 320;
export const DEFAULT_STACKED_SIDE_PANELS_MIN_SHELL_WIDTH_PX = 480;
export const DEFAULT_STACKED_SIDE_PANELS_MIN_PANEL_HEIGHT_PX = 160;

const SHELL_WIDTH_QUERY_KEY = 'shellWidth';
const TOP_HEIGHT_QUERY_KEY = 'topHeight';

export interface ResolveStackedSidePanelsLayoutOptions {
    activeState: StackedSidePanelsState;
    fallbackState: Exclude<StackedSidePanelsState, 'none'>;
    mainRatio?: number;
    sideRatio?: number;
    topRatio?: number;
    bottomRatio?: number;
    resizeState?: Partial<StackedSidePanelsResizeState> | null;
    containerWidthPx?: number;
    containerHeightPx?: number;
    allowPixelResize?: boolean;
}

export interface StackedSidePanelsLayoutResult {
    hasSidePanels: boolean;
    visibleState: Exclude<StackedSidePanelsState, 'none'>;
    mainWidth: string;
    shellWidth: string;
    shellDesktopOpacity: string;
    shellTransform: string;
    shellPointerEvents: 'auto' | 'none';
    rowTemplate: string;
    topTransform: string;
    bottomTransform: string;
    topOpacity: string;
    bottomOpacity: string;
    topPointerEvents: 'auto' | 'none';
    bottomPointerEvents: 'auto' | 'none';
    dividerVisible: boolean;
    mobileShellHeight: string;
    topTrackSize: string;
    shellWidthPx: number | null;
    topHeightPx: number | null;
    shellWidthMinPx: number | null;
    shellWidthMaxPx: number | null;
    topHeightMinPx: number | null;
    topHeightMaxPx: number | null;
    canResizeHorizontally: boolean;
    canResizeVertically: boolean;
}

const normalizeRatio = (value: number | undefined, fallback: number): number => {
    if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
        return fallback;
    }

    return value;
};

const toPercent = (value: number): string => {
    return `${value.toFixed(4)}%`;
};

const normalizePixelValue = (value: number | null | undefined): number | null => {
    if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
        return null;
    }

    return Math.round(value);
};

const normalizeContainerDimension = (
    value: number | undefined,
): number | null => {
    if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
        return null;
    }

    return value;
};

const clamp = (value: number, min: number, max: number): number => {
    return Math.min(Math.max(value, min), max);
};

const resolveRatioShare = (numerator: number, denominator: number): number => {
    return denominator > 0 ? numerator / denominator : 0.5;
};

const parsePixelQueryValue = (value: string | null): number | null => {
    if (value === null) {
        return null;
    }

    const parsed = Number.parseFloat(value);

    return normalizePixelValue(parsed);
};

const resolveHorizontalBounds = (
    containerWidthPx: number | null,
): { min: number; max: number } | null => {
    if (containerWidthPx === null) {
        return null;
    }

    const min = DEFAULT_STACKED_SIDE_PANELS_MIN_SHELL_WIDTH_PX;
    const max = Math.max(min, containerWidthPx - DEFAULT_STACKED_SIDE_PANELS_MIN_MAIN_WIDTH_PX);

    return { min, max };
};

const resolveVerticalBounds = (
    containerHeightPx: number | null,
): { min: number; max: number } | null => {
    if (containerHeightPx === null) {
        return null;
    }

    const min = DEFAULT_STACKED_SIDE_PANELS_MIN_PANEL_HEIGHT_PX;
    const max = Math.max(min, containerHeightPx - DEFAULT_STACKED_SIDE_PANELS_MIN_PANEL_HEIGHT_PX);

    return { min, max };
};

export const createDefaultStackedSidePanelsResizeState = (): StackedSidePanelsResizeState => {
    return {
        shellWidthPx: null,
        topHeightPx: null,
    };
};

export const normalizeStackedSidePanelsResizeState = (
    resizeState?: Partial<StackedSidePanelsResizeState> | null,
): StackedSidePanelsResizeState => {
    return {
        shellWidthPx: normalizePixelValue(resizeState?.shellWidthPx),
        topHeightPx: normalizePixelValue(resizeState?.topHeightPx),
    };
};

export const stackedSidePanelsResizeStatesEqual = (
    left: Partial<StackedSidePanelsResizeState> | null | undefined,
    right: Partial<StackedSidePanelsResizeState> | null | undefined,
): boolean => {
    const normalizedLeft = normalizeStackedSidePanelsResizeState(left);
    const normalizedRight = normalizeStackedSidePanelsResizeState(right);

    return (
        normalizedLeft.shellWidthPx === normalizedRight.shellWidthPx &&
        normalizedLeft.topHeightPx === normalizedRight.topHeightPx
    );
};

export const readStackedSidePanelsResizeState = (
    query: URLSearchParams,
): StackedSidePanelsResizeState => {
    return {
        shellWidthPx: parsePixelQueryValue(query.get(SHELL_WIDTH_QUERY_KEY)),
        topHeightPx: parsePixelQueryValue(query.get(TOP_HEIGHT_QUERY_KEY)),
    };
};

export const setStackedSidePanelsResizeQueryParams = (
    query: URLSearchParams,
    resizeState?: Partial<StackedSidePanelsResizeState> | null,
): void => {
    const normalized = normalizeStackedSidePanelsResizeState(resizeState);

    if (normalized.shellWidthPx === null) {
        query.delete(SHELL_WIDTH_QUERY_KEY);
    } else {
        query.set(SHELL_WIDTH_QUERY_KEY, String(normalized.shellWidthPx));
    }

    if (normalized.topHeightPx === null) {
        query.delete(TOP_HEIGHT_QUERY_KEY);
    } else {
        query.set(TOP_HEIGHT_QUERY_KEY, String(normalized.topHeightPx));
    }
};

const resolveRowTemplate = (
    visibleState: Exclude<StackedSidePanelsState, 'none'>,
    topRatio: number,
    bottomRatio: number,
): string => {
    if (visibleState === 'top') {
        return 'minmax(0, 1fr) minmax(0, 0fr)';
    }

    if (visibleState === 'bottom') {
        return 'minmax(0, 0fr) minmax(0, 1fr)';
    }

    return `minmax(0, ${topRatio}fr) minmax(0, ${bottomRatio}fr)`;
};

const resolveMobileShellHeight = (
    visibleState: Exclude<StackedSidePanelsState, 'none'>,
): string => {
    return visibleState === 'both'
        ? 'clamp(20rem, 42vh, 28rem)'
        : 'clamp(16rem, 34vh, 24rem)';
};

export const resolveStackedSidePanelsState = (
    topActive: boolean,
    bottomActive: boolean,
): StackedSidePanelsState => {
    if (topActive && bottomActive) {
        return 'both';
    }

    if (topActive) {
        return 'top';
    }

    if (bottomActive) {
        return 'bottom';
    }

    return 'none';
};

export const shouldAnimateStackedSidePanelsInternals = (
    previousState: StackedSidePanelsState,
    nextState: StackedSidePanelsState,
): boolean => {
    return previousState !== 'none' && nextState !== 'none';
};

export const shouldKeepStackedSidePanelsDividerVisible = (
    previousState: StackedSidePanelsState,
    nextState: StackedSidePanelsState,
): boolean => {
    return (
        previousState !== nextState &&
        previousState !== 'none' &&
        nextState !== 'none' &&
        (previousState === 'both' || nextState === 'both')
    );
};

export const resolveStackedSidePanelsLayout = ({
    activeState,
    fallbackState,
    mainRatio = DEFAULT_STACKED_SIDE_PANELS_MAIN_RATIO,
    sideRatio = DEFAULT_STACKED_SIDE_PANELS_SIDE_RATIO,
    topRatio = DEFAULT_STACKED_SIDE_PANELS_TOP_RATIO,
    bottomRatio = DEFAULT_STACKED_SIDE_PANELS_BOTTOM_RATIO,
    resizeState,
    containerWidthPx,
    containerHeightPx,
    allowPixelResize = false,
}: ResolveStackedSidePanelsLayoutOptions): StackedSidePanelsLayoutResult => {
    const normalizedMainRatio = normalizeRatio(
        mainRatio,
        DEFAULT_STACKED_SIDE_PANELS_MAIN_RATIO,
    );
    const normalizedSideRatio = normalizeRatio(
        sideRatio,
        DEFAULT_STACKED_SIDE_PANELS_SIDE_RATIO,
    );
    const normalizedTopRatio = normalizeRatio(
        topRatio,
        DEFAULT_STACKED_SIDE_PANELS_TOP_RATIO,
    );
    const normalizedBottomRatio = normalizeRatio(
        bottomRatio,
        DEFAULT_STACKED_SIDE_PANELS_BOTTOM_RATIO,
    );
    const normalizedResizeState = normalizeStackedSidePanelsResizeState(
        resizeState,
    );
    const normalizedContainerWidth = normalizeContainerDimension(containerWidthPx);
    const normalizedContainerHeight = normalizeContainerDimension(
        containerHeightPx,
    );
    const totalHorizontalRatio = normalizedMainRatio + normalizedSideRatio;
    const totalVerticalRatio = normalizedTopRatio + normalizedBottomRatio;
    const visibleState = activeState === 'none' ? fallbackState : activeState;
    const horizontalBounds = resolveHorizontalBounds(normalizedContainerWidth);
    const verticalBounds = resolveVerticalBounds(normalizedContainerHeight);
    const canUseHorizontalPixels =
        allowPixelResize &&
        activeState !== 'none' &&
        horizontalBounds !== null &&
        normalizedContainerWidth !== null;
    const canUseVerticalPixels =
        allowPixelResize &&
        activeState !== 'none' &&
        visibleState === 'both' &&
        verticalBounds !== null &&
        normalizedContainerHeight !== null;
    const resolvedShellWidthPx =
        canUseHorizontalPixels && horizontalBounds !== null && normalizedContainerWidth !== null
            ? clamp(
                  normalizedResizeState.shellWidthPx ??
                      normalizedContainerWidth *
                          resolveRatioShare(
                              normalizedSideRatio,
                              totalHorizontalRatio,
                          ),
                  horizontalBounds.min,
                  horizontalBounds.max,
              )
            : null;
    const resolvedTopHeightPx =
        canUseVerticalPixels && verticalBounds !== null && normalizedContainerHeight !== null
            ? clamp(
                  normalizedResizeState.topHeightPx ??
                      normalizedContainerHeight *
                          resolveRatioShare(
                              normalizedTopRatio,
                              totalVerticalRatio,
                          ),
                  verticalBounds.min,
                  verticalBounds.max,
              )
            : null;
    const useManualShellWidthPx =
        canUseHorizontalPixels && normalizedResizeState.shellWidthPx !== null;
    const useManualTopHeightPx =
        canUseVerticalPixels && normalizedResizeState.topHeightPx !== null;
    const horizontalRatioShare = toPercent(
        resolveRatioShare(normalizedMainRatio, totalHorizontalRatio) * 100,
    );
    const sideRatioShare = toPercent(
        resolveRatioShare(normalizedSideRatio, totalHorizontalRatio) * 100,
    );
    const topRatioShare = toPercent(
        resolveRatioShare(normalizedTopRatio, totalVerticalRatio) * 100,
    );
    const mainWidth =
        activeState === 'none'
            ? '100%'
            : useManualShellWidthPx && resolvedShellWidthPx !== null
              ? `calc(100% - ${resolvedShellWidthPx}px)`
              : horizontalRatioShare;
    const shellWidth =
        activeState === 'none'
            ? '0px'
            : useManualShellWidthPx && resolvedShellWidthPx !== null
            ? `${resolvedShellWidthPx}px`
            : sideRatioShare;
    const topTrackSize =
        visibleState === 'top'
            ? '100%'
            : visibleState === 'bottom'
              ? '0px'
              : useManualTopHeightPx && resolvedTopHeightPx !== null
                ? `${resolvedTopHeightPx}px`
                : topRatioShare;
    const rowTemplate =
        visibleState === 'top'
            ? 'minmax(0, 1fr) minmax(0, 0fr)'
            : visibleState === 'bottom'
              ? 'minmax(0, 0fr) minmax(0, 1fr)'
              : useManualTopHeightPx && resolvedTopHeightPx !== null
                ? `${resolvedTopHeightPx}px minmax(0, 1fr)`
                : resolveRowTemplate(
                      visibleState,
                      normalizedTopRatio,
                      normalizedBottomRatio,
                  );

    return {
        hasSidePanels: activeState !== 'none',
        visibleState,
        mainWidth,
        shellWidth,
        shellDesktopOpacity: activeState === 'none' ? '0' : '1',
        shellTransform:
            activeState === 'none' ? 'translate3d(100%, 0, 0)' : 'translate3d(0, 0, 0)',
        shellPointerEvents: activeState === 'none' ? 'none' : 'auto',
        rowTemplate,
        topTransform:
            activeState === 'bottom' ? 'translate3d(0, -100%, 0)' : 'translate3d(0, 0, 0)',
        bottomTransform:
            activeState === 'top' ? 'translate3d(0, 100%, 0)' : 'translate3d(0, 0, 0)',
        topOpacity: activeState === 'bottom' ? '0' : '1',
        bottomOpacity: activeState === 'top' ? '0' : '1',
        topPointerEvents:
            activeState === 'top' || activeState === 'both' ? 'auto' : 'none',
        bottomPointerEvents:
            activeState === 'bottom' || activeState === 'both'
                ? 'auto'
                : 'none',
        dividerVisible: visibleState === 'both',
        mobileShellHeight:
            activeState === 'none'
                ? '0px'
                : resolveMobileShellHeight(visibleState),
        topTrackSize,
        shellWidthPx: resolvedShellWidthPx,
        topHeightPx: resolvedTopHeightPx,
        shellWidthMinPx: horizontalBounds?.min ?? null,
        shellWidthMaxPx: horizontalBounds?.max ?? null,
        topHeightMinPx: verticalBounds?.min ?? null,
        topHeightMaxPx: verticalBounds?.max ?? null,
        canResizeHorizontally: activeState !== 'none' && canUseHorizontalPixels,
        canResizeVertically:
            activeState !== 'none' && visibleState === 'both' && canUseVerticalPixels,
    };
};
