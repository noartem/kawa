<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import LanguageTabs from '@/components/LanguageTabs.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

interface Props {
    user: User;
}

const handleLogout = () => {
    router.flushAll();
};

const { t } = useI18n();

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup class="grid gap-0.75">
        <div class="grid gap-0.5 px-1">
            <p class="text-xs font-medium text-muted-foreground">
                {{ t('settings.appearance.title') }}
            </p>
            <AppearanceTabs />
        </div>

        <div class="grid gap-0.5 px-1">
            <p class="text-xs font-medium text-muted-foreground">
                {{ t('settings.language.title') }}
            </p>
            <LanguageTabs />
        </div>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="edit()" prefetch as="button">
                <Settings class="mr-2 h-4 w-4" />
                {{ t('nav.settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('auth.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
