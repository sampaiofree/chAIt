<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import SequenceStatus from '@/components/SequenceStatus.vue';
import {
    sequenceRequest,
    SequenceRequestError,
    type SequenceSnapshot,
} from '@/lib/sequences';
const props = defineProps<{ workspaceId: number; contactId: number }>();
const enrollment = ref<SequenceSnapshot | null>(null);
const sequences = ref<{ id: number; name: string }[]>([]);
const canEnroll = ref(false);
const loading = ref(true);
const loadError = ref('');
const formError = ref('');
const notice = ref('');
const open = ref(false);
const selected = ref<number | null>(null);
const busy = ref(false);
const key = ref('');
const abort = new AbortController();
let timer: ReturnType<typeof setInterval>;
let fetching = false;
const endpoint = `/workspaces/${props.workspaceId}/contacts/${props.contactId}/sequence-enrollment`;
async function load() {
    if (fetching || busy.value || open.value) return;
    fetching = true;
    try {
        const result = await sequenceRequest<{
            data: SequenceSnapshot | null;
            sequences: { id: number; name: string }[];
            can_enroll: boolean;
        }>(endpoint, 'GET', undefined, abort.signal);
        enrollment.value = result.data;
        sequences.value = result.sequences;
        canEnroll.value = result.can_enroll;
        loadError.value = '';
    } catch (e) {
        if (!abort.signal.aborted)
            loadError.value =
                e instanceof Error
                    ? e.message
                    : 'Não foi possível consultar a sequência.';
    } finally {
        loading.value = false;
        fetching = false;
    }
}
watch(open, (value) => {
    formError.value = '';
    if (value) {
        selected.value = null;
        key.value = crypto.randomUUID();
    }
});
watch(selected, () => {
    key.value = crypto.randomUUID();
});
async function enroll() {
    if (busy.value) return;
    busy.value = true;
    formError.value = '';
    try {
        const result = await sequenceRequest<{
            data: SequenceSnapshot;
            result: string;
        }>(
            endpoint,
            'POST',
            { sequence_id: selected.value, idempotency_key: key.value },
            abort.signal,
        );
        enrollment.value = result.data;
        open.value = false;
        notice.value =
            result.result === 'already_enrolled'
                ? 'O contato já está nesta sequência. A programação foi preservada.'
                : 'Contato incluído na sequência.';
    } catch (e) {
        formError.value =
            e instanceof SequenceRequestError
                ? e.fields.sequence_id || e.message
                : 'Falha ao incluir. Tente novamente.';
    } finally {
        busy.value = false;
    }
}
onMounted(() => {
    void load();
    timer = setInterval(() => void load(), 15000);
});
onBeforeUnmount(() => {
    abort.abort();
    clearInterval(timer);
});
</script>
<template>
    <section class="contact-sequence" aria-label="Sequência do contato">
        <h3>Sequência</h3>
        <p v-if="loading">Consultando sequência…</p>
        <template v-else-if="loadError"
            ><p role="alert">{{ loadError }}</p>
            <AppButton variant="ghost" @click="load"
                >Tentar novamente</AppButton
            ></template
        >
        <template v-else
            ><SequenceStatus v-if="enrollment" :enrollment="enrollment" />
            <p v-else>Nenhuma sequência vigente.</p>
            <p v-if="notice" role="status">{{ notice }}</p>
            <AppButton
                v-if="canEnroll"
                variant="secondary"
                :disabled="!sequences.length"
                @click="open = true"
                >Adicionar à sequência</AppButton
            >
            <p v-if="canEnroll && !sequences.length">
                Publique uma sequência para incluir este contato.
            </p></template
        >
        <AppFormDialog
            v-model:open="open"
            title="Adicionar à sequência"
            description="Escolha a programação para este contato."
            :busy="busy"
            :dirty="selected !== null"
            v-slot="{ close }"
            ><form @submit.prevent="enroll">
                <p
                    v-if="
                        enrollment &&
                        !['completed', 'failed', 'replaced'].includes(
                            enrollment.status,
                        )
                    "
                    class="replacement"
                >
                    A sequência atual será encerrada. O fluxo em andamento
                    continuará até terminar; a nova sequência começará depois.
                    Escolher a mesma sequência preserva a programação.
                </p>
                <div class="form-field">
                    <label for="contact-sequence-choice"
                        >Sequência publicada</label
                    ><AppSelect
                        id="contact-sequence-choice"
                        v-model="selected"
                        required
                        :aria-invalid="Boolean(formError)"
                        aria-describedby="sequence-enroll-error"
                        ><option :value="null">Selecionar sequência</option>
                        <option
                            v-for="sequence in sequences"
                            :key="sequence.id"
                            :value="sequence.id"
                        >
                            {{ sequence.name }}
                        </option></AppSelect
                    >
                    <p
                        v-if="formError"
                        id="sequence-enroll-error"
                        class="form-error"
                        role="alert"
                    >
                        {{ formError }}
                    </p>
                </div>
                <div class="form-actions">
                    <AppButton
                        variant="secondary"
                        :disabled="busy"
                        @click="close()"
                        >Cancelar</AppButton
                    ><AppButton
                        type="submit"
                        :loading="busy"
                        :disabled="selected === null"
                        >Adicionar à sequência</AppButton
                    >
                </div>
            </form></AppFormDialog
        >
    </section>
</template>
<style scoped>
.contact-sequence {
    display: grid;
    gap: 12px;
    padding: 20px 0;
    border-top: 1px solid var(--border);
    min-width: 0;
}
h3 {
    font-size: 14px;
    font-weight: 600;
}
p {
    color: var(--muted-foreground);
    font-size: 13px;
    overflow-wrap: anywhere;
}
.replacement {
    margin-bottom: 16px;
    line-height: 1.6;
}
</style>
