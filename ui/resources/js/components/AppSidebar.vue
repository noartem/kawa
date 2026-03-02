<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { urlIsActive } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    create as flowCreate,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import { type FlowSidebarItem, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, LayoutGrid, Plus, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from './AppLogo.vue';

const { t } = useI18n();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.dashboard'),
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
        icon: Workflow,
    },
    {
        title: t('nav.new_flow'),
        href: flowCreate().url,
        icon: Plus,
    },
]);

const footerNavItems: NavItem[] = [];

const page = usePage();
const recentFlows = computed<FlowSidebarItem[]>(
    () => (page.props.recentFlows as FlowSidebarItem[] | undefined) ?? [],
);

const statusTone = (status?: string | null) => {
    switch (status) {
        case 'running':
            return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
        case 'error':
            return 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30';
        case 'stopped':
            return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
        default:
            return 'bg-sidebar-accent/50 text-sidebar-foreground/80 ring-1 ring-sidebar-border';
    }
};

const statusLabel = (status?: string | null) =>
    t(`statuses.${status ?? 'draft'}`);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />

            <SidebarGroup v-if="recentFlows.length" class="px-2 pt-2">
                <SidebarGroupLabel>{{
                    t('nav.recent_flows')
                }}</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="flow in recentFlows"
                        :key="flow.id"
                        class="group/sidebar-flow"
                    >
                        <SidebarMenuButton
                            as-child
                            size="sm"
                            :tooltip="flow.name"
                            :is-active="
                                urlIsActive(
                                    flowShow({ flow: flow.id }).url,
                                    page.url,
                                )
                            "
                        >
                            <Link
                                :href="flowShow({ flow: flow.id }).url"
                                class="flex items-center gap-2"
                            >
                                <Activity
                                    class="size-4 text-muted-foreground"
                                />
                                <span class="truncate">{{ flow.name }}</span>
                                <span
                                    class="ml-auto rounded-md px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                                    :class="statusTone(flow.status)"
                                >
                                    {{ statusLabel(flow.status) }}
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter
                v-if="footerNavItems.length > 0"
                :items="footerNavItems"
            />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
