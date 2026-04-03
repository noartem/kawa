<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { Form, Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();
</script>

<template>
    <AuthLayout
        :title="t('auth.verify.title')"
        :description="t('auth.verify.description')"
        show-fluid-smoke
    >
        <Head :title="t('auth.verify.short')" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ t('auth.verify.sent') }}
        </div>

        <Form
            v-bind="send.form()"
            class="space-y-6 text-center"
            v-slot="{ processing }"
        >
            <Button :disabled="processing" variant="secondary">
                <Spinner v-if="processing" />
                {{ t('auth.verify.resend') }}
            </Button>

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                {{ t('auth.logout') }}
            </TextLink>
        </Form>
    </AuthLayout>
</template>
