<script setup lang="ts">
import appLogoIcon from '../../images/app-logo.svg';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <img :src="appLogoIcon" alt="" :class="className" v-bind="$attrs" />
</template>
