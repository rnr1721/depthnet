<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('p_modal_handoff_logic') }}
            </h4>
        </div>

        <div class="space-y-6">
            <!-- Preset Code and Wait Time -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Preset Code -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_preset_code') }}
                    </label>
                    <input :value="modelValue.preset_code" @input="updateField('preset_code', $event.target.value)"
                        type="text" :class="inputClass" :placeholder="t('p_modal_preset_code_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_preset_code_desc') }}
                    </p>
                    <div v-if="errors.preset_code" class="text-red-500 text-xs mt-1">
                        {{ errors.preset_code }}
                    </div>
                </div>

                <!-- Before Execution Wait -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_before_execution_wait') }}
                    </label>
                    <input :value="modelValue.before_execution_wait"
                        @input="updateField('before_execution_wait', parseNumber($event.target.value))" type="number"
                        min="1" max="60" step="1" :class="inputClass"
                        :placeholder="t('p_modal_before_execution_wait_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_before_execution_wait_desc') }}
                    </p>
                    <div v-if="errors.before_execution_wait" class="text-red-500 text-xs mt-1">
                        {{ errors.before_execution_wait }}
                    </div>
                </div>
            </div>

            <!-- Auto-Handoff Settings -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Preset Code Next -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_preset_code_next') }}
                    </label>
                    <input :value="modelValue.preset_code_next"
                        @input="updateField('preset_code_next', $event.target.value)" type="text" :class="inputClass"
                        :placeholder="t('p_modal_preset_code_next_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_preset_code_next_desc') }}
                    </p>
                    <div v-if="errors.preset_code_next" class="text-red-500 text-xs mt-1">
                        {{ errors.preset_code_next }}
                    </div>
                </div>

                <!-- Error Behavior -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_error_behavior') }}
                    </label>
                    <select :value="modelValue.error_behavior"
                        @input="updateField('error_behavior', $event.target.value)" :class="inputClass">
                        <option value="stop">{{ t('p_modal_error_stop') }}</option>
                        <option value="continue">{{ t('p_modal_error_continue') }}</option>
                        <option value="fallback">{{ t('p_modal_error_fallback') }}</option>
                    </select>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_error_behavior_desc') }}
                    </p>
                </div>
            </div>

            <!-- Default Call Message -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_default_call_message') }}
                </label>
                <textarea :value="modelValue.default_call_message"
                    @input="updateField('default_call_message', $event.target.value)" rows="3" :class="inputClass"
                    :placeholder="t('p_modal_default_call_message_ph')"></textarea>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_default_call_message_desc') }}
                </p>
                <div v-if="errors.default_call_message" class="text-red-500 text-xs mt-1">
                    {{ errors.default_call_message }}
                </div>
            </div>

            <!-- Handoff Permissions -->
            <div>
                <h6 :class="['text-sm font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_handoff_permissions') }}
                </h6>
                <div class="flex flex-wrap gap-6">
                    <label
                        :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                        <input :checked="modelValue.allow_handoff_from"
                            @change="updateField('allow_handoff_from', $event.target.checked)" type="checkbox"
                            class="w-4 h-4 rounded text-indigo-600" />
                        <span class="text-sm">{{ t('p_modal_allow_handoff_from') }}</span>
                    </label>

                    <label
                        :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                        <input :checked="modelValue.allow_handoff_to"
                            @change="updateField('allow_handoff_to', $event.target.checked)" type="checkbox"
                            class="w-4 h-4 rounded text-indigo-600" />
                        <span class="text-sm">{{ t('p_modal_allow_handoff_to') }}</span>
                    </label>
                </div>
                <p :class="['text-xs mt-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_handoff_permissions_desc') }}
                </p>
            </div>

            <!-- Next Preset Select -->
            <div v-if="otherPresets.length > 0" class="border-t pt-4"
                :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_quick_handoff_setup') }}
                </label>
                <select :value="modelValue.preset_code_next ?? ''" @change="onNextPresetChange($event.target.value)"
                    :class="inputClass">
                    <option value="">{{ t('p_modal_not_selected') }}</option>
                    <option v-for="preset in otherPresets" :key="preset.id" :value="preset.preset_code ?? ''"
                        :disabled="!preset.preset_code">
                        {{ preset.name }}{{ !preset.preset_code ? ' — ' + t('p_modal_no_code') : '' }}
                    </option>
                </select>
            </div>

            <!-- Workflow Preview -->
            <div v-if="modelValue.preset_code_next" class="border-t pt-4"
                :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <h6 :class="['text-sm font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_workflow_preview') }}
                </h6>
                <div class="flex items-center space-x-2 text-sm">
                    <div
                        :class="['px-3 py-2 rounded-lg bg-opacity-50', isDark ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-800']">
                        {{ modelValue.preset_code || t('p_modal_this_preset') }}
                    </div>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    <div
                        :class="['px-3 py-2 rounded-lg bg-opacity-50', isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800']">
                        {{ modelValue.preset_code_next }}
                    </div>
                </div>
                <p :class="['text-xs mt-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_auto_handoff_after_execution') }}
                </p>
            </div>

            <!-- Cycle Inner Voice -->
            <div class="border-t pt-4" :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_cycle_prompt_preset') }}
                </label>
                <select :value="modelValue.cycle_prompt_preset_id"
                    @input="updateField('cycle_prompt_preset_id', $event.target.value ? parseInt($event.target.value) : null)"
                    :class="inputClass">
                    <option :value="null">{{ t('p_modal_none') }}</option>
                    <option v-for="preset in availablePresets" :key="preset.id" :value="preset.id">
                        {{ preset.name }}
                    </option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_cycle_prompt_preset_desc') }}
                </p>
                <div v-if="errors.cycle_prompt_preset_id" class="text-red-500 text-xs mt-1">
                    {{ errors.cycle_prompt_preset_id }}
                </div>
            </div>

            <!-- Cycle prompt context limit -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_cp_context_limit') }}
                </label>
                <input :value="modelValue.cp_context_limit ?? 5"
                    @input="updateField('cp_context_limit', parseNumber($event.target.value))" type="number" min="4"
                    max="20" step="1" :class="inputClass" :placeholder="t('p_modal_cp_context_limit_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_cp_context_limit_desc') }}
                </p>
                <div v-if="errors.cp_context_limit" class="text-red-500 text-xs mt-1">
                    {{ errors.cp_context_limit }}
                </div>
            </div>

            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_voice_mp_commands') }}
                </label>
                <input :value="modelValue.voice_mp_commands"
                    @input="updateField('voice_mp_commands', $event.target.value)" type="text" :class="inputClass"
                    :placeholder="t('p_modal_voice_mp_commands_ph')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_voice_mp_commands_desc') }}
                </p>
                <div v-if="errors.voice_mp_commands" class="text-red-500 text-xs mt-1">
                    {{ errors.voice_mp_commands }}
                </div>
            </div>

            <!-- Spawn info — only for spawned presets -->
            <div v-if="modelValue.is_spawned" class="border-t pt-4"
                :class="isDark ? 'border-gray-600' : 'border-gray-200'">

                <h6
                    :class="['text-sm font-medium mb-3 flex items-center gap-2', isDark ? 'text-white' : 'text-gray-900']">
                    <!-- иконка "инструмент" -->
                    <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{ t('p_modal_spawn_info') }}
                </h6>

                <div class="space-y-3">
                    <!-- Parent preset -->
                    <div>
                        <label
                            :class="['block text-xs font-medium mb-1 opacity-70', isDark ? 'text-white' : 'text-gray-700']">
                            {{ t('p_modal_spawn_parent') }}
                        </label>
                        <div :class="[
                            'px-3 py-2 rounded-lg text-sm flex items-center gap-2',
                            isDark ? 'bg-gray-600 text-gray-200' : 'bg-gray-100 text-gray-700'
                        ]">
                            <svg class="w-4 h-4 opacity-50 flex-shrink-0" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18" />
                            </svg>
                            <span>{{ parentPresetName }}</span>
                        </div>
                    </div>

                    <!-- Lifecycle hint -->
                    <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_spawn_lifecycle_hint') }}
                    </p>
                </div>
            </div>


        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Object,
        required: true
    },
    isDark: {
        type: Boolean,
        default: false
    },
    errors: {
        type: Object,
        default: () => ({})
    },
    availablePresets: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:modelValue', 'success', 'error']);

const showRagHint = ref(false);
const copied = ref(false);

const ragSystemPrompt = `You are a search query formulator.
Given the conversation below, output ONLY a short
keyword-rich search query (maximum 15 words,
no punctuation, no explanation).
Output nothing else.`;

const copyPrompt = async () => {
    try {
        await navigator.clipboard.writeText(ragSystemPrompt);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        copied.value = false;
    }
};

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const updateField = (field, value) => {
    const updated = { ...props.modelValue, [field]: value };
    emit('update:modelValue', updated);
};

const parseNumber = (value) => {
    const num = parseInt(value);
    return isNaN(num) ? 5 : num;
};

const setNextPreset = (preset) => {
    if (preset.preset_code && preset.preset_code !== props.modelValue.preset_code) {
        const updated = { ...props.modelValue, preset_code_next: preset.preset_code };
        if (!props.modelValue.default_call_message) {
            updated.default_call_message = preset.description || `Continue with ${preset.name}`;
        }
        emit('update:modelValue', updated);
        emit('success', t('p_modal_next_preset_set', { name: preset.name }));
    }
};

const onNextPresetChange = (presetCode) => {
    const updated = { ...props.modelValue, preset_code_next: presetCode || '' };
    if (presetCode && !props.modelValue.default_call_message) {
        const preset = props.availablePresets.find(p => p.preset_code === presetCode);
        if (preset) updated.default_call_message = preset.description || `Continue with ${preset.name}`;
    }
    emit('update:modelValue', updated);
};

const otherPresets = computed(() =>
    props.availablePresets.filter(p => p.id !== props.modelValue.id)
);

// Find the name of the parent preset by its ID
const parentPresetName = computed(() => {
    if (!props.modelValue.parent_preset_id) return '—';
    const parent = props.availablePresets.find(
        p => p.id === props.modelValue.parent_preset_id
    );
    return parent ? parent.name : `#${props.modelValue.parent_preset_id}`;
});


const onRagPresetChange = (value) => {
    updateField('rag_preset_id', value ? parseInt(value) : null);
};

</script>