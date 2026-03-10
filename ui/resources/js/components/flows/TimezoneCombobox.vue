<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const INITIAL_OPTIONS_LIMIT = 40;
const SEARCH_OPTIONS_LIMIT = 120;

const timezone = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        id?: string;
        options: string[];
        placeholder: string;
        searchPlaceholder: string;
        emptyLabel: string;
        disabled?: boolean;
    }>(),
    {
        id: undefined,
        disabled: false,
    },
);

const open = ref(false);
const searchTerm = ref('');

const selectedTimezone = computed(() => {
    if (props.options.includes(timezone.value)) {
        return timezone.value;
    }

    return '';
});

const visibleOptions = computed(() => {
    const normalizedSearchTerm = searchTerm.value.trim().toLowerCase();

    if (normalizedSearchTerm.length === 0) {
        const initialOptions = props.options.slice(0, INITIAL_OPTIONS_LIMIT);

        if (
            selectedTimezone.value.length > 0 &&
            !initialOptions.includes(selectedTimezone.value)
        ) {
            return [selectedTimezone.value, ...initialOptions];
        }

        return initialOptions;
    }

    return props.options
        .filter((option) => option.toLowerCase().includes(normalizedSearchTerm))
        .slice(0, SEARCH_OPTIONS_LIMIT);
});

const selectTimezone = (nextTimezone: string): void => {
    timezone.value = nextTimezone;
    open.value = false;
};

watch(open, (isOpen) => {
    if (!isOpen) {
        searchTerm.value = '';
    }
});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                :disabled="disabled"
                class="w-full justify-between"
            >
                <span class="truncate text-left">
                    {{ selectedTimezone || placeholder }}
                </span>
                <ChevronsUpDown class="ml-2 size-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>

        <PopoverContent
            class="w-[var(--reka-popover-trigger-width)] p-0"
            :style="{
                maxHeight: 'var(--reka-popover-content-available-height)',
            }"
        >
            <Command>
                <CommandInput
                    :placeholder="searchPlaceholder"
                    @update:model-value="(value) => (searchTerm = value)"
                />
                <CommandList>
                    <CommandEmpty>{{ emptyLabel }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="timezoneOption in visibleOptions"
                            :key="timezoneOption"
                            :value="timezoneOption"
                            @select="() => selectTimezone(timezoneOption)"
                        >
                            <Check
                                :class="
                                    cn(
                                        'mr-2 size-4',
                                        timezoneOption === selectedTimezone
                                            ? 'opacity-100'
                                            : 'opacity-0',
                                    )
                                "
                            />
                            <span class="truncate">{{ timezoneOption }}</span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
