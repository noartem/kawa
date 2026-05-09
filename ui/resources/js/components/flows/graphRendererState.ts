export interface RendererResizeState {
    width: number;
    height: number;
    hasBeenVisible: boolean;
    hasActiveRenderer: boolean;
}

export const hasRenderableContainerSize = (
    width: number,
    height: number,
): boolean => {
    return width > 0 && height > 0;
};

export const shouldMountRendererOnResize = ({
    width,
    height,
    hasBeenVisible,
    hasActiveRenderer,
}: RendererResizeState): boolean => {
    return (
        hasBeenVisible &&
        hasRenderableContainerSize(width, height) &&
        !hasActiveRenderer
    );
};
