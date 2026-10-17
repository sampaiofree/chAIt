<script setup lang="ts">
import { ref } from 'vue';
import { Ellipsis, FileDown, FileUp, BookOpen } from '@lucide/vue';
import AppButton from '@/components/AppButton.vue';
import AppFormDialog from '@/components/AppFormDialog.vue';
import { Textarea } from '@/components/ui/textarea';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import type { AssistantState } from '@/composables/useFlowAssistant';

export type FlowImportResult = {
    state: AssistantState;
    tags: { id: number; name: string }[];
    fields: { id: number; name: string; type: string }[];
};
const props = defineProps<{
    endpoint: string;
    published: boolean;
    disabled: boolean;
    revisions: () => {
        base_revision: number;
        generation: string | null;
        draft_revision: number | null;
    };
    begin: () => Promise<boolean>;
    end: () => void;
    apply: (result: FlowImportResult) => Promise<void>;
}>();
const open = ref(false);
const exportOpen = ref(false);
const busy = ref(false);
const json = ref('');
const errors = ref<string[]>([]);
const notice = ref('');
const copied = ref(false);
const confirming = ref(false);
const source = ref<'draft' | 'published'>('draft');
let validated: {
    json: string;
    base_revision: number;
    generation: string | null;
    draft_revision: number | null;
} | null = null;
const limit = 5 * 1024 * 1024;
async function request(path: string, body?: unknown) {
    const response = await fetch(props.endpoint + path, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content || '',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) {
        const result = await response.json().catch(() => ({}));
        const details = Object.entries(result.errors || {}).flatMap(
            ([path, messages]) =>
                (messages as string[]).map((message) => `${path}: ${message}`),
        );
        throw new Error(
            details.join('\n') ||
                result.message ||
                'Não foi possível concluir a operação.',
        );
    }
    return response;
}
async function startImport() {
    notice.value = '';
    if (!(await props.begin())) {
        notice.value =
            'Pare ou aguarde a IA e resolva conflitos antes de importar.';
        return;
    }
    json.value = '';
    errors.value = [];
    copied.value = false;
    confirming.value = false;
    validated = null;
    open.value = true;
}
function closeImport(value: boolean) {
    if (value || busy.value) return;
    open.value = false;
    props.end();
}
async function chooseFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    busy.value = true;
    try {
        if (file.size > limit || !file.name.toLowerCase().endsWith('.json'))
            throw new Error('Selecione um arquivo .json de até 5 MiB.');
        json.value = await file.text();
        errors.value = [];
    } catch (error) {
        errors.value = [
            error instanceof Error
                ? error.message
                : 'Não foi possível ler o arquivo.',
        ];
    } finally {
        input.value = '';
        busy.value = false;
    }
}
async function validate() {
    errors.value = [];
    copied.value = false;
    if (new TextEncoder().encode(json.value).length > limit) {
        errors.value = ['O conteúdo deve ter no máximo 5 MiB.'];
        return;
    }
    busy.value = true;
    try {
        const payload = { json: json.value, ...props.revisions() };
        await request('/import/validate', payload);
        validated = payload;
        confirming.value = true;
    } catch (error) {
        errors.value = (
            error instanceof Error ? error.message : 'Falha ao validar.'
        ).split('\n');
    } finally {
        busy.value = false;
    }
}
async function importFlow() {
    if (!validated || busy.value) return;
    busy.value = true;
    try {
        const result = await (await request('/import', validated)).json();
        await props.apply(result.data);
        open.value = false;
        props.end();
        notice.value =
            'Fluxo importado e rascunho salvo. Revise as configurações antes de publicar.';
    } catch (error) {
        confirming.value = false;
        errors.value = (
            error instanceof Error ? error.message : 'Falha ao importar.'
        ).split('\n');
    } finally {
        busy.value = false;
    }
}
async function copyErrors() {
    try {
        await navigator.clipboard.writeText(
            'Erros de importação CHAIT (chait-flow 1.0):\n' +
                errors.value.join('\n'),
        );
        copied.value = true;
    } catch {
        notice.value =
            'Não foi possível copiar. Selecione e copie os erros exibidos.';
    }
}
async function download(path: string, fallback: string) {
    busy.value = true;
    notice.value = '';
    try {
        const response = await request(path);
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download =
            response.headers
                .get('content-disposition')
                ?.match(/filename="([^"]+)"/)?.[1] || fallback;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        exportOpen.value = false;
    } catch (error) {
        notice.value =
            error instanceof Error ? error.message : 'Falha ao baixar arquivo.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child
            ><AppButton
                variant="secondary"
                aria-label="Ações do fluxo"
                :disabled="busy"
                ><Ellipsis :size="18" /></AppButton
        ></DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem :disabled="disabled" @select="startImport"
                ><FileUp :size="16" />Importar JSON</DropdownMenuItem
            >
            <DropdownMenuItem
                @select="
                    source = 'draft';
                    exportOpen = true;
                "
                ><FileDown :size="16" />Exportar JSON</DropdownMenuItem
            >
            <DropdownMenuItem
                @select="download('/ai-guide', 'chait-guia-fluxos.md')"
                ><BookOpen :size="16" />Baixar guia para IA</DropdownMenuItem
            >
        </DropdownMenuContent>
    </DropdownMenu>
    <span v-if="notice" class="transfer-notice" role="status">{{
        notice
    }}</span>
    <AppFormDialog
        :open="open"
        :title="confirming ? 'Substituir rascunho?' : 'Importar JSON'"
        :description="
            confirming
                ? 'A importação substituirá todos os blocos, configurações e conexões do rascunho e da prévia compartilhada de toda a equipe. Essa ação não poderá ser desfeita. O plano anterior do assistente será invalidado. A versão publicada e os gatilhos não serão alterados.'
                : 'Cole o conteúdo ou selecione um arquivo .json de até 5 MiB.'
        "
        :busy="busy"
        @update:open="closeImport"
    >
        <form @submit.prevent="confirming ? importFlow() : validate()">
            <template v-if="!confirming">
                <label class="transfer-label"
                    >Arquivo JSON<input
                        type="file"
                        accept=".json,application/json"
                        :disabled="busy"
                        @change="chooseFile"
                /></label>
                <label class="transfer-label"
                    >Conteúdo JSON<Textarea
                        v-model="json"
                        aria-label="Conteúdo JSON"
                        rows="12"
                        :disabled="busy"
                        spellcheck="false"
                /></label>
                <div v-if="errors.length" class="transfer-errors" role="alert">
                    <p v-for="(error, index) in errors" :key="index">
                        {{ error }}
                    </p>
                    <AppButton variant="secondary" @click="copyErrors">{{
                        copied ? 'Erros copiados' : 'Copiar erros'
                    }}</AppButton>
                </div>
            </template>
            <div class="form-actions">
                <AppButton
                    variant="secondary"
                    :disabled="busy"
                    @click="closeImport(false)"
                    >Cancelar</AppButton
                ><AppButton
                    type="submit"
                    :loading="busy"
                    :disabled="!confirming && !json.trim()"
                    >{{
                        confirming ? 'Confirmar importação' : 'Validar JSON'
                    }}</AppButton
                >
            </div>
        </form>
    </AppFormDialog>
    <AppFormDialog
        v-model:open="exportOpen"
        title="Exportar JSON"
        description="O arquivo exportado pode conter mensagens e instruções internas do fluxo. Revise o conteúdo antes de compartilhá-lo."
        :busy="busy"
    >
        <form
            @submit.prevent="download('/export?source=' + source, 'fluxo.json')"
        >
            <p>Alterações não salvas da prévia não serão incluídas.</p>
            <label class="transfer-choice"
                ><input v-model="source" type="radio" value="draft" />Rascunho
                salvo</label
            >
            <label v-if="published" class="transfer-choice"
                ><input v-model="source" type="radio" value="published" />Versão
                publicada</label
            >
            <div class="form-actions">
                <AppButton
                    variant="secondary"
                    :disabled="busy"
                    @click="exportOpen = false"
                    >Cancelar</AppButton
                ><AppButton type="submit" :loading="busy"
                    >Baixar JSON</AppButton
                >
            </div>
        </form>
    </AppFormDialog>
</template>

<style scoped>
.transfer-label {
    display: grid;
    gap: 8px;
    margin: 16px 0;
    font-size: 13px;
}
.transfer-label textarea {
    min-height: min(220px, 30dvh);
    max-height: 45dvh;
    font-family: monospace;
}
.transfer-errors {
    max-height: 25dvh;
    overflow: auto;
    color: var(--destructive);
    font-size: 13px;
    overflow-wrap: anywhere;
}
.transfer-errors p {
    margin: 6px 0;
}
.transfer-notice {
    width: 100%;
    font-size: 12px;
    color: var(--muted-foreground);
    overflow-wrap: anywhere;
}
.transfer-choice {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 14px 0;
}
</style>
