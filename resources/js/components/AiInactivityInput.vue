<script setup lang="ts">
import { ref, watch } from 'vue';
import AppSelect from '@/components/AppSelect.vue';
import { Input } from '@/components/ui/input';

const model = defineModel<number | undefined>();
const units = [
    { label: 'Dias', seconds: 86400 },
    { label: 'Horas', seconds: 3600 },
    { label: 'Minutos', seconds: 60 },
    { label: 'Segundos', seconds: 1 },
];
const unit = ref(1);
const amount = ref('');
const invalid = ref(false);
function display(seconds: number) {
    return String(Number((seconds / unit.value).toFixed(8))).replace('.', ',');
}
const initial = model.value;
if (initial) {
    unit.value = units.find(({ seconds }) => initial % seconds === 0)?.seconds ?? 1;
    amount.value = display(initial);
}
watch(model, (value) => {
    if (!invalid.value && value && Math.round(Number(amount.value.replace(',', '.')) * unit.value) !== value)
        amount.value = display(value);
});
function update(value: string | number | null) {
    amount.value = String(value ?? '');
    const normalized = amount.value.trim().replace(',', '.');
    const seconds = Math.round(Number(normalized) * unit.value);
    invalid.value = !/^\d+(\.\d+)?$/.test(normalized) || seconds < 300 || seconds > 604800;
    if (!invalid.value) model.value = seconds;
}
function changeUnit() {
    if (model.value && !invalid.value) amount.value = display(model.value);
    else if (amount.value) update(amount.value);
}
</script>

<template>
    <div class="duration-fields">
        <Input :model-value="amount" aria-label="Tempo de inatividade" inputmode="decimal"
            :aria-invalid="invalid" aria-describedby="ai-inactivity-equivalent" @update:model-value="update" />
        <AppSelect v-model.number="unit" aria-label="Unidade de inatividade" @change="changeUnit">
            <option v-for="option in units" :key="option.seconds" :value="option.seconds">{{ option.label }}</option>
        </AppSelect>
        <small id="ai-inactivity-equivalent" :role="invalid ? 'alert' : undefined">
            {{ invalid ? 'Informe um prazo entre 5 minutos e 7 dias.' : model ? `${model.toLocaleString('pt-BR')} segundos` : 'Informe um prazo entre 5 minutos e 7 dias.' }}
        </small>
    </div>
</template>

<style scoped>
.duration-fields { display: grid; grid-template-columns: minmax(0, 1fr) minmax(96px, 1fr); gap: 8px; }
small { grid-column: 1 / -1; }
small[role='alert'] { color: var(--danger); }
</style>
