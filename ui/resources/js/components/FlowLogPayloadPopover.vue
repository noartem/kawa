<script setup lang="ts">
import FlowLogPayloadViewer from '@/components/FlowLogPayloadViewer.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { PopoverAnchor } from 'reka-ui';
import { onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    payload: unknown;
}>();

const { t } = useI18n();

const isOpen = ref(false);
const isPinned = ref(false);

let closeTimeout: ReturnType<typeof setTimeout> | null = null;

const clearCloseTimeout = (): void => {
    if (closeTimeout === null) {
        return;
    }

    clearTimeout(closeTimeout);
    closeTimeout = null;
};

const openPopover = (): void => {
    clearCloseTimeout();
    isOpen.value = true;
};

const scheduleClose = (): void => {
    clearCloseTimeout();

    if (isPinned.value) {
        return;
    }

    closeTimeout = setTimeout(() => {
        if (!isPinned.value) {
            isOpen.value = false;
        }
    }, 120);
};

const togglePinned = (event: MouseEvent): void => {
    event.stopPropagation();
    clearCloseTimeout();

    if (isPinned.value) {
        isPinned.value = false;
        isOpen.value = false;

        return;
    }

    isPinned.value = true;
    isOpen.value = true;
};

const handleOpenChange = (nextOpen: boolean): void => {
    clearCloseTimeout();

    if (!nextOpen) {
        isPinned.value = false;
        isOpen.value = false;

        return;
    }

    isOpen.value = true;
};

onBeforeUnmount(() => {
    clearCloseTimeout();
});
</script>

<template>
    <Popover :open="isOpen" @update:open="handleOpenChange">
        <PopoverAnchor as-child>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="ml-1 inline-flex h-5 items-center rounded-md border border-border/70 bg-background/80 px-1.5 align-baseline text-[10px] font-medium tracking-wide text-muted-foreground transition hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:outline-none"
                @pointerenter="openPopover"
                @pointerleave="scheduleClose"
                @focus="openPopover"
                @blur="scheduleClose"
                @click="togglePinned"
            >
                {{ t('flows.logs.payload.json_label') }}
            </Button>
        </PopoverAnchor>

        <PopoverContent
            side="bottom"
            align="start"
            class="w-[min(90vw,42rem)] border-border/70 bg-popover p-0 shadow-xl"
            @pointerenter="openPopover"
            @pointerleave="scheduleClose"
        >
            <FlowLogPayloadViewer :payload="props.payload" />
        </PopoverContent>
    </Popover>
</template>
