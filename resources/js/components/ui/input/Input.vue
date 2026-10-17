<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  defaultValue?: string | number
  modelValue?: string | number | null
  modelModifiers?: { number?: boolean; trim?: boolean }
  class?: HTMLAttributes["class"]
}>()

const emits = defineEmits<{
  (e: "update:modelValue", payload: string | number | null): void
}>()

const modelValue = computed({
    get: () => props.modelValue ?? props.defaultValue ?? '',
    set: (raw: string | number) => {
        let value = props.modelModifiers?.trim && typeof raw === 'string' ? raw.trim() : raw;
        if (props.modelModifiers?.number && typeof value === 'string') {
            const numeric = parseFloat(value);
            if (!Number.isNaN(numeric)) value = numeric;
        }
        emits('update:modelValue', value);
    },
});
</script>

<template>
  <input
    v-model="modelValue"
    data-slot="input"
    :class="cn(
      'text-foreground file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-10 w-full min-w-0 rounded-lg border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
      'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3',
      'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
      props.class,
    )"
  >
</template>
