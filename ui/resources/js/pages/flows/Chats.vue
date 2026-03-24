<script setup lang="ts">
import FlowPastChatDetailsDialog from '@/components/flows/editor/FlowPastChatDetailsDialog.vue';
import type {
    FlowChatConversation,
    FlowChatsPaginator,
    FlowChatsSortDirection,
    FlowChatsSortKey,
} from '@/components/flows/editor/types';
import { Button } from '@/components/ui/button';
import { ClearableSearchFilter } from '@/components/ui/filters';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as flowShow, index as flowsIndex } from '@/routes/flows';
import { index as flowChatIndex } from '@/routes/flows/chat';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowDownUp, ArrowUp } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

type ChatsQuery = {
    search?: string;
    sort?: FlowChatsSortKey;
    direction?: FlowChatsSortDirection;
    page?: number;
};

type PaginationToken = number | 'ellipsis-left' | 'ellipsis-right';

const props = defineProps<{
    flow: {
        id: number;
        name: string;
    };
    chats: FlowChatsPaginator;
    filters: {
        search?: string | null;
    };
    sorting: {
        column: FlowChatsSortKey;
        direction: FlowChatsSortDirection;
    };
}>();

const { t, locale } = useI18n();

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
        title: t('flows.chats_page.title'),
        href: flowChatIndex({ flow: props.flow.id }).url,
    },
]);

const searchValue = ref(props.filters.search ?? '');
const sortColumn = ref<FlowChatsSortKey>(props.sorting.column);
const sortDirection = ref<FlowChatsSortDirection>(props.sorting.direction);

const detailsOpen = ref(false);
const selectedChatId = ref<string | null>(null);

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

const relativeTimeFormatter = computed(() => {
    return new Intl.RelativeTimeFormat(locale.value, {
        numeric: 'auto',
    });
});

const formatRecentDate = (value?: string | null): string => {
    const parsed = parseDateMs(value);

    if (parsed === null) {
        return formatDate(value);
    }

    const deltaSeconds = Math.round((parsed - Date.now()) / 1000);
    const absSeconds = Math.abs(deltaSeconds);

    if (absSeconds < 60) {
        return relativeTimeFormatter.value.format(deltaSeconds, 'second');
    }

    const deltaMinutes = Math.round(deltaSeconds / 60);

    if (Math.abs(deltaMinutes) < 60) {
        return relativeTimeFormatter.value.format(deltaMinutes, 'minute');
    }

    const deltaHours = Math.round(deltaMinutes / 60);

    if (Math.abs(deltaHours) < 24) {
        return relativeTimeFormatter.value.format(deltaHours, 'hour');
    }

    const deltaDays = Math.round(deltaHours / 24);

    return relativeTimeFormatter.value.format(deltaDays, 'day');
};

const selectedChat = computed<FlowChatConversation | null>(() => {
    if (selectedChatId.value === null) {
        return null;
    }

    return (
        props.chats.data.find((chat) => chat.id === selectedChatId.value) ??
        null
    );
});

const paginationItems = computed<PaginationToken[]>(() => {
    const totalPages = props.chats.last_page;
    const currentPage = props.chats.current_page;

    if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    if (currentPage <= 4) {
        return [1, 2, 3, 4, 5, 'ellipsis-right', totalPages];
    }

    if (currentPage >= totalPages - 3) {
        return [
            1,
            'ellipsis-left',
            totalPages - 4,
            totalPages - 3,
            totalPages - 2,
            totalPages - 1,
            totalPages,
        ];
    }

    return [
        1,
        'ellipsis-left',
        currentPage - 1,
        currentPage,
        currentPage + 1,
        'ellipsis-right',
        totalPages,
    ];
});

const resultsLabel = computed<string>(() => {
    if (props.chats.total === 0) {
        return t('flows.chats_page.results_empty');
    }

    const fallbackFrom =
        (props.chats.current_page - 1) * props.chats.per_page + 1;
    const from = props.chats.from ?? fallbackFrom;
    const to = props.chats.to ?? from + props.chats.data.length - 1;

    return t('flows.chats_page.results', {
        from,
        to,
        total: props.chats.total,
    });
});

const FILTER_DEBOUNCE_MS = 350;
let queryDebounceTimer: ReturnType<typeof setTimeout> | null = null;

const buildQuery = (overrides: Partial<ChatsQuery> = {}): ChatsQuery => {
    const merged: ChatsQuery = {
        search: searchValue.value.trim() || undefined,
        sort: sortColumn.value,
        direction: sortDirection.value,
        ...overrides,
    };

    const query: ChatsQuery = {};

    for (const [key, value] of Object.entries(merged)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (key === 'page' && value === 1) {
            continue;
        }

        query[key as keyof ChatsQuery] = value;
    }

    return query;
};

const paginationHref = (page: number): string => {
    return flowChatIndex(
        { flow: props.flow.id },
        {
            query: buildQuery({ page }),
        },
    ).url;
};

const applyQuery = (overrides: Partial<ChatsQuery> = {}): void => {
    router.get(
        flowChatIndex({ flow: props.flow.id }).url,
        buildQuery(overrides),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const clearQueryDebounce = (): void => {
    if (queryDebounceTimer !== null) {
        clearTimeout(queryDebounceTimer);
        queryDebounceTimer = null;
    }
};

const scheduleApplyQuery = (overrides: Partial<ChatsQuery> = {}): void => {
    clearQueryDebounce();
    queryDebounceTimer = setTimeout(() => {
        applyQuery(overrides);
        queryDebounceTimer = null;
    }, FILTER_DEBOUNCE_MS);
};

const onSearchInput = (): void => {
    scheduleApplyQuery({
        search: searchValue.value.trim() || undefined,
        page: 1,
    });
};

const toggleSorting = (column: FlowChatsSortKey): void => {
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

const sortIconFor = (column: FlowChatsSortKey) => {
    if (sortColumn.value !== column) {
        return ArrowDownUp;
    }

    return sortDirection.value === 'asc' ? ArrowUp : ArrowDown;
};

const openChatDetails = (chatId: string): void => {
    selectedChatId.value = chatId;
    detailsOpen.value = true;
};

watch(
    () => props.filters,
    (filters) => {
        searchValue.value = filters.search ?? '';
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
        selectedChatId.value = null;
    }
});

watch(
    () => props.chats.data,
    (chats) => {
        if (selectedChatId.value === null) {
            return;
        }

        const hasSelectedChat = chats.some(
            (chat) => chat.id === selectedChatId.value,
        );

        if (!hasSelectedChat) {
            detailsOpen.value = false;
            selectedChatId.value = null;
        }
    },
);

onBeforeUnmount(() => {
    clearQueryDebounce();
});
</script>

<template>
    <Head :title="t('flows.chats_page.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1600px] divide-y">
            <div class="space-y-1 p-4">
                <h1 class="text-xl font-semibold">
                    {{ t('flows.chats_page.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ resultsLabel }}
                </p>
            </div>

            <div class="p-4">
                <div class="flex flex-col gap-2 md:flex-row md:items-center">
                    <ClearableSearchFilter
                        v-model="searchValue"
                        class="w-full md:max-w-xl"
                        :placeholder="
                            t('flows.chats_page.filters.search_placeholder')
                        "
                        :clear-label="t('flows.chats_page.filters.reset')"
                        clearable
                        @input="onSearchInput"
                    />
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('title')"
                                >
                                    {{ t('flows.chats_page.columns.title') }}
                                    <component
                                        :is="sortIconFor('title')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                            <TableHead>
                                {{ t('flows.chats_page.columns.preview') }}
                            </TableHead>
                            <TableHead class="text-right">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('messages_count')"
                                >
                                    {{ t('flows.chats_page.columns.messages') }}
                                    <component
                                        :is="sortIconFor('messages_count')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('created_at')"
                                >
                                    {{ t('flows.chats_page.columns.created') }}
                                    <component
                                        :is="sortIconFor('created_at')"
                                        class="ml-1 size-3.5"
                                    />
                                </Button>
                            </TableHead>
                            <TableHead>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 h-8 px-2"
                                    @click="toggleSorting('updated_at')"
                                >
                                    {{ t('flows.chats_page.columns.updated') }}
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
                            v-for="chat in props.chats.data"
                            :key="chat.id"
                            class="cursor-pointer"
                            tabindex="0"
                            @click="openChatDetails(chat.id)"
                            @keydown.enter.prevent="openChatDetails(chat.id)"
                            @keydown.space.prevent="openChatDetails(chat.id)"
                        >
                            <TableCell>
                                <div class="space-y-1">
                                    <p class="font-medium text-foreground">
                                        {{ chat.title }}
                                    </p>
                                    <p
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ chat.id }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell
                                class="max-w-xl text-sm text-muted-foreground"
                            >
                                <p class="line-clamp-2 break-words">
                                    {{ chat.preview || t('common.empty') }}
                                </p>
                            </TableCell>
                            <TableCell class="text-right font-mono text-xs">
                                {{ chat.messages_count }}
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">
                                {{ formatDate(chat.created_at) }}
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">
                                {{ formatRecentDate(chat.updated_at) }}
                            </TableCell>
                        </TableRow>

                        <TableRow v-if="props.chats.data.length === 0">
                            <TableCell
                                colspan="5"
                                class="py-10 text-center text-muted-foreground"
                            >
                                {{ t('flows.chats_page.empty') }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="props.chats.last_page > 1"
                    class="border-t border-border p-3"
                >
                    <Pagination :aria-label="t('flows.chats_page.title')">
                        <PaginationContent>
                            <PaginationItem>
                                <PaginationPrevious
                                    v-if="props.chats.current_page > 1"
                                    :as-child="true"
                                >
                                    <Link
                                        :href="
                                            paginationHref(
                                                props.chats.current_page - 1,
                                            )
                                        "
                                        preserve-state
                                        preserve-scroll
                                    >
                                        {{
                                            t(
                                                'flows.chats_page.pagination.previous',
                                            )
                                        }}
                                    </Link>
                                </PaginationPrevious>
                                <PaginationPrevious
                                    v-else
                                    as="span"
                                    class="pointer-events-none opacity-50"
                                >
                                    {{
                                        t(
                                            'flows.chats_page.pagination.previous',
                                        )
                                    }}
                                </PaginationPrevious>
                            </PaginationItem>

                            <PaginationItem
                                v-for="item in paginationItems"
                                :key="item"
                            >
                                <PaginationEllipsis
                                    v-if="typeof item !== 'number'"
                                />
                                <PaginationLink
                                    v-else
                                    :as-child="true"
                                    :is-active="
                                        item === props.chats.current_page
                                    "
                                >
                                    <Link
                                        :href="paginationHref(item)"
                                        preserve-state
                                        preserve-scroll
                                        :aria-current="
                                            item === props.chats.current_page
                                                ? 'page'
                                                : undefined
                                        "
                                    >
                                        {{ item }}
                                    </Link>
                                </PaginationLink>
                            </PaginationItem>

                            <PaginationItem>
                                <PaginationNext
                                    v-if="
                                        props.chats.current_page <
                                        props.chats.last_page
                                    "
                                    :as-child="true"
                                >
                                    <Link
                                        :href="
                                            paginationHref(
                                                props.chats.current_page + 1,
                                            )
                                        "
                                        preserve-state
                                        preserve-scroll
                                    >
                                        {{
                                            t(
                                                'flows.chats_page.pagination.next',
                                            )
                                        }}
                                    </Link>
                                </PaginationNext>
                                <PaginationNext
                                    v-else
                                    as="span"
                                    class="pointer-events-none opacity-50"
                                >
                                    {{ t('flows.chats_page.pagination.next') }}
                                </PaginationNext>
                            </PaginationItem>
                        </PaginationContent>
                    </Pagination>
                </div>
            </div>
        </div>

        <FlowPastChatDetailsDialog
            v-model:open="detailsOpen"
            :chat="selectedChat"
            :format-date="formatDate"
            :format-recent-date="formatRecentDate"
        />
    </AppLayout>
</template>
