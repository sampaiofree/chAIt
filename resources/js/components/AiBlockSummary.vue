<script setup lang="ts">
defineProps<{
    pending: { message: string; field: string }[];
    readOnly?: boolean;
    model: string;
    history: boolean;
    mediaCount: number;
    sections: readonly { id: string; label: string }[];
    errors: Partial<Record<string, string[]>>;
}>();
defineEmits<{ select: [section: string]; correct: [field: string] }>();
</script>

<template>
    <section class="block-summary" aria-label="Resumo da configuração">
        <header>
            <h2>Configuração do bloco</h2>
            <slot name="actions" />
        </header>
        <p v-if="!pending.length">Nenhuma pendência identificada.</p>
        <ul v-else>
            <li v-for="{ message, field } in pending" :key="message">
                <button v-if="!readOnly" type="button" @click="$emit('correct', field)">{{ message }}</button>
                <span v-else>{{ message }}</span>
            </li>
        </ul>
        <template v-for="section in sections" :key="section.id">
            <button
                v-if="errors[section.id]?.length"
                type="button"
                @click="$emit('select', section.id)"
            >Corrigir {{ section.label }}<span>{{ errors[section.id]?.[0] }}</span></button>
        </template>
        <dl>
            <div><dt>Modelo</dt><dd>{{ model }}</dd></div>
            <div><dt>Histórico da conversa</dt><dd>{{ history ? 'Incluído' : 'Desativado' }}</dd></div>
            <div><dt>Mídias configuradas</dt><dd>{{ mediaCount }}</dd></div>
        </dl>
    </section>
</template>

<style scoped>
.block-summary {
    min-width: 0;
    padding: var(--card-padding);
    border: 1px solid var(--border);
    border-radius: var(--radius-card);
    background: var(--card);
}
header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 12px;
}
h2 {
    font-size: 16px;
    font-weight: 600;
}
p, li, dd {
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
ul {
    padding-left: 16px;
    list-style: disc;
}
dl {
    display: grid;
    gap: 12px;
    border-top: 1px solid var(--border);
    margin-top: 16px;
    padding-top: 16px;
    font-size: 13px;
}
dl div {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 4px 12px;
}
button {
    display: grid;
    gap: 4px;
    text-align: left;
    width: 100%;
    padding: 12px 0;
    color: var(--link);
    font-size: 13px;
}
button span {
    color: var(--danger);
}
</style>
