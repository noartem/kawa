<script setup lang="ts">
import FluidSmokeBackground from '@/components/FluidSmokeBackground.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const { t } = useI18n();
</script>

<template>
    <Head :title="t('nav.welcome')" />

    <main
        class="relative min-h-screen overflow-x-hidden bg-background text-foreground"
    >
        <FluidSmokeBackground />

        <div
            class="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.18),transparent_32%),linear-gradient(to_bottom,rgba(248,244,237,0.08),rgba(248,244,237,0.74))] dark:bg-[radial-gradient(circle_at_top,rgba(196,163,90,0.12),transparent_28%),linear-gradient(to_bottom,rgba(5,6,10,0.34),rgba(5,6,10,0.82))]"
        />

        <section
            class="relative z-10 flex flex-col min-h-screen items-center justify-center px-6 py-16"
        >
        <h1
                            class="text-5xl mb-4 font-semibold tracking-[-0.06em] text-balance sm:text-7xl"
                        >
                            {{ t('app.name') }}
                        </h1>

                        <div class="flex gap-2">

                        <Button
                            as-child
                            size="lg"
                            class="min-w-40"
                        >
                            <Link :href="login()">{{
                                t('auth.login.short')
                            }}</Link>
                        </Button>

                        <Button
                            v-if="canRegister"
                            as-child
                            size="lg"
                            variant="outline"
                            class="min-w-40 border-black/15 bg-white/40 backdrop-blur sm:bg-white/52 dark:border-white/15 dark:bg-white/8"
                        >
                            <Link :href="register()">{{
                                t('auth.register.short')
                            }}</Link>
                        </Button>
                        </div>
        </section>
    </main>
</template>
