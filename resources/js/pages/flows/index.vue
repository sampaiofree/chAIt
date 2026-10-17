<script setup lang="ts">
import { Plus, Search, Workflow, Folder, Ellipsis } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { buttonVariants } from '@/components/ui/button';
import AppButton from '@/components/AppButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppDialog from '@/components/AppDialog.vue';
import { useAppDialog } from '@/composables/useAppDialog';

type FlowSummary = {
    id: number;
    folder_id: number | null;
    name: string;
    revision: number;
    updated_at: string;
    is_active: boolean;
    published_version_id: number | null;
};
type FlowFolder = {
    id: number;
    parent_id: number | null;
    name: string;
    flows_count: number;
};
type Breadcrumb = { id: number | null; name: string };
type FolderOption = FlowFolder & { depth: number };

const props = defineProps<{
    workspace: { id: number; name: string };
    current_folder_id: number | null;
    breadcrumbs: Breadcrumb[];
    folders: FlowFolder[];
    flows: FlowSummary[];
}>();

const query = ref('');
const creating = ref(false);
const activeMenu = ref<string | null>(null);
const activeDestination = ref<string | null>(null);

const { dialog, alert, confirm, prompt, finish } = useAppDialog();

const folderById = computed(
    () => new Map(props.folders.map((folder) => [folder.id, folder])),
);
const folderOptions = computed<FolderOption[]>(() => {
    const result: FolderOption[] = [];
    const visited = new Set<number>();
    const children = new Map<number | null, FlowFolder[]>();
    for (const folder of props.folders) {
        const siblings = children.get(folder.parent_id) ?? [];
        siblings.push(folder);
        children.set(folder.parent_id, siblings);
    }
    const stack: FolderOption[] = (children.get(null) ?? [])
        .sort((a, b) => b.name.localeCompare(a.name, 'pt-BR'))
        .map((folder) => ({ ...folder, depth: 0 }));
    while (stack.length) {
        const folder = stack.pop()!;
        if (visited.has(folder.id)) continue;
        visited.add(folder.id);
        result.push(folder);
        const next = (children.get(folder.id) ?? []).sort((a, b) =>
            b.name.localeCompare(a.name, 'pt-BR'),
        );
        stack.push(
            ...next.map((child) => ({ ...child, depth: folder.depth + 1 })),
        );
    }
    return result;
});
const visibleFolders = computed(() =>
    query.value.trim()
        ? []
        : props.folders.filter(
              (folder) => folder.parent_id === props.current_folder_id,
          ),
);
const visibleFlows = computed(() => {
    const term = query.value.trim().toLocaleLowerCase('pt-BR');
    return props.flows.filter((flow) =>
        term
            ? flow.name.toLocaleLowerCase('pt-BR').includes(term)
            : flow.folder_id === props.current_folder_id,
    );
});

function folderUrl(folderId: number | null): string {
    const base = '/workspaces/' + props.workspace.id + '/flows';
    return folderId === null ? base : base + '?folder_id=' + folderId;
}
function folderPath(folderId: number | null): string {
    const parts: string[] = [];
    const seen = new Set<number>();
    let cursor = folderId;
    while (cursor !== null && !seen.has(cursor)) {
        seen.add(cursor);
        const folder = folderById.value.get(cursor);
        if (!folder) break;
        parts.unshift(folder.name);
        cursor = folder.parent_id;
    }
    return ['Área principal', ...parts].join(' / ');
}
function isInside(candidateId: number, folderId: number): boolean {
    let cursor: number | null = candidateId;
    const seen = new Set<number>();
    while (cursor !== null && !seen.has(cursor)) {
        if (cursor === folderId) return true;
        seen.add(cursor);
        cursor = folderById.value.get(cursor)?.parent_id ?? null;
    }
    return false;
}
function folderDestinations(folder: FlowFolder): FolderOption[] {
    return folderOptions.value.filter(
        (option) => !isInside(option.id, folder.id),
    );
}
function closeMenus() {
    activeMenu.value = null;
    activeDestination.value = null;
}
function toggleMenu(key: string) {
    activeMenu.value = activeMenu.value === key ? null : key;
    activeDestination.value = null;
}
function toggleDestinations(key: string) {
    activeDestination.value = activeDestination.value === key ? null : key;
}
function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') closeMenus();
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

async function request(path: string, method: string, body: object = {}) {
    const response = await fetch(path, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content || '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok) {
        const detail = Object.values(result.errors ?? {})
            .flat()
            .find((value) => typeof value === 'string');
        throw new Error(
            typeof detail === 'string'
                ? detail
                : result.message || 'Não foi possível concluir a operação.',
        );
    }
    return result.data;
}
function reloadLibrary() {
    router.reload({
        only: ['folders', 'flows', 'breadcrumbs', 'current_folder_id'],
    });
}
async function createFlow() {
    closeMenus();
    const name = (await prompt('Nome do fluxo', 'Novo fluxo'))?.trim();
    if (!name) return;
    creating.value = true;
    try {
        const flow = await request(
            '/workspaces/' + props.workspace.id + '/flows',
            'POST',
            {
                name,
                folder_id: props.current_folder_id,
            },
        );
        window.location.href =
            '/workspaces/' + props.workspace.id + '/flows/' + flow.id;
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível criar o fluxo.',
        );
    } finally {
        creating.value = false;
    }
}
async function createFolder() {
    closeMenus();
    const name = (await prompt('Nova pasta', '', 'Nome da pasta'))?.trim();
    if (!name) return;
    try {
        const folder = await request(
            '/workspaces/' + props.workspace.id + '/flow-folders',
            'POST',
            {
                name,
                parent_id: props.current_folder_id,
            },
        );
        router.visit(folderUrl(folder.id));
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível criar a pasta.',
        );
    }
}
async function renameFolder(folder: FlowFolder) {
    closeMenus();
    const name = (await prompt('Renomear pasta', folder.name))?.trim();
    if (!name || name === folder.name) return;
    try {
        await request(
            '/workspaces/' + props.workspace.id + '/flow-folders/' + folder.id,
            'PATCH',
            { name },
        );
        reloadLibrary();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível renomear a pasta.',
        );
    }
}
async function moveFolder(folder: FlowFolder, parentId: number | null) {
    closeMenus();
    if (folder.parent_id === parentId) return;
    try {
        await request(
            '/workspaces/' + props.workspace.id + '/flow-folders/' + folder.id,
            'PATCH',
            {
                parent_id: parentId,
            },
        );
        reloadLibrary();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível mover a pasta.',
        );
    }
}
async function deleteFolder(folder: FlowFolder) {
    closeMenus();
    const subtree = props.folders.filter((item) =>
        isInside(item.id, folder.id),
    );
    const count = props.flows.filter(
        (flow) =>
            flow.folder_id !== null && isInside(flow.folder_id, folder.id),
    ).length;
    const subfolders = subtree.length - 1;
    const message =
        'Excluir a pasta “' +
        folder.name +
        '”' +
        (subfolders ? ' e ' + subfolders + ' subpasta(s)' : '') +
        '? ' +
        count +
        ' fluxo(s) serão movidos para Área principal.';
    if (!(await confirm(message, 'Excluir pasta'))) return;
    try {
        await request(
            '/workspaces/' + props.workspace.id + '/flow-folders/' + folder.id,
            'DELETE',
        );
        reloadLibrary();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível excluir a pasta.',
        );
    }
}
async function renameFlow(flow: FlowSummary) {
    closeMenus();
    const name = (await prompt('Renomear fluxo', flow.name))?.trim();
    if (!name || name === flow.name) return;
    try {
        await request(
            '/workspaces/' + props.workspace.id + '/flows/' + flow.id,
            'PATCH',
            { name },
        );
        reloadLibrary();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível renomear o fluxo.',
        );
    }
}
async function moveFlow(flow: FlowSummary, folderId: number | null) {
    closeMenus();
    if (flow.folder_id === folderId) return;
    try {
        await request(
            '/workspaces/' +
                props.workspace.id +
                '/flows/' +
                flow.id +
                '/folder',
            'PATCH',
            {
                folder_id: folderId,
            },
        );
        reloadLibrary();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível mover o fluxo.',
        );
    }
}
async function deleteFlow(flow: FlowSummary) {
    closeMenus();
    if (
        !(await confirm(
            'Excluir o fluxo “' +
                flow.name +
                '”? Esta ação não pode ser desfeita.',
            'Excluir fluxo',
        ))
    )
        return;
    try {
        await request(
            '/workspaces/' + props.workspace.id + '/flows/' + flow.id,
            'DELETE',
        );
        reloadLibrary();
    } catch (error) {
        await alert(
            error instanceof Error
                ? error.message
                : 'Não foi possível excluir o fluxo.',
        );
    }
}
</script>

<template>
    <Head title="Fluxos" />
    <main class="flows-page" @click="closeMenus">
        <section class="flows-content">
            <header class="page-header">
                <div>
                    <small>{{ workspace.name }}</small>
                    <h1>Fluxos</h1>
                    <p>Organize pastas e automações do workspace.</p>
                </div>
                <div class="header-actions">
                    <Link
                        :class="buttonVariants({ variant: 'outline' })"
                        :href="'/workspaces/' + workspace.id + '/flow-triggers'"
                        >Gatilhos WhatsApp</Link
                    >
                    <AppButton
                        type="button"
                        :disabled="creating"
                        @click="createFlow"
                        variant="primary"
                    >
                        <Plus :size="16" />{{
                            creating ? 'Criando…' : 'Novo fluxo'
                        }}
                    </AppButton>
                </div>
            </header>

            <nav class="breadcrumbs" aria-label="Caminho da pasta">
                <template
                    v-for="(crumb, index) in breadcrumbs"
                    :key="crumb.id ?? 'root'"
                >
                    <span v-if="index" aria-hidden="true">›</span>
                    <Link
                        :href="folderUrl(crumb.id)"
                        :aria-current="
                            index === breadcrumbs.length - 1
                                ? 'page'
                                : undefined
                        "
                    >
                        {{ crumb.name }}
                    </Link>
                </template>
            </nav>

            <div class="library-toolbar">
                <label class="flow-search">
                    <span aria-hidden="true"><Search :size="16" /></span>
                    <Input
                        v-model="query"
                        placeholder="Buscar fluxo pelo nome"
                    />
                </label>
                <AppButton
                    class="new-folder"
                    type="button"
                    @click="createFolder"
                    variant="secondary"
                >
                    <Plus :size="16" />Nova pasta
                </AppButton>
            </div>

            <section
                v-if="visibleFolders.length"
                class="library-section"
                aria-label="Pastas"
            >
                <h2>Pastas</h2>
                <div class="library-list">
                    <article
                        v-for="folder in visibleFolders"
                        :key="folder.id"
                        class="library-row"
                    >
                        <Link class="row-main" :href="folderUrl(folder.id)">
                            <span
                                class="item-icon folder-icon"
                                aria-hidden="true"
                            >
                                <Folder :size="20" />
                            </span>
                            <span class="item-info"
                                ><strong>{{ folder.name }}</strong
                                ><small
                                    >{{ folder.flows_count }} fluxo(s)</small
                                ></span
                            >
                        </Link>
                        <div class="action-wrap" @click.stop>
                            <button
                                class="menu-toggle"
                                type="button"
                                :aria-label="'Ações da pasta ' + folder.name"
                                aria-haspopup="menu"
                                :aria-expanded="
                                    activeMenu === 'folder:' + folder.id
                                "
                                @click="toggleMenu('folder:' + folder.id)"
                            >
                                <Ellipsis :size="20" />
                            </button>
                            <div
                                v-if="activeMenu === 'folder:' + folder.id"
                                class="action-menu"
                                role="menu"
                            >
                                <button
                                    type="button"
                                    role="menuitem"
                                    @click="renameFolder(folder)"
                                >
                                    Renomear
                                </button>
                                <button
                                    type="button"
                                    role="menuitem"
                                    :aria-expanded="
                                        activeDestination ===
                                        'folder:' + folder.id
                                    "
                                    @click="
                                        toggleDestinations(
                                            'folder:' + folder.id,
                                        )
                                    "
                                >
                                    Mover para <span>›</span>
                                </button>
                                <div
                                    v-if="
                                        activeDestination ===
                                        'folder:' + folder.id
                                    "
                                    class="destination-menu"
                                    role="group"
                                    aria-label="Destino da pasta"
                                >
                                    <button
                                        type="button"
                                        :disabled="folder.parent_id === null"
                                        @click="moveFolder(folder, null)"
                                    >
                                        Área principal
                                    </button>
                                    <button
                                        v-for="option in folderDestinations(
                                            folder,
                                        )"
                                        :key="option.id"
                                        type="button"
                                        :title="folderPath(option.id)"
                                        :disabled="
                                            folder.parent_id === option.id
                                        "
                                        :style="{
                                            paddingLeft:
                                                12 +
                                                Math.min(option.depth, 6) * 12 +
                                                'px',
                                        }"
                                        @click="moveFolder(folder, option.id)"
                                    >
                                        {{ option.name }}
                                    </button>
                                </div>
                                <button
                                    class="danger-action"
                                    type="button"
                                    role="menuitem"
                                    @click="deleteFolder(folder)"
                                >
                                    Excluir
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section
                v-if="visibleFlows.length"
                class="library-section"
                aria-label="Fluxos"
            >
                <h2>Fluxos</h2>
                <div class="library-list">
                    <article
                        v-for="flow in visibleFlows"
                        :key="flow.id"
                        class="library-row"
                    >
                        <Link
                            class="row-main"
                            :href="
                                '/workspaces/' +
                                workspace.id +
                                '/flows/' +
                                flow.id
                            "
                        >
                            <span class="item-icon flow-icon" aria-hidden="true"
                                ><Workflow :size="20"
                            /></span>
                            <span class="item-info">
                                <strong>{{ flow.name }}</strong>
                                <small>
                                    {{
                                        flow.is_active
                                            ? 'Ativo'
                                            : flow.published_version_id
                                              ? 'Inativo'
                                              : 'Rascunho'
                                    }}
                                    <template v-if="query.trim()">
                                        ·
                                        {{
                                            folderPath(flow.folder_id)
                                        }}</template
                                    >
                                    <template v-else>
                                        · atualizado em
                                        {{
                                            new Intl.DateTimeFormat('pt-BR', {
                                                dateStyle: 'short',
                                                timeStyle: 'short',
                                            }).format(new Date(flow.updated_at))
                                        }}</template
                                    >
                                </small>
                            </span>
                        </Link>
                        <span class="status-pill">{{
                            flow.is_active
                                ? 'Ativo'
                                : flow.published_version_id
                                  ? 'Inativo'
                                  : 'Rascunho'
                        }}</span>
                        <div class="action-wrap" @click.stop>
                            <button
                                class="menu-toggle"
                                type="button"
                                :aria-label="'Ações do fluxo ' + flow.name"
                                aria-haspopup="menu"
                                :aria-expanded="
                                    activeMenu === 'flow:' + flow.id
                                "
                                @click="toggleMenu('flow:' + flow.id)"
                            >
                                <Ellipsis :size="20" />
                            </button>
                            <div
                                v-if="activeMenu === 'flow:' + flow.id"
                                class="action-menu"
                                role="menu"
                            >
                                <button
                                    type="button"
                                    role="menuitem"
                                    @click="renameFlow(flow)"
                                >
                                    Renomear
                                </button>
                                <button
                                    type="button"
                                    role="menuitem"
                                    :aria-expanded="
                                        activeDestination === 'flow:' + flow.id
                                    "
                                    @click="
                                        toggleDestinations('flow:' + flow.id)
                                    "
                                >
                                    Mover para <span>›</span>
                                </button>
                                <div
                                    v-if="
                                        activeDestination === 'flow:' + flow.id
                                    "
                                    class="destination-menu"
                                    role="group"
                                    aria-label="Destino do fluxo"
                                >
                                    <button
                                        type="button"
                                        :disabled="flow.folder_id === null"
                                        @click="moveFlow(flow, null)"
                                    >
                                        Área principal
                                    </button>
                                    <button
                                        v-for="option in folderOptions"
                                        :key="option.id"
                                        type="button"
                                        :title="folderPath(option.id)"
                                        :disabled="flow.folder_id === option.id"
                                        :style="{
                                            paddingLeft:
                                                12 +
                                                Math.min(option.depth, 6) * 12 +
                                                'px',
                                        }"
                                        @click="moveFlow(flow, option.id)"
                                    >
                                        {{ option.name }}
                                    </button>
                                </div>
                                <button
                                    class="danger-action"
                                    type="button"
                                    role="menuitem"
                                    @click="deleteFlow(flow)"
                                >
                                    Excluir
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <div
                v-if="!visibleFolders.length && !visibleFlows.length"
                class="empty-library"
            >
                <span aria-hidden="true"><Workflow :size="20" /></span>
                <h2>
                    {{
                        query
                            ? 'Nenhum fluxo encontrado'
                            : 'Esta área está vazia'
                    }}
                </h2>
                <p>
                    {{
                        query
                            ? 'Tente outro nome para encontrar um fluxo.'
                            : 'Crie uma pasta ou um fluxo para começar.'
                    }}
                </p>
                <AppButton
                    v-if="!query"
                    type="button"
                    :disabled="creating"
                    @click="createFlow"
                    variant="primary"
                >
                    <Plus :size="16" />Novo fluxo
                </AppButton>
            </div>
        </section>
    </main>
    <AppDialog :dialog="dialog" @resolve="finish" />
</template>

<style scoped>
* {
    box-sizing: border-box;
}
.flows-page {
    min-height: 100%;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    background: var(--background);
    color: var(--foreground);
    font-family: var(--font-sans), sans-serif;
}

.flows-content {
    width: min(1050px, 100%);
    margin: 0 auto;
    padding: 46px 56px;
    min-width: 0;
}
.page-header,
.header-actions,
.library-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
}
.page-header {
    justify-content: space-between;
    flex-wrap: wrap;
}
.page-header small {
    color: var(--muted-foreground);
    font-size: 10px;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.page-header h1 {
    margin: 5px 0;
    font-size: 28px;
}
.page-header p {
    margin: 0;
    color: var(--muted-foreground);
    font-size: 13px;
}

.breadcrumbs {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 28px;
    color: var(--muted-foreground);
    font-size: 12px;
}
.breadcrumbs a {
    color: var(--muted-foreground);
    text-decoration: none;
}
.breadcrumbs a[aria-current='page'] {
    color: var(--foreground);
    font-weight: 700;
}
.library-toolbar {
    margin: 16px 0 24px;
}
.flow-search {
    display: flex;
    align-items: center;
    gap: 9px;
    flex: 1;
    height: 43px;
    padding: 0 13px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--card);
    color: var(--muted-foreground);
}
.flow-search .library-section {
    margin-top: 22px;
}
.library-section h2 {
    margin: 0 0 9px;
    font-size: 13px;
}
.library-list {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
}
.library-row {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 72px;
    gap: 12px;
    padding: 12px 15px;
    border-bottom: 1px solid var(--border);
}
.library-row:last-child {
    border-bottom: 0;
}
.row-main {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
    gap: 12px;
    color: var(--foreground);
    text-decoration: none;
}
.row-main:hover strong {
    text-decoration: underline;
}
.item-icon {
    display: grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    border-radius: 10px;
}
.item-icon svg {
    width: 22px;
    height: 22px;
}
.folder-icon {
    background: var(--warning-soft);
    color: var(--warning);
}
.flow-icon {
    background: var(--success-soft);
    color: var(--success);
    font-size: 18px;
}
.item-info {
    display: grid;
    gap: 5px;
    min-width: 0;
}
.item-info strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
}
.item-info small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--muted-foreground);
    font-size: 10px;
}
.status-pill {
    flex: none;
    padding: 5px 8px;
    border-radius: 20px;
    background: var(--warning-soft);
    color: var(--warning);
    font-size: 9px;
}
.action-wrap {
    position: relative;
    flex: none;
}
.menu-toggle {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    color: var(--muted-foreground);
    cursor: pointer;
}
.menu-toggle:hover,
.menu-toggle[aria-expanded='true'] {
    border-color: var(--border);
    background: var(--background);
    color: var(--foreground);
}
.menu-toggle svg {
    width: 17px;
    height: 17px;
}
.action-menu {
    position: absolute;
    z-index: 30;
    right: 0;
    top: calc(100% + 4px);
    width: min(235px, 80vw);
    padding: 5px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--card);
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.16);
}
.action-menu button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 9px 10px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--foreground);
    text-align: left;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
}
.action-menu button:hover:not(:disabled) {
    background: var(--background);
}
.action-menu button:disabled {
    opacity: 0.45;
    cursor: default;
}
.action-menu .danger-action {
    color: var(--destructive, #dc2626);
}
.destination-menu {
    max-height: 225px;
    overflow: auto;
    padding: 3px 0 4px;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.destination-menu button {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.empty-library {
    display: grid;
    justify-items: center;
    padding: 72px 20px;
    border: 1px dashed var(--border);
    border-radius: 13px;
    background: var(--card);
    text-align: center;
}
.empty-library > span {
    color: var(--success);
    font-size: 30px;
}
.empty-library h2 {
    margin: 10px 0 5px;
    font-size: 16px;
}
.empty-library p {
    margin: 0 0 17px;
    color: var(--muted-foreground);
    font-size: 12px;
}
@media (max-width: 700px) {
    .flows-content {
        padding: 28px 16px;
    }
    .page-header {
        align-items: flex-start;
    }
    .page-header h1 {
        font-size: 24px;
    }
    .header-actions {
        flex-wrap: wrap;
    }
    .library-row {
        padding: 10px;
    }
    .status-pill {
        display: none;
    }
    .library-toolbar {
        flex-wrap: wrap;
    }
}
</style>

<style scoped>
.page-header h1 {
    font-size: 24px;
    font-weight: 600;
}
.page-header p {
    font-size: 14px;
}
.library-section {
    margin-top: 24px;
}
.library-section > h2 {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 12px;
}
.item-info strong {
    font-size: 14px;
}
.item-info small {
    font-size: 12px;
}
.status-pill {
    font-size: 11px;
}
.flow-search {
    min-height: var(--control-height);
}
.flow-search input {
    border: 0;
    box-shadow: none;
    background: transparent;
    height: 100%;
    font-size: 14px;
    padding: 0;
}
.flow-search:focus-within {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.flow-search input:focus-visible {
    outline: 0;
    box-shadow: none;
}
.menu-toggle {
    min-width: var(--control-height);
    min-height: var(--control-height);
}
.action-menu button {
    min-height: var(--control-height);
    font-size: 13px;
}
.empty-library p {
    font-size: 14px;
}
</style>
