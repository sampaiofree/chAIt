import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';

export const MAX_VOICE_SECONDS = 120;
export const MAX_VOICE_BYTES = 10 * 1024 * 1024;

export function useVoiceRecorder(
    enabled: Ref<boolean>,
    context: Ref<string | null>,
) {
    const state = ref<
        'idle' | 'requesting' | 'recording' | 'stopping' | 'preview'
    >('idle');
    const seconds = ref(0);
    const blob = shallowRef<Blob | null>(null);
    const previewUrl = ref('');
    const error = ref('');
    const active = computed(() => state.value !== 'idle');
    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let interval: ReturnType<typeof setInterval> | undefined;
    let deadline: ReturnType<typeof setTimeout> | undefined;
    let generation = 0;

    function clearTimers() {
        clearInterval(interval);
        clearTimeout(deadline);
    }

    function releaseMicrophone() {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    function discard() {
        generation++;
        clearTimers();

        if (recorder) {
            recorder.ondataavailable = null;
            recorder.onstop = null;
            recorder.onerror = null;

            if (recorder.state !== 'inactive') {
                recorder.stop();
            }
        }

        recorder = null;
        releaseMicrophone();

        if (previewUrl.value) {
            URL.revokeObjectURL(previewUrl.value);
        }

        previewUrl.value = '';
        blob.value = null;
        seconds.value = 0;
        state.value = 'idle';
        error.value = '';
    }

    function fail(message: string) {
        discard();
        error.value = message;
    }

    function stop() {
        if (state.value !== 'recording' || !recorder) {
            return;
        }

        state.value = 'stopping';
        clearTimers();
        recorder.stop();
        releaseMicrophone();
    }

    async function start() {
        if (!enabled.value || !context.value || active.value) {
            return;
        }

        error.value = '';

        if (
            !globalThis.isSecureContext ||
            !navigator.mediaDevices?.getUserMedia ||
            typeof MediaRecorder === 'undefined'
        ) {
            error.value =
                'A gravação exige HTTPS e um navegador com suporte a microfone.';

            return;
        }

        const mimeType = [
            'audio/webm;codecs=opus',
            'audio/ogg;codecs=opus',
            'audio/mp4;codecs=mp4a.40.2',
            'audio/mp4',
        ].find((mime) => MediaRecorder.isTypeSupported(mime));

        if (!mimeType) {
            error.value =
                'Este navegador não suporta os formatos de gravação disponíveis.';

            return;
        }

        const attempt = ++generation;
        state.value = 'requesting';

        try {
            const acquired = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });

            if (attempt !== generation || !enabled.value) {
                acquired.getTracks().forEach((track) => track.stop());

                return;
            }

            stream = acquired;
            const current = new MediaRecorder(acquired, {
                mimeType,
                audioBitsPerSecond: 64000,
            });
            recorder = current;
            const chunks: Blob[] = [];
            let bytes = 0;
            const startedAt = Date.now();
            current.ondataavailable = (event) => {
                if (attempt !== generation || !event.data.size) {
                    return;
                }

                bytes += event.data.size;

                if (bytes > MAX_VOICE_BYTES) {
                    fail(
                        'A gravação ultrapassou 10 MB. Grave uma mensagem menor.',
                    );

                    return;
                }

                chunks.push(event.data);
            };
            current.onstop = () => {
                if (attempt !== generation) {
                    return;
                }

                seconds.value = Math.min(
                    MAX_VOICE_SECONDS,
                    (Date.now() - startedAt) / 1000,
                );
                clearTimers();
                releaseMicrophone();
                const recording = new Blob(chunks, { type: current.mimeType });

                if (!recording.size || seconds.value < 1) {
                    fail(
                        'Grave pelo menos 1 segundo de áudio antes de enviar.',
                    );

                    return;
                }

                recorder = null;
                blob.value = recording;
                previewUrl.value = URL.createObjectURL(recording);
                state.value = 'preview';
            };
            current.onerror = () =>
                fail(
                    'A gravação foi interrompida. Verifique o microfone e tente novamente.',
                );
            current.start(1000);
            state.value = 'recording';
            interval = setInterval(() => {
                seconds.value = Math.min(
                    MAX_VOICE_SECONDS,
                    (Date.now() - startedAt) / 1000,
                );
            }, 100);
            deadline = setTimeout(stop, MAX_VOICE_SECONDS * 1000);
        } catch (cause) {
            if (attempt !== generation) {
                return;
            }

            const name = cause instanceof Error ? cause.name : '';
            fail(
                name === 'NotAllowedError'
                    ? 'Permissão de microfone negada. Autorize o microfone nas configurações do navegador.'
                    : name === 'NotFoundError'
                      ? 'Nenhum microfone foi encontrado neste dispositivo.'
                      : 'Não foi possível acessar o microfone. Verifique se ele está disponível.',
            );
        }
    }

    watch(
        [context, enabled],
        () => {
            if (active.value) {
                discard();
            }
        },
        { flush: 'sync' },
    );
    globalThis.addEventListener?.('pagehide', discard);
    onScopeDispose(() => {
        globalThis.removeEventListener?.('pagehide', discard);
        discard();
    });

    return {
        state,
        seconds,
        blob,
        previewUrl,
        error,
        active,
        start,
        stop,
        discard,
    };
}
