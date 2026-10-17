<script setup lang="ts">
import {
    blockerNames,
    duration,
    sequenceStates,
    type SequenceSnapshot,
} from '@/lib/sequences';
defineProps<{ enrollment: SequenceSnapshot }>();
function blockers(reasons: string[]) {
    return reasons.map((reason) => blockerNames[reason] ?? reason).join(' · ');
}
function date(value: string, timezone: string) {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: timezone,
    }).format(new Date(value));
}
</script>
<template>
    <div class="sequence-status">
        <strong>{{ enrollment.name }}</strong>
        <p>{{ sequenceStates[enrollment.status] ?? enrollment.status }}</p>
        <p v-if="enrollment.flow_name">
            Etapa {{ (enrollment.step_position ?? 0) + 1 }} ·
            {{ enrollment.flow_name }}
        </p>
        <p v-if="enrollment.remaining_seconds !== null">
            {{ duration(enrollment.remaining_seconds)
            }}{{
                enrollment.blocked_reasons.length
                    ? ' restantes · contagem suspensa'
                    : ''
            }}
        </p>
        <p v-else-if="enrollment.due_at">
            {{ date(enrollment.due_at, enrollment.timezone) }} ·
            {{ enrollment.timezone }}
        </p>
        <p v-if="enrollment.blocked_reasons.length">
            {{ blockers(enrollment.blocked_reasons) }}
        </p>
        <p v-if="enrollment.status === 'failed'" role="alert">
            A execução falhou. Revise o fluxo antes de incluir novamente.
        </p>
        <small
            >Versão {{ enrollment.version }} · Atualizada em
            {{ date(enrollment.updated_at, enrollment.timezone) }}</small
        >
    </div>
</template>
<style scoped>
.sequence-status {
    display: grid;
    gap: 6px;
    overflow-wrap: anywhere;
    font-size: 13px;
}
p {
    color: var(--muted-foreground);
}
small {
    font-size: 12px;
    color: var(--muted-foreground);
}
p[role='alert'] {
    color: var(--danger);
}
</style>
