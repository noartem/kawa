<script setup lang="ts">
import { AccordionItem, type AccordionItemProps, useForwardProps } from 'reka-ui';

const props = defineProps<AccordionItemProps>();

const forwarded = useForwardProps(props);
</script>

<template>
    <AccordionItem v-slot="{ open }" data-slot="accordion-item" v-bind="forwarded">
        <slot :open="open" />
    </AccordionItem>
</template>
