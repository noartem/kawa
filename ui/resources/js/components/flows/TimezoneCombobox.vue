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
import { computed, ref } from 'vue';

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

const selectedTimezone = computed(() => {
    if (props.options.includes(timezone.value)) {
        return timezone.value;
    }

    return '';
});

const selectTimezone = (nextTimezone: string): void => {
    timezone.value = nextTimezone;
    open.value = false;
};
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

        <PopoverContent class="w-[--reka-popover-trigger-width] p-0">
            <Command>
                <CommandInput :placeholder="searchPlaceholder" />

                <CommandList>
                    <CommandEmpty>{{ emptyLabel }}</CommandEmpty>

                    <CommandGroup>
                        <CommandItem
                            v-for="timezoneOption in options"
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
