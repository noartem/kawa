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
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { urlIsActive } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    create as flowCreate,
    deployments as flowDeployments,
    editor as flowEditor,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import { index as flowChatIndex } from '@/routes/flows/chat';
import { type FlowSidebarItem, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpRight,
    LayoutGrid,
    List,
    MessageSquarePlus,
    Plus,
    SquarePen,
} from 'lucide-vue-next';
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
        icon: List,
    },
    {
        title: t('nav.new_flow'),
        href: flowCreate().url,
        icon: Plus,
    },
]);

const footerNavItems: NavItem[] = [];

const page = usePage();
const { isMobile, state } = useSidebar();
const recentFlows = computed<FlowSidebarItem[]>(
    () => (page.props.recentFlows as FlowSidebarItem[] | undefined) ?? [],
);
const showRecentFlowTooltip = computed(
    () => state.value === 'collapsed' && !isMobile.value,
);

const statusTone = (status?: string | null) => {
    switch (status) {
        case 'creating':
        case 'created':
        case 'stopping':
            return 'bg-sky-500/15 text-sky-300 ring-1 ring-sky-500/30';
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

const flowEditorUrl = (flow: FlowSidebarItem): string =>
    flowEditor({ flow: flow.id }).url;

const flowShowUrl = (flow: FlowSidebarItem): string =>
    flowShow({ flow: flow.id }).url;

const flowDeploymentsUrl = (flow: FlowSidebarItem): string =>
    flowDeployments({ flow: flow.id }).url;

const flowChatsUrl = (flow: FlowSidebarItem): string =>
    flowChatIndex({ flow: flow.id }).url;
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
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <SidebarMenuButton
                                    as-child
                                    size="sm"
                                    :is-active="
                                        urlIsActive(flowShowUrl(flow), page.url)
                                    "
                                >
                                    <Link
                                        :href="flowShowUrl(flow)"
                                        class="flex items-center gap-2"
                                    >
                                        <Activity
                                            class="size-4 text-muted-foreground"
                                        />
                                        <span class="truncate">{{
                                            flow.name
                                        }}</span>
                                        <span
                                            class="ml-auto rounded-md px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                                            :class="statusTone(flow.status)"
                                        >
                                            {{ statusLabel(flow.status) }}
                                        </span>
                                    </Link>
                                </SidebarMenuButton>
                            </TooltipTrigger>

                            <TooltipContent
                                side="right"
                                align="start"
                                :hidden="!showRecentFlowTooltip"
                                class="w-60 rounded-lg p-0"
                            >
                                <div class="flex flex-col">
                                    <div class="px-3 py-2.5">
                                        <p
                                            class="truncate text-sm font-medium text-popover-foreground"
                                        >
                                            {{ flow.name }}
                                        </p>
                                    </div>

                                    <div
                                        class="flex flex-col gap-1 border-t border-border p-1.5"
                                    >
                                        <Link
                                            :href="flowShowUrl(flow)"
                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                                        >
                                            <LayoutGrid class="size-3.5" />
                                            {{ t('flows.actions.general') }}
                                        </Link>

                                        <Link
                                            :href="flowEditorUrl(flow)"
                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                                        >
                                            <SquarePen class="size-3.5" />
                                            {{ t('flows.actions.open_editor') }}
                                        </Link>

                                        <Link
                                            :href="flowDeploymentsUrl(flow)"
                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                                        >
                                            <ArrowUpRight class="size-3.5" />
                                            {{
                                                t(
                                                    'flows.deployments_page.title',
                                                )
                                            }}
                                        </Link>

                                        <Link
                                            :href="flowChatsUrl(flow)"
                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                                        >
                                            <MessageSquarePlus
                                                class="size-3.5"
                                            />
                                            {{ t('flows.chats_page.title') }}
                                        </Link>
                                    </div>
                                </div>
                            </TooltipContent>
                        </Tooltip>
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
