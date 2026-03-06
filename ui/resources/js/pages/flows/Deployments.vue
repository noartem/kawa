<script setup lang="ts">
import FlowDeploymentDetailsDialog from '@/components/flows/editor/FlowDeploymentDetailsDialog.vue';
import type {
    DeploymentCard,
    FlowDeployment,
    FlowDeploymentsPaginator,
    FlowDeploymentsSortDirection,
    FlowDeploymentsSortKey,
    FlowRun,
} from '@/components/flows/editor/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    deployments as flowDeployments,
    show as flowShow,
    index as flowsIndex,
} from '@/routes/flows';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowDownUp,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    Filter,
    FilterX,
    Search,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

type DeploymentsQuery = {
    search?: string;
    status?: string;
    type?: FlowRun['type'];
    sort?: FlowDeploymentsSortKey;
    direction?: FlowDeploymentsSortDirection;
    page?: number;
};

const props = defineProps<{
    flow: {
        id: number;
        name: string;
    };
    deployments: FlowDeploymentsPaginator;
    filters: {
        search?: string | null;
        status?: string | null;
        type?: FlowRun['type'] | null;
    };
    sorting: {
        column: FlowDeploymentsSortKey;
        direction: FlowDeploymentsSortDirection;
    };
    statusOptions: string[];
}>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('nav.flows'),
        href: flowsIndex().url,
    },
    {
        title: t('flows.breadcrumbs.flow', { id: props.flow.id }),
        href: flowShow({ flow: props.flow.id }).url,
    },
    {
        title: t('flows.deployments_page.title'),
        href: flowDeployments({ flow: props.flow.id }).url,
    },
]);

const searchValue = ref(props.filters.search ?? '');
const selectedStatus = ref<string | null>(props.filters.status ?? null);
const selectedType = ref<FlowRun['type'] | null>(props.filters.type ?? null);
const sortColumn = ref<FlowDeploymentsSortKey>(props.sorting.column);
const sortDirection = ref<FlowDeploymentsSortDirection>(
    props.sorting.direction,
);

const detailsOpen = ref(false);
const selectedDeploymentId = ref<number | null>(null);

const statusLabel = (status?: string | null): string => {
    return t(`statuses.${status ?? 'unknown'}`);
};

const runTypeLabel = (type?: FlowRun['type'] | null): string => {
    return type === 'production'
        ? t('environments.production')
        : t('environments.development');
};

const statusTone = (status?: string | null): string => {
    switch (status) {
        case 'running':
        case 'ready':
        case 'locked':
            return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300';
        case 'error':
        case 'failed':
        case 'lock_failed':
            return 'border-rose-500/40 bg-rose-500/10 text-rose-300';
        case 'stopped':
        case 'success':
            return 'border-amber-500/40 bg-amber-500/10 text-amber-300';
        default:
            return 'border-border bg-muted/40 text-muted-foreground';
    }
};

const parseDateMs = (value?: string | null): number | null => {
    if (!value) {
        return null;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return parsed.getTime();
};

const formatDate = (value?: string | null): string => {
    if (!value) {
        return t('common.empty');
    }

    const parsed = parseDateMs(value);

    if (parsed === null) {
        return value;
    }

    return new Date(parsed).toLocaleString();
};

const formatDuration = (start?: string | null, end?: string | null): string => {
    if (!start) {
        return t('common.empty');
    }

    const startAt = parseDateMs(start);
    const endAt = parseDateMs(end) ?? Date.now();

    if (startAt === null) {
        return t('common.empty');
    }

    const totalSeconds = Math.max(Math.floor((endAt - startAt) / 1000), 0);
    const minutes = Math.floor(totalSeconds / 60);
    const hours = Math.floor(minutes / 60);

    if (hours > 0) {
        return t('common.duration.hours', { hours, minutes: minutes % 60 });
    }

    if (minutes > 0) {
        return t('common.duration.minutes', {
            minutes,
            seconds: totalSeconds % 60,
        });
    }

    return t('common.duration.seconds', { seconds: totalSeconds });
};

const countGraphNodesByType = (
    graph: Record<string, unknown> | null | undefined,
    expectedType: 'actor' | 'event',
): number => {
    const rawNodes = Array.isArray(graph?.nodes) ? graph.nodes : [];
    let count = 0;

    for (const rawNode of rawNodes) {
        if (!rawNode || typeof rawNode !== 'object') {
            continue;
        }

        const node = rawNode as Record<string, unknown>;
        const nodeType =
            typeof node.type === 'string' ? node.type.toLowerCase() : null;

        if (nodeType === expectedType) {
            count += 1;
        }
    }

    return count;
};

const deploymentCards = computed<DeploymentCard[]>(() => {
    return props.deployments.data.map((deployment: FlowDeployment) => ({
        deployment,
        graphMeta: {
            actors: countGraphNodesByType(deployment.graph, 'actor'),
            events: countGraphNodesByType(deployment.graph, 'event'),
            status: statusLabel(deployment.status),
            freshnessLabel: t('common.updated_at'),
            updatedAt: formatDate(
                deployment.finished_at ??
                    deployment.started_at ??
                    deployment.created_at,
            ),
        },
    }));
});

const selectedDeploymentCard = computed<DeploymentCard | null>(() => {
    if (selectedDeploymentId.value === null) {
        return null;
    }

    return (
        deploymentCards.value.find(
            ({ deployment }) => deployment.id === selectedDeploymentId.value,
        ) ?? null
    );
});

const pageNumbers = computed<number[]>(() => {
    const start = Math.max(1, props.deployments.current_page - 2);
    const end = Math.min(
        props.deployments.last_page,
        props.deployments.current_page + 2,
    );
    const pages: number[] = [];

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const hasActiveFilters = computed<boolean>(() => {
    return (
        searchValue.value.trim() !== '' ||
        selectedStatus.value !== null ||
        selectedType.value !== null ||
        sortColumn.value !== 'created_at' ||
        sortDirection.value !== 'desc'
    );
});

const resultsLabel = computed<string>(() => {
    if (props.deployments.total === 0) {
        return t('flows.deployments_page.results_empty');
    }

    const fallbackFrom =
        (props.deployments.current_page - 1) * props.deployments.per_page + 1;
    const from = props.deployments.from ?? fallbackFrom;
    const to = props.deployments.to ?? from + deploymentCards.value.length - 1;

    return t('flows.deployments_page.results', {
        from,
        to,
        total: props.deployments.total,
    });
});

const statusFilterValue = computed<string>(() => selectedStatus.value ?? 'all');
const typeFilterValue = computed<string>(() => selectedType.value ?? 'all');

const typeOptions = computed(() => [
    {
        value: 'all',
        label: t('flows.deployments_page.filters.type_all'),
    },
    {
        value: 'development',
        label: t('environments.development'),
    },
    {
        value: 'production',
        label: t('environments.production'),
    },
]);

const buildQuery = (
    overrides: Partial<DeploymentsQuery> = {},
): DeploymentsQuery => {
    const merged: DeploymentsQuery = {
        search: searchValue.value.trim() || undefined,
        status: selectedStatus.value ?? undefined,
        type: selectedType.value ?? undefined,
        sort: sortColumn.value,
        direction: sortDirection.value,
        ...overrides,
    };

    const query: DeploymentsQuery = {};

    for (const [key, value] of Object.entries(merged)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (key === 'page' && value === 1) {
            continue;
        }

        query[key as keyof DeploymentsQuery] = value;
    }

    return query;
};

const paginationHref = (page: number): string => {
    return flowDeployments(
        { flow: props.flow.id },
        {
            query: buildQuery({ page }),
        },
    ).url;
};

const applyQuery = (overrides: Partial<DeploymentsQuery> = {}): void => {
    router.get(
        flowDeployments({ flow: props.flow.id }).url,
        buildQuery(overrides),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const applySearch = (): void => {
    searchValue.value = searchValue.value.trim();
    applyQuery({ search: searchValue.value || undefined, page: 1 });
};

const onStatusFilterChange = (value: string): void => {
    const nextStatus = value === 'all' ? null : value;

    if (selectedStatus.value === nextStatus) {
        return;
    }

    selectedStatus.value = nextStatus;
    applyQuery({ status: nextStatus ?? undefined, page: 1 });
};

const onTypeFilterChange = (value: string): void => {
    const nextType = value === 'all' ? null : (value as FlowRun['type']);

    if (selectedType.value === nextType) {
        return;
    }

    selectedType.value = nextType;
    applyQuery({ type: nextType ?? undefined, page: 1 });
};

const resetFilters = (): void => {
    searchValue.value = '';
    selectedStatus.value = null;
    selectedType.value = null;
    sortColumn.value = 'created_at';
    sortDirection.value = 'desc';

    applyQuery({
        search: undefined,
        status: undefined,
        type: undefined,
        sort: 'created_at',
        direction: 'desc',
        page: 1,
    });
};

const toggleSorting = (column: FlowDeploymentsSortKey): void => {
    if (sortColumn.value === column) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = column;
        sortDirection.value = 'desc';
    }

    applyQuery({
        sort: sortColumn.value,
        direction: sortDirection.value,
        page: 1,
    });
};

const sortIconFor = (column: FlowDeploymentsSortKey) => {
    if (sortColumn.value !== column) {
        return ArrowDownUp;
    }

    return sortDirection.value === 'asc' ? ArrowUp : ArrowDown;
};

const openDeploymentDetails = (deploymentId: number): void => {
    selectedDeploymentId.value = deploymentId;
    detailsOpen.value = true;
};

watch(
    () => props.filters,
    (filters) => {
        searchValue.value = filters.search ?? '';
        selectedStatus.value = filters.status ?? null;
        selectedType.value = filters.type ?? null;
    },
    { deep: true },
);

watch(
    () => props.sorting,
    (sorting) => {
        sortColumn.value = sorting.column;
        sortDirection.value = sorting.direction;
    },
    { deep: true },
);

watch(detailsOpen, (open) => {
    if (!open) {
        selectedDeploymentId.value = null;
    }
});

watch(deploymentCards, (cards) => {
    if (selectedDeploymentId.value === null) {
        return;
    }

    const hasSelectedDeployment = cards.some(
        ({ deployment }) => deployment.id === selectedDeploymentId.value,
    );

    if (!hasSelectedDeployment) {
        detailsOpen.value = false;
        selectedDeploymentId.value = null;
    }
});
</script>

<template>
    <Head :title="t('flows.deployments_page.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1600px] divide-y">
            <h1 class="p-4 text-xl font-semibold">
                {{ t('flows.deployments_page.title') }}
            </h1>

            <div class="p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <form
                        class="flex w-full flex-col gap-2 sm:flex-row"
                        @submit.prevent="applySearch"
                    >
                        <div class="relative w-full">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="searchValue"
                                :placeholder="
                                    t(
                                        'flows.deployments_page.filters.search_placeholder',
                                    )
                                "
                                class="pl-9"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="secondary"
                            class="sm:w-auto"
                        >
                            {{ t('flows.deployments_page.filters.apply') }}
                        </Button>
                    </form>

                    <div class="flex flex-wrap items-center gap-2">
                        <DropdownMenu>
                            <DropdownMenuTrigger :as-child="true">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="gap-2"
                                >
                                    <Filter class="size-4" />
                                    {{
                                        t(
                                            'flows.deployments_page.filters.status',
                                        )
                                    }}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuLabel>
                                    {{
                                        t(
                                            'flows.deployments_page.filters.status',
                                        )
                                    }}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuRadioGroup
                                    :model-value="statusFilterValue"
                                    @update:model-value="onStatusFilterChange"
                                >
                                    <DropdownMenuRadioItem value="all">
                                        {{
                                            t(
                                                'flows.deployments_page.filters.status_all',
                                            )
                                        }}
                                    </DropdownMenuRadioItem>
                                    <DropdownMenuRadioItem
                                        v-for="status in props.statusOptions"
                                        :key="status"
                                        :value="status"
                                    >
                                        {{ statusLabel(status) }}
                                    </DropdownMenuRadioItem>
                                </DropdownMenuRadioGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <DropdownMenu>
                            <DropdownMenuTrigger :as-child="true">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="gap-2"
                                >
                                    <Filter class="size-4" />
                                    {{
                                        t('flows.deployments_page.filters.type')
                                    }}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuLabel>
                                    {{
                                        t('flows.deployments_page.filters.type')
                                    }}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuRadioGroup
                                    :model-value="typeFilterValue"
                                    @update:model-value="onTypeFilterChange"
                                >
                                    <DropdownMenuRadioItem
                                        v-for="option in typeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </DropdownMenuRadioItem>
                                </DropdownMenuRadioGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Button
                            v-if="hasActiveFilters"
                            variant="ghost"
                            size="sm"
                            class="gap-2"
                            @click="resetFilters"
                        >
                            <FilterX class="size-4" />
                            {{ t('flows.deployments_page.filters.reset') }}
                        </Button>
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('id')"
                                >
                                    {{ t('flows.deployments_page.columns.id') }}
                                    <component
                                        :is="sortIconFor('id')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                            <TableHead>
                                {{ t('flows.deployments_page.columns.status') }}
                            </TableHead>
                            <TableHead>
                                {{ t('flows.deployments_page.columns.type') }}
                            </TableHead>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('started_at')"
                                >
                                    {{
                                        t(
                                            'flows.deployments_page.columns.started',
                                        )
                                    }}
                                    <component
                                        :is="sortIconFor('started_at')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('finished_at')"
                                >
                                    {{
                                        t(
                                            'flows.deployments_page.columns.finished',
                                        )
                                    }}
                                    <component
                                        :is="sortIconFor('finished_at')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                            <TableHead>
                                {{
                                    t('flows.deployments_page.columns.duration')
                                }}
                            </TableHead>
                            <TableHead class="text-right">
                                {{ t('flows.deployments_page.columns.logs') }}
                            </TableHead>
                            <TableHead class="text-right">
                                {{ t('flows.deployments_page.columns.actors') }}
                            </TableHead>
                            <TableHead class="text-right">
                                {{ t('flows.deployments_page.columns.events') }}
                            </TableHead>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('updated_at')"
                                >
                                    {{
                                        t(
                                            'flows.deployments_page.columns.updated',
                                        )
                                    }}
                                    <component
                                        :is="sortIconFor('updated_at')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <TableRow
                            v-for="card in deploymentCards"
                            :key="card.deployment.id"
                            class="cursor-pointer"
                            tabindex="0"
                            @click="openDeploymentDetails(card.deployment.id)"
                            @keydown.enter.prevent="
                                openDeploymentDetails(card.deployment.id)
                            "
                            @keydown.space.prevent="
                                openDeploymentDetails(card.deployment.id)
                            "
                        >
                            <TableCell class="font-medium">
                                <div class="space-y-0.5">
                                    <p class="font-mono text-xs">
                                        #{{ card.deployment.id }}
                                    </p>
                                    <p
                                        v-if="card.deployment.container_id"
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ card.deployment.container_id }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    variant="outline"
                                    :class="statusTone(card.deployment.status)"
                                >
                                    {{ statusLabel(card.deployment.status) }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    variant="outline"
                                    class="border-border bg-muted/50 text-muted-foreground"
                                >
                                    {{ runTypeLabel(card.deployment.type) }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">
                                {{ formatDate(card.deployment.started_at) }}
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">
                                {{ formatDate(card.deployment.finished_at) }}
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">
                                {{
                                    formatDuration(
                                        card.deployment.started_at,
                                        card.deployment.finished_at,
                                    )
                                }}
                            </TableCell>
                            <TableCell class="text-right font-mono text-xs">
                                {{ card.deployment.logs.length }}
                            </TableCell>
                            <TableCell class="text-right font-mono text-xs">
                                {{ card.graphMeta.actors }}
                            </TableCell>
                            <TableCell class="text-right font-mono text-xs">
                                {{ card.graphMeta.events }}
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">
                                {{ card.graphMeta.updatedAt }}
                            </TableCell>
                        </TableRow>

                        <TableRow v-if="deploymentCards.length === 0">
                            <TableCell
                                colspan="10"
                                class="py-10 text-center text-muted-foreground"
                            >
                                {{ t('flows.deployments_page.empty') }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="props.deployments.last_page > 1"
                    class="border-t border-border p-3"
                >
                    <nav
                        class="flex flex-wrap items-center justify-between gap-2"
                        :aria-label="t('flows.deployments_page.title')"
                    >
                        <Button
                            v-if="props.deployments.current_page > 1"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link
                                :href="
                                    paginationHref(
                                        props.deployments.current_page - 1,
                                    )
                                "
                                preserve-state
                                preserve-scroll
                            >
                                <ChevronLeft class="size-4" />
                                {{
                                    t(
                                        'flows.deployments_page.pagination.previous',
                                    )
                                }}
                            </Link>
                        </Button>
                        <span v-else class="text-sm text-muted-foreground">
                            {{
                                t('flows.deployments_page.pagination.previous')
                            }}
                        </span>

                        <div class="flex flex-wrap items-center gap-1">
                            <Button
                                v-for="page in pageNumbers"
                                :key="page"
                                as-child
                                size="sm"
                                :variant="
                                    page === props.deployments.current_page
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                <Link
                                    :href="paginationHref(page)"
                                    preserve-state
                                    preserve-scroll
                                    :aria-current="
                                        page === props.deployments.current_page
                                            ? 'page'
                                            : undefined
                                    "
                                >
                                    {{ page }}
                                </Link>
                            </Button>
                        </div>

                        <Button
                            v-if="
                                props.deployments.current_page <
                                props.deployments.last_page
                            "
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link
                                :href="
                                    paginationHref(
                                        props.deployments.current_page + 1,
                                    )
                                "
                                preserve-state
                                preserve-scroll
                            >
                                {{
                                    t('flows.deployments_page.pagination.next')
                                }}
                                <ChevronRight class="size-4" />
                            </Link>
                        </Button>
                        <span v-else class="text-sm text-muted-foreground">
                            {{ t('flows.deployments_page.pagination.next') }}
                        </span>
                    </nav>
                </div>
            </div>
        </div>

        <FlowDeploymentDetailsDialog
            v-model:open="detailsOpen"
            :deployment-card="selectedDeploymentCard"
            :status-tone="statusTone"
            :status-label="statusLabel"
            :run-type-label="runTypeLabel"
            :format-date="formatDate"
            :format-duration="formatDuration"
        />
    </AppLayout>
</template>
