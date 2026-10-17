<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { LoaderCircle } from '@lucide/vue';
withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        loading?: boolean;
        size?: 'default' | 'sm' | 'icon';
    }>(),
    {
        variant: 'primary',
        type: 'button',
        disabled: false,
        loading: false,
        size: 'default',
    },
);
</script>
<template>
    <Button
        :type="type"
        :variant="
            variant === 'primary'
                ? 'default'
                : variant === 'danger'
                  ? 'destructive'
                  : variant === 'secondary'
                    ? 'outline'
                    : 'ghost'
        "
        :size="size"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        ><LoaderCircle v-if="loading" class="animate-spin" :size="16" /><slot
    /></Button>
</template>
