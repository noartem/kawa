<script setup lang="ts">
import { cn } from '@/lib/utils';
import {
    AccordionContent,
    type AccordionContentProps,
    useForwardProps,
} from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<
    AccordionContentProps & { class?: HTMLAttributes['class'] }
>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardProps(delegatedProps);
</script>

<template>
    <AccordionContent
        data-slot="accordion-content"
        v-bind="forwarded"
        :class="
            cn(
                'data-[state=closed]:animate-accordion-up data-[state=open]:animate-accordion-down overflow-hidden',
                props.class,
            )
        "
    >
        <slot />
    </AccordionContent>
</template>
