<script setup lang="ts">
import AppFieldHelp from '@/components/AppFieldHelp.vue';
defineProps<{ label: string }>();
</script>
<template>
    <AppFieldHelp :label="label"><slot /></AppFieldHelp>
</template>
