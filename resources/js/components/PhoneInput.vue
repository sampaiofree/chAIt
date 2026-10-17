<script setup lang="ts">
import { Input } from '@/components/ui/input';

defineProps<{ modelValue: string | null }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

function update(event: Event) {
    const input = event.target as HTMLInputElement;
    input.value = input.value.replace(/[^0-9]/g, '');
    emit('update:modelValue', input.value);
}
</script>

<template>
    <Input
        :model-value="modelValue"
        type="tel"
        inputmode="numeric"
        autocomplete="tel"
        pattern="[0-9]{1,15}"
        placeholder="5511999999999"
        @input="update"
    />
</template>
