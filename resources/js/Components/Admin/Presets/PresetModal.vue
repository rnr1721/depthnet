<template>
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div :class="[
            'w-full max-w-5xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl',
            isDark ? 'bg-gray-800' : 'bg-white'
        ]" @click.stop>
            <!-- Header -->
            <div
                :class="['px-6 py-4 border-b flex items-center justify-between', isDark ? 'border-gray-700' : 'border-gray-200']">
                <h3 :class="['text-xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                    {{ preset ? t('p_modal_edit_preset') : t('p_modal_create_preset') }}
                </h3>
                <button @click="$emit('close')"
                    :class="['p-2 rounded-xl transition-all hover:scale-105', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Success/Error Messages -->
            <Transition enter-active-class="transition ease-out duration-300"
                enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-200" leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95">
                <div v-if="notification.message" :class="[
                    'mx-6 mt-4 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                    notification.type === 'success'
                        ? (isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800')
                        : (isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800')
                ]">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg v-if="notification.type === 'success'" class="w-5 h-5 mr-3 flex-shrink-0"
                                fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <svg v-else class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ notification.message }}</span>
                        </div>
                        <button @click="clearNotification" class="ml-4 opacity-70 hover:opacity-100">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                    clip-rule="evenodd"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Body -->
            <form @submit.prevent="submit" class="p-6 space-y-6">
                <!-- Basic Info Component -->
                <PresetBasicInfo v-model="form" :engines="engines" :is-dark="isDark" :errors="errors"
                    :hide-default-option="preset?.is_default" @engine-changed="onEngineChange" />

                <!-- System Prompt Component -->
                <PresetSystemPrompt v-model="form" :is-dark="isDark" :errors="errors" :placeholders="placeholders"
                    :default-system-prompt="currentEngineData?.default_system_prompt" @success="showNotification"
                    @error="showError" />

                <!-- Sandbox Management Component -->
                <PresetSandboxManager :preset="preset" :is-dark="isDark" @error="showError"
                    @success="showNotification" />

                <!-- Agent Settings Component -->
                <PresetAgentSettings v-model="form" :is-dark="isDark" :errors="errors"
                    :available-plugins="availablePlugins" />

                <!-- Logic & Handoff Component -->
                <PresetLogicInfo v-model="form" :is-dark="isDark" :errors="errors" :available-presets="availablePresets"
                    @success="showNotification" @error="showError" />

                <!-- Engine Config Component -->
                <PresetEngineConfig v-model="form" :engine-name="form.engine_name" :engines="engines" :is-dark="isDark"
                    :errors="errors" @validation-errors="onValidationErrors" @success="showNotification"
                    @error="showError" />

                <!-- Actions -->
                <div class="flex justify-end space-x-4 pt-6 border-t"
                    :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                    <button type="button" @click="$emit('close')" :class="buttonSecondaryClass">
                        {{ t('p_modal_cancel') }}
                    </button>
                    <button type="submit" :disabled="!isFormValid || isSubmitting" :class="buttonPrimaryClass">
                        <svg v-if="isSubmitting" class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                        {{ isSubmitting ? t('p_modal_saving') : (preset ? t('p_modal_save') : t('p_modal_create')) }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import PresetBasicInfo from './PresetBasicInfo.vue';
import PresetSystemPrompt from './PresetSystemPrompt.vue';
import PresetSandboxManager from './PresetSandboxManager.vue';
import PresetLogicInfo from './PresetLogicInfo.vue';
import PresetAgentSettings from './PresetAgentSettings.vue';
import PresetEngineConfig from './PresetEngineConfig.vue';

const { t } = useI18n();

const props = defineProps({
    preset: Object,
    engines: Object,
    placeholders: Object,
    availablePlugins: {
        type: Array,
        default: () => []
    },
    availablePresets: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['close', 'save']);

// State
const isDark = ref(false);
const errors = ref({});
const validationErrors = ref([]);
const isSubmitting = ref(false);
const notification = ref({ message: '', type: 'success' });

// Form data
const form = ref({
    id: props.preset?.id || null,
    name: props.preset?.name || '',
    description: props.preset?.description || '',
    engine_name: props.preset?.engine_name || '',
    system_prompt: props.preset?.system_prompt || '',
    preset_code: props.preset?.preset_code || '',
    preset_code_next: props.preset?.preset_code_next || '',
    default_call_message: props.preset?.default_call_message || '',
    before_execution_wait: props.preset?.before_execution_wait || 5,
    plugins_disabled: props.preset?.plugins_disabled || '',
    engine_config: props.preset?.engine_config || {},
    loop_interval: props.preset?.loop_interval || 15,
    max_context_limit: props.preset?.max_context_limit || 8,
    agent_result_mode: props.preset?.agent_result_mode || 'separate',
    error_behavior: props.preset?.error_behavior || 'stop',
    allow_handoff_to: props.preset?.allow_handoff_to ?? true,
    allow_handoff_from: props.preset?.allow_handoff_from ?? true,
    is_active: props.preset?.is_active ?? true,
    is_default: props.preset?.is_default || false
});

// Computed
const currentEngineData = computed(() => {
    return form.value.engine_name ? props.engines[form.value.engine_name] : null;
});

const isFormValid = computed(() => {
    return form.value.name &&
        form.value.engine_name &&
        validationErrors.value.length === 0;
});

const buttonPrimaryClass = computed(() => [
    'inline-flex items-center px-8 py-3 rounded-xl font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed',
    'bg-indigo-600 hover:bg-indigo-700 text-white'
]);

const buttonSecondaryClass = computed(() => [
    'px-4 py-2 rounded-lg text-sm font-medium transition-all hover:scale-105',
    isDark.value
        ? 'bg-gray-600 text-gray-200 hover:bg-gray-500'
        : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
]);

// Methods
const showNotification = (message, type = 'success') => {
    notification.value = { message, type };
    setTimeout(() => {
        clearNotification();
    }, 5000);
};

const showError = (message) => {
    showNotification(message, 'error');
};

const clearNotification = () => {
    notification.value = { message: '', type: 'success' };
};

const onEngineChange = (engineName) => {
    // Clear previous config and errors when engine changes
    form.value.engine_config = {};
    errors.value = {};
    validationErrors.value = [];
    clearNotification();
};

const onValidationErrors = (newErrors) => {
    validationErrors.value = newErrors;
};

const submit = async () => {
    errors.value = {};
    clearNotification();

    // Basic validation
    if (!form.value.name) {
        errors.value.name = t('p_modal_name_is_required');
        return;
    }
    if (!form.value.engine_name) {
        errors.value.engine_name = t('p_modal_choose_engine');
        return;
    }

    // Length validations
    if (form.value.system_prompt && form.value.system_prompt.length > 10000) {
        errors.value.system_prompt = t('p_modal_sys_mess_to_long');
        return;
    }

    if (form.value.plugins_disabled && form.value.plugins_disabled.length > 255) {
        errors.value.plugins_disabled = t('p_modal_plugins_disabled_to_long');
        return;
    }

    // NEW: Validate handoff logic fields
    if (form.value.preset_code && form.value.preset_code.length > 50) {
        errors.value.preset_code = t('p_modal_preset_code_too_long');
        return;
    }

    if (form.value.preset_code_next && form.value.preset_code_next.length > 50) {
        errors.value.preset_code_next = t('p_modal_preset_code_next_too_long');
        return;
    }

    if (form.value.default_call_message && form.value.default_call_message.length > 1000) {
        errors.value.default_call_message = t('p_modal_default_call_message_too_long');
        return;
    }

    if (form.value.before_execution_wait < 1 || form.value.before_execution_wait > 60) {
        errors.value.before_execution_wait = t('p_modal_before_execution_wait_invalid');
        return;
    }

    // Check validation errors from engine config
    if (validationErrors.value.length > 0) {
        showError(t('p_modal_fix_validation_errors'));
        return;
    }

    isSubmitting.value = true;

    try {
        emit('save', form.value);
    } catch (error) {
        showError(t('p_modal_save_error'));
    } finally {
        isSubmitting.value = false;
    }
};

// Lifecycle
onMounted(() => {
    // Detect theme
    const savedTheme = localStorage.getItem('chat-theme');
    isDark.value = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);

    // Listen for theme changes
    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>
