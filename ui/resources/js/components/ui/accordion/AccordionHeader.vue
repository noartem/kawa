<script setup lang="ts">
import { AccordionHeader, type AccordionHeaderProps } from 'reka-ui';

const props = defineProps<AccordionHeaderProps>();
</script>

<template>
    <AccordionHeader data-slot="accordion-header" v-bind="props">
        <slot />
    </AccordionHeader>
</template>
