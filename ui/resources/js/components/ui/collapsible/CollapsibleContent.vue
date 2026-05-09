<script setup lang="ts">
import { cn } from '@/lib/utils';
import { collapsibleContentAnimationClass } from './collapsibleContent';
import {
    CollapsibleContent,
    type CollapsibleContentProps,
    useForwardProps,
} from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<
    CollapsibleContentProps & { class?: HTMLAttributes['class'] }
>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardProps(delegatedProps);
</script>

<template>
    <CollapsibleContent
        data-slot="collapsible-content"
        v-bind="forwarded"
        :class="cn(collapsibleContentAnimationClass, props.class)"
    >
        <slot />
    </CollapsibleContent>
</template>
