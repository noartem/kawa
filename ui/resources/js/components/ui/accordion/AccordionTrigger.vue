<script setup lang="ts">
import { AccordionTrigger, type AccordionTriggerProps } from 'reka-ui';

const props = defineProps<AccordionTriggerProps>();
</script>

<template>
    <AccordionTrigger data-slot="accordion-trigger" v-bind="props">
        <slot />
    </AccordionTrigger>
</template>
