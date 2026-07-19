import { ref } from 'vue';
import axios from 'axios';

/**
 * Fetches the preset's voice capability config and builds the transport
 * functions useVoice needs for server-side providers.
 *
 * Kept separate from useVoice so that composable stays about speech mechanics
 * and knows nothing about routes or axios — which also makes it testable without
 * a backend.
 */
export function useVoiceConfig() {
    const voiceConfig = ref(null);
    const isLoading = ref(false);
    const loadError = ref(null);

    /**
     * Load config for a preset and return the object to hand to applyConfig().
     * Returns a null-provider shape on failure rather than throwing — voice is
     * an enhancement, and a failed fetch should disable it quietly, not break
     * the chat.
     */
    async function loadVoiceConfig(presetId) {
        isLoading.value = true;
        loadError.value = null;

        try {
            const { data } = await axios.get(route('voice.config'), {
                params: { preset_id: presetId },
            });

            voiceConfig.value = data;

            return {
                stt: data.stt,
                tts: data.tts,
                transcribeFn: data.stt?.execution_mode === 'server'
                    ? (blob) => transcribe(blob, presetId, data.stt?.language)
                    : null,
                synthesizeFn: data.tts?.execution_mode === 'server'
                    ? (text) => synthesize(text, presetId)
                    : null,
            };

        } catch (e) {
            loadError.value = e;
            console.warn('[useVoiceConfig] failed to load voice config:', e);
            voiceConfig.value = null;
            return { stt: null, tts: null, transcribeFn: null, synthesizeFn: null };
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * POST a recording, get text back.
     * The filename matters — the backend maps the extension to a decoder.
     */
    async function transcribe(blob, presetId, language) {
        const form = new FormData();
        form.append('audio', blob, 'recording.webm');
        form.append('preset_id', presetId);
        if (language) form.append('language', language);

        const { data } = await axios.post(route('voice.transcribe'), form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return data.text || '';
    }

    /**
     * POST text, get an audio blob back.
     * responseType 'blob' is essential — axios would otherwise try to parse the
     * binary body as text and corrupt it.
     */
    async function synthesize(text, presetId) {
        const { data } = await axios.post(
            route('voice.speak'),
            { text, preset_id: presetId },
            { responseType: 'blob' },
        );

        return data;
    }

    return { voiceConfig, isLoading, loadError, loadVoiceConfig };
}
