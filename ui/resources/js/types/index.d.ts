import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface FlowSidebarItem {
    id: number;
    name: string;
    slug: string;
    status?: string | null;
    last_started_at?: string | null;
    last_finished_at?: string | null;
    archived_at?: string | null;
}

export interface FlowRunSummary {
    id: number;
    type: 'development' | 'production';
    active: boolean;
    status?: string | null;
    started_at?: string | null;
    finished_at?: string | null;
    lock?: string | null;
    created_at: string;
    flow: {
        id: number;
        name: string;
        slug: string;
        status?: string | null;
    };
}

export interface FlowStatsSummary {
    total: number;
    running: number;
    stopped: number;
    errors: number;
    withContainer: number;
    lastUpdatedAt: string | null;
    runsByStatus: { status: string | null; total: number }[];
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    locale: string;
    locales: string[];
    sidebarOpen: boolean;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    recentFlows?: FlowSidebarItem[];
};

export interface User {
    id: number;
    name: string;
    email: string;
    locale?: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
