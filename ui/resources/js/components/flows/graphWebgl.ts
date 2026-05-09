const WEBGL_CONTEXT_OPTIONS = {
    preserveDrawingBuffer: false,
    antialias: false,
} as const;

const WEBGL_CONTEXT_NAMES = ['webgl2', 'webgl', 'experimental-webgl'] as const;

interface WebGLContextProbeTarget {
    getContext: (contextId: string, options?: object) => unknown;
}

export const canCreateWebGLContext = (
    target: WebGLContextProbeTarget | null | undefined,
): boolean => {
    if (!target) {
        return false;
    }

    for (const contextId of WEBGL_CONTEXT_NAMES) {
        try {
            if (target.getContext(contextId, WEBGL_CONTEXT_OPTIONS)) {
                return true;
            }
        } catch {
            continue;
        }
    }

    return false;
};
