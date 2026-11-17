<script setup lang="ts">
import { computed, ref } from 'vue';
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
const props = defineProps<{
    tags: { id: number; name: string; color?: string }[];
    disabled?: boolean;
}>();
const model = defineModel<number[]>({ default: () => [] });
const search = ref('');
const filtered = computed(() =>
    props.tags.filter((tag) =>
        tag.name.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()),
    ),
);
function toggle(id: number, checked: boolean) {
    model.value = checked
        ? [...new Set([...model.value, id])]
        : model.value.filter((value) => value !== id);
}
</script>
<template>
    <div class="lead-tag-picker">
        <div class="lead-search">
            <Search :size="16" aria-hidden="true" /><Input
                v-model="search"
                type="search"
                placeholder="Buscar etiquetas"
                aria-label="Buscar etiquetas"
                :disabled="disabled"
            />
        </div>
        <div class="lead-tag-options">
            <label v-for="tag in filtered" :key="tag.id" class="form-checkbox"
                ><Checkbox
                    :model-value="model.includes(tag.id)"
                    :disabled="disabled"
                    @update:model-value="toggle(tag.id, $event === true)"
                /><span
                    class="lead-tag-dot"
                    :style="{
                        backgroundColor: tag.color || 'var(--muted-foreground)',
                    }"
                />{{ tag.name }}</label
            >
            <p v-if="!filtered.length" class="form-help">
                {{
                    tags.length
                        ? 'Nenhuma etiqueta encontrada.'
                        : 'Nenhuma etiqueta cadastrada.'
                }}
            </p>
        </div>
    </div>
</template>
