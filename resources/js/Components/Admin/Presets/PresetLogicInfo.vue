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

            <!-- Quick Setup Presets -->
            <div v-if="availablePresets && availablePresets.length > 0" class="border-t pt-4"
                :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <h6 :class="['text-sm font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_quick_handoff_setup') }}
                </h6>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                    <button v-for="preset in availablePresets" :key="preset.id" type="button"
                        @click="setNextPreset(preset)" :class="[
                            'px-3 py-2 rounded-lg text-xs font-medium transition-all hover:scale-105 text-left',
                            preset.preset_code === modelValue.preset_code_next
                                ? (isDark ? 'bg-indigo-900 bg-opacity-70 text-indigo-200 ring-1 ring-indigo-500' : 'bg-indigo-100 text-indigo-800 ring-1 ring-indigo-300')
                                : (isDark ? 'bg-gray-600 text-gray-200 hover:bg-gray-500' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')
                        ]" :disabled="preset.preset_code === modelValue.preset_code"
                        :title="preset.description || preset.name">
                        <div class="truncate">{{ preset.name }}</div>
                        <div v-if="preset.preset_code"
                            :class="['text-xs mt-1 truncate', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ preset.preset_code }}
                        </div>
                    </button>
                </div>
                <p :class="['text-xs mt-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_click_to_set_next_preset') }}
                </p>
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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
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
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
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
        updateField('preset_code_next', preset.preset_code);

        // Set default message if empty
        if (!props.modelValue.default_call_message) {
            const defaultMessage = preset.description || `Continue with ${preset.name}`;
            updateField('default_call_message', defaultMessage);
        }

        emit('success', t('p_modal_next_preset_set', { name: preset.name }));
    }
};
</script>