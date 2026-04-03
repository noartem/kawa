<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        showFluidSmoke?: boolean;
    }>(),
    {
        showFluidSmoke: false,
    },
);
</script>

<template>
    <AuthLayout
        :title="title"
        :description="description"
        :show-fluid-smoke="showFluidSmoke"
    >
        <slot />
    </AuthLayout>
</template>
