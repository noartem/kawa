<script setup lang="ts">
import TimezoneCombobox from '@/components/flows/TimezoneCombobox.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Archive, Save, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const name = defineModel<string>('name', { required: true });
const description = defineModel<string>('description', { required: true });
const timezone = defineModel<string>('timezone', { required: true });

defineProps<{
    processing: boolean;
    canSave: boolean;
    isArchived: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    hasActiveDeploys: boolean;
    actionInProgress: string | null;
    timezoneOptions: string[];
    nameError?: string;
    timezoneError?: string;
}>();

defineEmits<{
    save: [];
    archive: [];
    restore: [];
    delete: [];
}>();

const { t } = useI18n();
</script>

<template>
    <section class="space-y-3 p-4">
        <h2 class="text-lg font-semibold">
            {{ t('flows.settings.title') }}
        </h2>

        <div class="max-w-2xl space-y-3">
            <div class="space-y-2">
                <Label for="flow-name">{{ t('flows.settings.name') }}</Label>
                <Input
                    id="flow-name"
                    v-model="name"
                    :placeholder="t('flows.settings.name_placeholder')"
                    required
                />
                <p v-if="nameError" class="text-sm text-destructive">
                    {{ nameError }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="flow-description">{{
                    t('flows.settings.description')
                }}</Label>
                <Textarea
                    id="flow-description"
                    v-model="description"
                    :placeholder="t('flows.settings.description_placeholder')"
                    class="min-h-[100px]"
                />
            </div>

            <div class="space-y-2">
                <Label for="flow-timezone">{{
                    t('flows.settings.timezone')
                }}</Label>
                <TimezoneCombobox
                    id="flow-timezone"
                    v-model="timezone"
                    :options="timezoneOptions"
                    :placeholder="t('flows.settings.timezone_placeholder')"
                    :search-placeholder="
                        t('flows.settings.timezone_search_placeholder')
                    "
                    :empty-label="t('flows.settings.timezone_no_results')"
                />
                <p v-if="timezoneError" class="text-sm text-destructive">
                    {{ timezoneError }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button :disabled="processing || !canSave" @click="$emit('save')">
                <Save class="size-4" />
                {{ t('flows.actions.save') }}
            </Button>

            <Button
                v-if="!isArchived"
                variant="outline"
                :disabled="
                    !canUpdate || hasActiveDeploys || actionInProgress !== null
                "
                @click="$emit('archive')"
            >
                <Archive class="size-4" />
                {{ t('actions.archive') }}
            </Button>

            <Button
                v-else
                variant="outline"
                :disabled="!canUpdate || actionInProgress !== null"
                @click="$emit('restore')"
            >
                <Archive class="size-4" />
                {{ t('actions.restore') }}
            </Button>

            <Button
                variant="outline"
                class="text-destructive"
                :disabled="!canDelete || hasActiveDeploys"
                @click="$emit('delete')"
            >
                <Trash2 class="size-4" />
                {{ t('actions.delete') }}
            </Button>

            <p class="text-xs text-muted-foreground">
                {{ t('flows.settings.delete_hint') }}
            </p>
        </div>
    </section>
</template>
