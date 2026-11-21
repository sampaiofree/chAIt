import { nextTick, reactive } from 'vue';

export type AppDialogMode = 'alert' | 'confirm' | 'prompt';
export type AppDialogResult = boolean | string | null;
type ConfirmVariant = 'primary' | 'danger';

export type AppDialogState = {
    open: boolean;
    mode: AppDialogMode;
    title: string;
    message: string;
    value: string;
    placeholder: string;
    confirmLabel: string;
    confirmVariant: ConfirmVariant;
    cancelLabel: string;
};

export function useAppDialog() {
    const dialog = reactive<AppDialogState>({
        open: false,
        mode: 'alert',
        title: 'Aviso',
        message: '',
        value: '',
        placeholder: '',
        confirmLabel: 'Entendi',
        confirmVariant: 'primary',
        cancelLabel: 'Cancelar',
    });
    type DialogOptions = {
        title?: string;
        value?: string;
        placeholder?: string;
        confirmLabel?: string;
        confirmVariant?: ConfirmVariant;
    };
    type DialogRequest = {
        mode: AppDialogMode;
        message: string;
        options: DialogOptions;
        resolve: (result: AppDialogResult) => void;
    };
    const queue: DialogRequest[] = [];
    let resolveDialog: ((result: AppDialogResult) => void) | null = null;

    function showNext() {
        if (resolveDialog || !queue.length) {
            return;
        }

        const request = queue.shift()!;
        dialog.mode = request.mode;
        dialog.title =
            request.options.title ||
            (request.mode === 'alert' ? 'Aviso' : 'Confirmar ação');
        dialog.message = request.message;
        dialog.value = request.options.value || '';
        dialog.placeholder = request.options.placeholder || '';
        dialog.confirmVariant = request.options.confirmVariant ?? 'primary';
        dialog.confirmLabel =
            request.options.confirmLabel ||
            (request.mode === 'alert' ? 'Entendi' : 'Confirmar');
        resolveDialog = request.resolve;
        dialog.open = true;
    }

    function ask(
        mode: AppDialogMode,
        message: string,
        options: DialogOptions = {},
    ): Promise<AppDialogResult> {
        return new Promise((resolve) => {
            queue.push({ mode, message, options, resolve });
            showNext();
        });
    }

    function finish(result: AppDialogResult) {
        if (!resolveDialog) {
            return;
        }

        const resolve = resolveDialog;
        resolveDialog = null;
        dialog.open = false;
        resolve(result);
        void nextTick().then(showNext);
    }

    return {
        dialog,
        alert: (message: string, title?: string) =>
            ask('alert', message, { title }).then(() => undefined),
        confirm: (
            message: string,
            title?: string,
            confirmVariant: ConfirmVariant = 'primary',
        ) =>
            ask('confirm', message, { title, confirmVariant }).then(
                (result) => result === true,
            ),
        prompt: (title: string, value = '', placeholder = '') =>
            ask('prompt', '', { title, value, placeholder }).then((result) =>
                typeof result === 'string' ? result : null,
            ),
        finish,
    };
}
