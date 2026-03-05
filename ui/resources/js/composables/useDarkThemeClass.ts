import { onBeforeUnmount, onMounted, ref } from 'vue';

const isDarkThemeClass = ref(false);

let activeSubscribers = 0;
let classObserver: MutationObserver | null = null;

const syncDarkClass = (): void => {
    if (typeof document === 'undefined') {
        return;
    }

    isDarkThemeClass.value =
        document.documentElement.classList.contains('dark');
};

const ensureObserver = (): void => {
    if (typeof document === 'undefined' || classObserver !== null) {
        return;
    }

    classObserver = new MutationObserver(() => {
        syncDarkClass();
    });

    classObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
};

const cleanupObserverIfUnused = (): void => {
    if (activeSubscribers > 0 || classObserver === null) {
        return;
    }

    classObserver.disconnect();
    classObserver = null;
};

export const useDarkThemeClass = () => {
    onMounted(() => {
        activeSubscribers += 1;
        syncDarkClass();
        ensureObserver();
    });

    onBeforeUnmount(() => {
        activeSubscribers = Math.max(activeSubscribers - 1, 0);
        cleanupObserverIfUnused();
    });

    return {
        isDarkThemeClass,
    };
};
