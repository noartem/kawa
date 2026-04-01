<script setup lang="ts">
import { AccordionContent, type AccordionContentProps } from 'reka-ui';

const props = defineProps<AccordionContentProps>();
</script>

<template>
    <AccordionContent data-slot="accordion-content" v-bind="props">
        <slot />
    </AccordionContent>
</template>
