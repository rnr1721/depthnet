import { ref, computed } from 'vue';
import axios from 'axios';

/**
 * Composable for managing prompts of a single preset.
 * All operations are independent API calls — no dependency on the preset save form.
 *
 * @param {number|null} presetId  — ID of the preset (null for new presets, disables API calls)
 */
export function usePresetPrompts(presetId) {
    const prompts = ref([]);
    const activePromptId = ref(null);
    const isLoading = ref(false);
    const isSaving = ref({});   // { [promptId]: bool }
    const isDeleting = ref({});   // { [promptId]: bool }

    // ── Read ─────────────────────────────────────────────────────────────────

    async function loadPrompts() {
        if (!presetId) return;

        isLoading.value = true;
        try {
            const { data } = await axios.get(route('admin.presets.prompts.index', presetId));
            if (data.success) {
                prompts.value = data.data.prompts;
                activePromptId.value = data.data.active_prompt_id;
            }
        } catch (e) {
            console.error('usePresetPrompts: failed to load prompts', e);
            throw e;
        } finally {
            isLoading.value = false;
        }
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Save (create or update) a prompt.
     * If prompt has an id → PUT, otherwise → POST.
     */
    async function savePrompt(prompt) {
        const key = prompt.id ?? 'new';
        isSaving.value = { ...isSaving.value, [key]: true };

        try {
            let data;
            if (prompt.id) {
                const res = await axios.put(
                    route('admin.presets.prompts.update', { id: presetId, promptId: prompt.id }),
                    { code: prompt.code, content: prompt.content, description: prompt.description }
                );
                data = res.data;
            } else {
                const res = await axios.post(
                    route('admin.presets.prompts.store', presetId),
                    { code: prompt.code, content: prompt.content, description: prompt.description, set_as_active: false }
                );
                data = res.data;
                // Append new prompt to local list
                if (data.success) {
                    prompts.value.push(data.data.prompt);
                }
            }

            if (!data.success) throw new Error(data.message ?? 'Unknown error');

            // Update local copy
            if (prompt.id) {
                const idx = prompts.value.findIndex(p => p.id === prompt.id);
                if (idx !== -1) prompts.value[idx] = data.data.prompt;
            }

            return data.data.prompt;
        } finally {
            const updated = { ...isSaving.value };
            delete updated[key];
            isSaving.value = updated;
        }
    }

    /**
     * Delete a prompt.
     * After deletion the server may change active_prompt_id — we sync it back.
     */
    async function deletePrompt(promptId) {
        isDeleting.value = { ...isDeleting.value, [promptId]: true };

        try {
            const { data } = await axios.delete(
                route('admin.presets.prompts.destroy', { id: presetId, promptId })
            );
            if (!data.success) throw new Error(data.message ?? 'Unknown error');

            prompts.value = prompts.value.filter(p => p.id !== promptId);
            activePromptId.value = data.data.active_prompt_id;
        } finally {
            const updated = { ...isDeleting.value };
            delete updated[promptId];
            isDeleting.value = updated;
        }
    }

    /**
     * Set a prompt as active.
     */
    async function activatePrompt(promptId) {
        const { data } = await axios.patch(
            route('admin.presets.prompts.activate', { id: presetId, promptId })
        );
        if (!data.success) throw new Error(data.message ?? 'Unknown error');

        activePromptId.value = data.data.active_prompt_id;
    }

    /**
     * Duplicate a prompt.
     */
    async function duplicatePrompt(promptId) {
        const { data } = await axios.post(
            route('admin.presets.prompts.duplicate', { id: presetId, promptId })
        );
        if (!data.success) throw new Error(data.message ?? 'Unknown error');

        prompts.value.push(data.data.prompt);
        return data.data.prompt;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    const activePrompt = computed(() =>
        prompts.value.find(p => p.id === activePromptId.value) ?? null
    );

    const isLastPrompt = computed(() => prompts.value.length <= 1);

    return {
        prompts,
        activePromptId,
        activePrompt,
        isLastPrompt,
        isLoading,
        isSaving,
        isDeleting,
        loadPrompts,
        savePrompt,
        deletePrompt,
        activatePrompt,
        duplicatePrompt,
    };
}