<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import FluidSmokeBackground from '@/components/FluidSmokeBackground.vue';
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

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
    <div
        class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-x-hidden bg-background p-6 md:p-10"
    >
        <template v-if="showFluidSmoke">
            <FluidSmokeBackground />

            <div
                class="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.18),transparent_32%),linear-gradient(to_bottom,rgba(248,244,237,0.08),rgba(248,244,237,0.8))] dark:bg-[radial-gradient(circle_at_top,rgba(196,163,90,0.12),transparent_28%),linear-gradient(to_bottom,rgba(5,6,10,0.36),rgba(5,6,10,0.86))]"
            />
        </template>

        <div class="relative z-10 w-full max-w-sm">
            <div
                :class="[
                    'flex flex-col gap-8',
                    showFluidSmoke
                        ? 'rounded-[2rem] border border-black/10 bg-white/72 p-8 shadow-[0_24px_90px_rgba(17,17,17,0.14)] backdrop-blur-xl dark:border-white/10 dark:bg-black/38 dark:shadow-[0_24px_90px_rgba(0,0,0,0.38)]'
                        : '',
                ]"
            >
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                        >
                            <AppLogoIcon
                                class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                            />
                        </div>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
