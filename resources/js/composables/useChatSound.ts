import axios from 'axios';
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

/**
 * Som do chat (RF-31). A preferência é por usuário e fica no servidor; silenciar nunca interrompe o recebimento
 * das mensagens. O navegador pode bloquear áudio sem interação: nesse caso `blocked` avisa a pessoa, e o aviso
 * visual continua sendo o principal (o som nunca é o único meio de comunicar um evento).
 */

let context: AudioContext | null = null;
const blocked = ref(false);
let unlockRegistered = false;

const audioContext = (): AudioContext | null => {
    if (context) return context;

    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!Ctor) return null;

    return (context = new Ctor());
};

/** Qualquer clique ou tecla é uma interação válida: libera o áudio e limpa o aviso. */
const registerUnlock = () => {
    if (unlockRegistered) return;
    unlockRegistered = true;

    const unlock = async () => {
        const ctx = audioContext();
        if (!ctx) return;

        await ctx.resume().catch(() => undefined);
        if (ctx.state === 'running') {
            blocked.value = false;
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
            unlockRegistered = false;
        }
    };

    window.addEventListener('pointerdown', unlock);
    window.addEventListener('keydown', unlock);
};

/** Dois toques curtos. Devolve se o som tocou; falso quando o navegador bloqueou ou não há suporte. */
const beep = async (): Promise<boolean> => {
    const ctx = audioContext();
    if (!ctx) return false;

    if (ctx.state === 'suspended') await ctx.resume().catch(() => undefined);

    if (ctx.state !== 'running') {
        blocked.value = true;
        registerUnlock();

        return false;
    }

    const start = ctx.currentTime;
    [660, 880].forEach((frequency, index) => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        const at = start + index * 0.16;

        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(0.15, at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.14);
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start(at);
        oscillator.stop(at + 0.15);
    });

    blocked.value = false;

    return true;
};

export function useChatSound() {
    const page = usePage();
    const toast = useToast();
    const enabled = ref(page.props.auth.user.chat_sound_enabled !== false);
    const saving = ref(false);

    /** Toca só se a pessoa não silenciou. */
    const notify = async () => {
        if (enabled.value) await beep();
    };

    /** Teste do som (RF-31): toca mesmo silenciado, para a pessoa saber como é. */
    const test = async () => {
        if (!(await beep())) toast.warning('O navegador bloqueou o som. Clique em qualquer lugar da página para ativá-lo e teste de novo.');
    };

    const setEnabled = async (value: boolean) => {
        const previous = enabled.value;
        enabled.value = value;
        saving.value = true;

        try {
            await axios.put(route('chat.preferences.update'), { sound_enabled: value });
        } catch {
            enabled.value = previous;
            toast.unexpected();
        } finally {
            saving.value = false;
        }
    };

    return { enabled, saving, blocked, notify, test, setEnabled };
}
