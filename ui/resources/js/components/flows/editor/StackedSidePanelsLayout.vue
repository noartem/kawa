<script setup lang="ts">
import { cn } from '@/lib/utils';
import {
    createDefaultStackedSidePanelsResizeState,
    DEFAULT_STACKED_SIDE_PANELS_BOTTOM_RATIO,
    DEFAULT_STACKED_SIDE_PANELS_MAIN_RATIO,
    DEFAULT_STACKED_SIDE_PANELS_SIDE_RATIO,
    DEFAULT_STACKED_SIDE_PANELS_TOP_RATIO,
    normalizeStackedSidePanelsResizeState,
    resolveStackedSidePanelsLayout,
    resolveStackedSidePanelsState,
    type StackedSidePanelsState,
    type StackedSidePanelsResizeState,
} from './stackedSidePanelsLayout';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

const props = withDefaults(
    defineProps<{
        topActive: boolean;
        bottomActive: boolean;
        mainRatio?: number;
        sideRatio?: number;
        topRatio?: number;
        bottomRatio?: number;
        resizable?: boolean;
        resizeState?: StackedSidePanelsResizeState;
    }>(),
    {
        mainRatio: DEFAULT_STACKED_SIDE_PANELS_MAIN_RATIO,
        sideRatio: DEFAULT_STACKED_SIDE_PANELS_SIDE_RATIO,
        topRatio: DEFAULT_STACKED_SIDE_PANELS_TOP_RATIO,
        bottomRatio: DEFAULT_STACKED_SIDE_PANELS_BOTTOM_RATIO,
        resizable: false,
        resizeState: createDefaultStackedSidePanelsResizeState,
    },
);

const emit = defineEmits<{
    'update:resizeState': [resizeState: StackedSidePanelsResizeState];
}>();

defineSlots<{
    main: () => unknown;
    top: () => unknown;
    bottom: () => unknown;
}>();

const rootElement = ref<HTMLElement | null>(null);
const containerSize = ref({
    width: 0,
    height: 0,
});
const isDesktop = ref(false);
const dragAxis = ref<'horizontal' | 'vertical' | null>(null);
const draftResizeState = ref(
    normalizeStackedSidePanelsResizeState(props.resizeState),
);

let resizeObserver: ResizeObserver | null = null;
let mediaQuery: MediaQueryList | null = null;
let activePointerTarget: HTMLElement | null = null;
let activePointerId: number | null = null;
let cleanupDragStyles: (() => void) | null = null;
let removeMediaQueryListener: (() => void) | null = null;

const clamp = (value: number, min: number, max: number): number => {
    return Math.min(Math.max(value, min), max);
};

const syncContainerSize = (): void => {
    const element = rootElement.value;

    if (!element) {
        containerSize.value = {
            width: 0,
            height: 0,
        };
        return;
    }

    const rect = element.getBoundingClientRect();

    containerSize.value = {
        width: rect.width,
        height: rect.height,
    };
};

const syncDesktopState = (nextMatches: boolean): void => {
    isDesktop.value = nextMatches;
};

const syncDraftResizeState = (
    nextState: Partial<StackedSidePanelsResizeState> | null | undefined,
): void => {
    if (dragAxis.value !== null) {
        return;
    }

    draftResizeState.value = normalizeStackedSidePanelsResizeState(nextState);
};

const updateResizeState = (
    nextState: Partial<StackedSidePanelsResizeState>,
): void => {
    const normalizedState = normalizeStackedSidePanelsResizeState(nextState);

    draftResizeState.value = normalizedState;
    emit('update:resizeState', normalizedState);
};

const beginDragging = (
    event: PointerEvent,
    axis: 'horizontal' | 'vertical',
    cursor: 'col-resize' | 'row-resize',
): void => {
    if (event.button !== 0) {
        return;
    }

    const target = event.currentTarget;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    dragAxis.value = axis;
    activePointerTarget = target;
    activePointerId = event.pointerId;
    target.setPointerCapture(event.pointerId);

    const { body } = document;
    const previousCursor = body.style.cursor;
    const previousUserSelect = body.style.userSelect;

    body.style.cursor = cursor;
    body.style.userSelect = 'none';

    cleanupDragStyles = () => {
        body.style.cursor = previousCursor;
        body.style.userSelect = previousUserSelect;
    };

    event.preventDefault();
};

const stopDragging = (): void => {
    if (
        activePointerTarget !== null &&
        activePointerId !== null &&
        activePointerTarget.hasPointerCapture(activePointerId)
    ) {
        activePointerTarget.releasePointerCapture(activePointerId);
    }

    dragAxis.value = null;
    activePointerTarget = null;
    activePointerId = null;
    cleanupDragStyles?.();
    cleanupDragStyles = null;
};

const activeState = computed<StackedSidePanelsState>(() => {
    return resolveStackedSidePanelsState(props.topActive, props.bottomActive);
});

const lastVisibleState = ref<Exclude<StackedSidePanelsState, 'none'>>(
    activeState.value === 'none' ? 'both' : activeState.value,
);

watch(
    activeState,
    (nextState) => {
        if (nextState !== 'none') {
            lastVisibleState.value = nextState;
        }
    },
    { immediate: true },
);

watch(
    () => props.resizeState,
    (nextState) => {
        syncDraftResizeState(nextState);
    },
    { deep: true, immediate: true },
);

const layout = computed(() => {
    return resolveStackedSidePanelsLayout({
        activeState: activeState.value,
        fallbackState: lastVisibleState.value,
        mainRatio: props.mainRatio,
        sideRatio: props.sideRatio,
        topRatio: props.topRatio,
        bottomRatio: props.bottomRatio,
        resizeState: draftResizeState.value,
        containerWidthPx: containerSize.value.width,
        containerHeightPx: containerSize.value.height,
        allowPixelResize: props.resizable && isDesktop.value,
    });
});

const startHorizontalResize = (event: PointerEvent): void => {
    if (!props.resizable || !layout.value.canResizeHorizontally) {
        return;
    }

    beginDragging(event, 'horizontal', 'col-resize');
    handleHorizontalResize(event);
};

const handleHorizontalResize = (event: PointerEvent): void => {
    if (
        dragAxis.value !== 'horizontal' ||
        rootElement.value === null ||
        layout.value.shellWidthMinPx === null ||
        layout.value.shellWidthMaxPx === null
    ) {
        return;
    }

    const rect = rootElement.value.getBoundingClientRect();
    const shellWidthPx = clamp(
        rect.right - event.clientX,
        layout.value.shellWidthMinPx,
        layout.value.shellWidthMaxPx,
    );

    updateResizeState({
        ...draftResizeState.value,
        shellWidthPx,
    });

    event.preventDefault();
};

const startVerticalResize = (event: PointerEvent): void => {
    if (!props.resizable || !layout.value.canResizeVertically) {
        return;
    }

    beginDragging(event, 'vertical', 'row-resize');
    handleVerticalResize(event);
};

const handleVerticalResize = (event: PointerEvent): void => {
    if (
        dragAxis.value !== 'vertical' ||
        rootElement.value === null ||
        layout.value.topHeightMinPx === null ||
        layout.value.topHeightMaxPx === null
    ) {
        return;
    }

    const rect = rootElement.value.getBoundingClientRect();
    const topHeightPx = clamp(
        event.clientY - rect.top,
        layout.value.topHeightMinPx,
        layout.value.topHeightMaxPx,
    );

    updateResizeState({
        ...draftResizeState.value,
        topHeightPx,
    });

    event.preventDefault();
};

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    mediaQuery = window.matchMedia('(min-width: 768px)');
    syncDesktopState(mediaQuery.matches);
    syncContainerSize();

    const handleMediaQueryChange = (event: MediaQueryListEvent): void => {
        syncDesktopState(event.matches);
    };

    mediaQuery.addEventListener('change', handleMediaQueryChange);
    removeMediaQueryListener = () => {
        mediaQuery?.removeEventListener('change', handleMediaQueryChange);
    };

    resizeObserver = new ResizeObserver(() => {
        syncContainerSize();
    });

    if (rootElement.value !== null) {
        resizeObserver.observe(rootElement.value);
    }
});

onBeforeUnmount(() => {
    removeMediaQueryListener?.();
    removeMediaQueryListener = null;
    resizeObserver?.disconnect();
    resizeObserver = null;
    stopDragging();
});
</script>

<template>
    <div
        ref="rootElement"
        class="relative flex h-full min-h-0 flex-col overflow-hidden md:block"
        :style="{
            '--stacked-main-width': layout.mainWidth,
            '--stacked-shell-width': layout.shellWidth,
            '--stacked-top-track-size': layout.topTrackSize,
            '--stacked-mobile-shell-height': layout.mobileShellHeight,
            '--stacked-mobile-shell-opacity': layout.hasSidePanels ? '1' : '0',
            '--stacked-mobile-shell-translate-y': layout.hasSidePanels
                ? '0rem'
                : '1rem',
        }"
    >
        <div
            class="min-h-0 flex-1 overflow-hidden md:absolute md:inset-y-0 md:left-0 md:w-[var(--stacked-main-width)]"
        >
            <slot name="main" />
        </div>

        <div
            class="min-h-0 shrink-0 overflow-hidden border-t border-border bg-background max-h-[var(--stacked-mobile-shell-height)] [opacity:var(--stacked-mobile-shell-opacity)] [transform:translate3d(0,var(--stacked-mobile-shell-translate-y),0)] md:absolute md:inset-y-0 md:right-0 md:z-10 md:h-full md:w-[var(--stacked-shell-width)] md:max-h-none md:overflow-visible md:border-t-0 md:transform-none"
            :inert="activeState === 'none'"
            :style="{
                pointerEvents: layout.shellPointerEvents,
                opacity: layout.shellDesktopOpacity,
            }"
        >
            <button
                v-if="props.resizable && layout.canResizeHorizontally"
                type="button"
                class="group absolute inset-y-0 left-0 z-20 hidden w-3 -translate-x-1/2 cursor-col-resize touch-none md:block"
                :aria-label="'Resize side panels'"
                @lostpointercapture="stopDragging"
                @pointercancel="stopDragging"
                @pointerdown="startHorizontalResize"
                @pointermove="handleHorizontalResize"
                @pointerup="stopDragging"
            >
                <span
                    :class="
                        cn(
                            'absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-border/80 transition-colors duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]',
                            dragAxis === 'horizontal'
                                ? 'bg-primary'
                                : 'group-hover:bg-primary/60',
                        )
                    "
                />
            </button>

            <div
                class="relative grid h-full min-h-0 border-l border-l-border bg-background"
                :style="{ gridTemplateRows: layout.rowTemplate }"
            >
                <button
                    v-if="props.resizable && layout.canResizeVertically"
                    type="button"
                    class="group absolute inset-x-0 z-20 hidden h-3 -translate-y-1/2 cursor-row-resize touch-none md:block"
                    :style="{ top: 'var(--stacked-top-track-size)' }"
                    :aria-label="'Resize top and bottom panels'"
                    @lostpointercapture="stopDragging"
                    @pointercancel="stopDragging"
                    @pointerdown="startVerticalResize"
                    @pointermove="handleVerticalResize"
                    @pointerup="stopDragging"
                >
                    <span
                        :class="
                            cn(
                                'absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-border/80 transition-colors duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]',
                                dragAxis === 'vertical'
                                    ? 'bg-primary'
                                    : 'group-hover:bg-primary/60',
                            )
                        "
                    />
                </button>

                <div
                    :class="
                        cn(
                            'pointer-events-none absolute inset-x-0 z-10 hidden h-px bg-border/80 md:block',
                            layout.dividerVisible ? 'opacity-100' : 'opacity-0',
                        )
                    "
                    :style="{ top: 'var(--stacked-top-track-size)' }"
                />

                <div
                    class="min-h-0 overflow-hidden"
                    :aria-hidden="!props.topActive"
                    :inert="!props.topActive || activeState === 'none'"
                    :style="{
                        transform: isDesktop
                            ? 'none'
                            : layout.topTransform,
                        opacity: layout.topOpacity,
                        pointerEvents: layout.topPointerEvents,
                    }"
                >
                    <slot name="top" />
                </div>

                <div
                    class="min-h-0 overflow-hidden"
                    :aria-hidden="!props.bottomActive"
                    :inert="!props.bottomActive || activeState === 'none'"
                    :style="{
                        transform: isDesktop
                            ? 'none'
                            : layout.bottomTransform,
                        opacity: layout.bottomOpacity,
                        pointerEvents: layout.bottomPointerEvents,
                    }"
                >
                    <slot name="bottom" />
                </div>
            </div>
        </div>
    </div>
</template>
