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

            <!-- Body -->
            <form @submit.prevent="submit" class="p-6 space-y-6">
                <!-- Basic Info -->
                <div
                    :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
                    <h4 :class="['text-lg font-semibold mb-4', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('p_modal_main_info') }}</h4>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                                    t('p_modal_preset_name') }}
                                *</label>
                            <input v-model="form.name" type="text" required :class="inputClass"
                                :placeholder="t('p_modal_name_example_ph')" />
                            <div v-if="errors.name" class="text-red-500 text-xs mt-1">{{ errors.name }}</div>
                        </div>
                        <div>
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                                    t('p_modal_preset_engine') }}
                                *</label>
                            <select v-model="form.engine_name" @change="onEngineChange" required :class="inputClass">
                                <option value="">{{ t('p_modal_choose_engine') }}</option>
                                <option v-for="(engine, name) in enabledEngines" :key="name" :value="name">
                                    {{ engine.display_name }}
                                </option>
                            </select>
                            <div v-if="errors.engine_name" class="text-red-500 text-xs mt-1">{{ errors.engine_name }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                            t('p_modal_preset_description') }}</label>
                        <textarea v-model="form.description" rows="3" :class="inputClass"
                            :placeholder="t('p_modal_preset_description_ph')"></textarea>
                    </div>

                    <div class="mt-6 flex space-x-6">
                        <label
                            :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                            <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-indigo-600" />
                            <span class="text-sm font-medium">{{ t('p_modal_active') }}</span>
                        </label>
                        <label v-if="!preset?.is_default"
                            :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                            <input v-model="form.is_default" type="checkbox" class="w-4 h-4 rounded text-indigo-600" />
                            <span class="text-sm font-medium">{{ t('p_modal_default') }}</span>
                        </label>
                    </div>
                </div>

                <!-- System Prompt Section -->
                <div
                    :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
                    <div class="flex items-center justify-between mb-4">
                        <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_system_message') }}
                        </h4>
                        <div class="flex items-center space-x-2">
                            <span :class="['text-xs px-2 py-1 rounded-lg',
                                form.system_prompt ?
                                    (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800') :
                                    (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-100 text-gray-600')
                            ]">
                                {{ form.system_prompt ? t('p_modal_present') : t('p_modal_default') }}
                            </span>
                        </div>
                    </div>

                    <!-- System prompt input -->
                    <div class="space-y-4">
                        <div>
                            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('p_modal_user_sys_message') }}
                            </label>

                            <textarea ref="systemPromptRef" v-model="form.system_prompt" rows="6" :class="[inputClass]"
                                :placeholder="t('p_modal_system_prompt_desc')" @keydown="handleKeydown"
                                @beforeinput="handleBeforeInput"></textarea>

                            <!-- Quick placeholder buttons -->
                            <div v-if="placeholders && Object.keys(placeholders).length > 0"
                                class="flex flex-wrap gap-2 mt-2">
                                <button v-for="(description, key) in placeholders" :key="key" type="button"
                                    @click="insertPlaceholder(key, 'system_prompt')"
                                    class="inline-flex items-center px-2 py-1 text-xs bg-green-100 hover:bg-green-200 text-green-800 rounded-md transition-colors dark:bg-green-900 dark:text-green-200 dark:hover:bg-green-800"
                                    :title="description">
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 4v16m8-8H4"></path>
                                    </svg>
                                    [[{{ key }}]]
                                </button>
                            </div>

                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('p_modal_sys_message_desc') }}
                            </p>
                        </div>

                        <!-- Character counter -->
                        <div class="flex justify-between items-center text-xs"
                            :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                            <span>{{ t('p_modal_symbols') }}: {{ (form.system_prompt || '').length }} / 5000</span>
                            <button v-if="form.system_prompt" type="button" @click="clearSystemPrompt"
                                :class="['text-red-500 hover:text-red-600 underline']">
                                {{ t('p_modal_clear') }}
                            </button>
                        </div>

                        <!-- Preview of effective system prompt -->
                        <div v-if="!form.system_prompt && currentEngineData?.default_system_prompt"
                            :class="['p-3 rounded-lg border', isDark ? 'bg-gray-800 border-gray-600' : 'bg-gray-50 border-gray-200']">
                            <h6 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('p_modal_default_system_message') }}:
                            </h6>
                            <p :class="['text-xs whitespace-pre-wrap', isDark ? 'text-gray-400' : 'text-gray-600']">
                                {{ currentEngineData.default_system_prompt }}
                            </p>
                        </div>
                    </div>

                </div>

                <!-- Agent Section -->
                <div
                    :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
                    <div class="flex items-center justify-between mb-4">
                        <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_agent') }}
                        </h4>
                    </div>

                    <!-- Dopamine level -->
                    <div class="space-y-4">
                        <div>
                            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('p_modal_dopamine') }}
                            </label>
                            <input v-model="form.dopamine_level" :class="inputClass">
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('p_modal_dopamine_desc') }}
                            </p>
                        </div>
                    </div>

                    <!-- User notes -->
                    <div class="space-y-4 mt-6">
                        <div>
                            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('p_user_notes') }}
                            </label>
                            <textarea v-model="form.notes" rows="6" :class="inputClass"
                                :placeholder="t('p_modal_notes_ph')"></textarea>
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('p_user_notes_desc') }}
                            </p>
                        </div>

                        <!-- Character counter -->
                        <div class="flex justify-between items-center text-xs"
                            :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                            <span>{{ t('p_modal_symbols') }}: {{ (form.notes || '').length }} / 2000</span>
                            <button v-if="form.notes" type="button" @click="clearNotes"
                                :class="['text-red-500 hover:text-red-600 underline']">
                                {{ t('p_modal_clear') }}
                            </button>
                        </div>
                    </div>

                    <!-- Disabled plugins list -->
                    <div class="space-y-4 mt-6">
                        <div>
                            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('p_modal_plugins_disabled') }}
                            </label>
                            <input v-model="form.plugins_disabled" type="text"
                                :placeholder="t('p_modal_disabled_plugins_ph')" :class="inputClass">
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('p_modal_disabled_plugins_desc') }}
                            </p>
                        </div>
                    </div>

                </div>

                <!-- Engine Config -->
                <div v-if="form.engine_name && currentEngineData"
                    :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
                    <div class="flex items-center justify-between mb-4">
                        <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_settings') }}: {{ currentEngineData.display_name }}
                        </h4>
                        <div class="flex space-x-2">
                            <button type="button" @click="loadDefaults" :class="buttonSecondaryClass">
                                {{ t('p_modal_reset_to_defaults') }}
                            </button>
                            <button type="button" @click="testConfiguration" :disabled="isTestingConnection"
                                :class="buttonSecondaryClass">
                                {{ isTestingConnection ? t('p_modal_testing') : t('p_modal_connection_testing') }}
                            </button>
                        </div>
                    </div>

                    <!-- Connection test result -->
                    <div v-if="testResult"
                        :class="['mb-4 p-3 rounded-lg text-sm', testResult.success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800']">
                        {{ testResult.success ? t('p_modal_conn_success') : t('p_modal_conn_error') + testResult.error
                        }}
                        <span v-if="testResult.response_time" class="ml-2 text-xs opacity-75">
                            ({{ testResult.response_time }}ms)
                        </span>
                    </div>

                    <!-- Recommended presets -->
                    <div v-if="recommendedPresets.length > 0" class="mb-6">
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_recommended_settings') }}
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="recommended in recommendedPresets" :key="recommended.name" type="button"
                                @click="loadRecommended(recommended)" :class="[
                                    'px-3 py-2 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                    isDark ? 'bg-purple-900 bg-opacity-50 text-purple-200 hover:bg-opacity-70' : 'bg-purple-100 text-purple-800 hover:bg-purple-200'
                                ]">
                                {{ recommended.name }}
                                <span v-if="recommended.description" class="ml-1 opacity-75">
                                    - {{ recommended.description }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Dynamic config fields from engine -->
                    <div v-if="configFields" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="(fieldConfig, fieldName) in configFields" :key="fieldName" class="space-y-2">
                            <label :class="['block text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                {{ fieldConfig.label }}
                                <span v-if="fieldConfig.required" class="text-red-500">*</span>
                            </label>

                            <!-- Text input -->
                            <input v-if="fieldConfig.type === 'text'" v-model="form.engine_config[fieldName]"
                                :type="fieldConfig.type" :required="fieldConfig.required"
                                :placeholder="fieldConfig.placeholder" :class="inputClass" />

                            <!-- Password input -->
                            <input v-else-if="fieldConfig.type === 'password'" v-model="form.engine_config[fieldName]"
                                type="password" :required="fieldConfig.required" :placeholder="fieldConfig.placeholder"
                                :class="inputClass" autocomplete="off" />

                            <!-- Number input -->
                            <input v-else-if="fieldConfig.type === 'number'"
                                v-model.number="form.engine_config[fieldName]" type="number" :min="fieldConfig.min"
                                :max="fieldConfig.max" :step="fieldConfig.step" :required="fieldConfig.required"
                                :placeholder="fieldConfig.placeholder" :class="inputClass" />

                            <!-- URL input -->
                            <input v-else-if="fieldConfig.type === 'url'" v-model="form.engine_config[fieldName]"
                                type="url" :required="fieldConfig.required" :placeholder="fieldConfig.placeholder"
                                :class="inputClass" />

                            <!-- Select dropdown -->
                            <select v-else-if="fieldConfig.type === 'select'" v-model="form.engine_config[fieldName]"
                                :required="fieldConfig.required" :class="inputClass">
                                <option value="">{{ t('p_modal_choose') }}</option>
                                <option v-for="(label, value) in fieldConfig.options" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>

                            <!-- Checkbox -->
                            <label v-else-if="fieldConfig.type === 'checkbox'"
                                :class="['flex items-center space-x-2 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                                <input v-model="form.engine_config[fieldName]" type="checkbox"
                                    class="w-4 h-4 rounded text-indigo-600" />
                                <span class="text-sm">{{ fieldConfig.label }}</span>
                            </label>

                            <!-- Textarea -->
                            <textarea v-else-if="fieldConfig.type === 'textarea'"
                                v-model="form.engine_config[fieldName]" :rows="fieldConfig.rows || 4"
                                :required="fieldConfig.required" :placeholder="fieldConfig.placeholder"
                                :class="inputClass"></textarea>

                            <!-- Description -->
                            <p v-if="fieldConfig.description"
                                :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ fieldConfig.description }}
                            </p>

                            <!-- Validation errors -->
                            <div v-if="errors[`engine_config.${fieldName}`]" class="text-red-500 text-xs">
                                {{ errors[`engine_config.${fieldName}`] }}
                            </div>
                        </div>
                    </div>

                    <!-- Validation summary -->
                    <div v-if="validationErrors.length > 0"
                        class="mt-4 p-4 bg-red-100 border border-red-300 rounded-lg">
                        <h5 class="text-sm font-medium text-red-800 mb-2">{{ t('p_modal_config_errors') }}:</h5>
                        <ul class="text-sm text-red-700 space-y-1">
                            <li v-for="error in validationErrors" :key="error" class="flex items-start">
                                <span class="text-red-500 mr-2">•</span>
                                {{ error }}
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4 pt-6 border-t"
                    :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                    <button type="button" @click="$emit('close')" :class="buttonSecondaryClass">
                        {{ t('p_modal_cancel') }}
                    </button>
                    <button type="submit" :disabled="!isFormValid || isValidating" :class="buttonPrimaryClass">
                        {{ isValidating ? t('p_modal_checking') : (preset ? t('p_modal_save') : t('p_modal_create')) }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    preset: Object,
    engines: Object,
    placeholders: Object
});

const emit = defineEmits(['close', 'save']);

const placeholders = computed(() => props.placeholders || {});

const isDark = ref(false);
const errors = ref({});
const validationErrors = ref([]);
const isValidating = ref(false);
const isTestingConnection = ref(false);
const testResult = ref(null);
const systemPromptRef = ref(null);

const form = ref({
    name: props.preset?.name || '',
    description: props.preset?.description || '',
    engine_name: props.preset?.engine_name || '',
    system_prompt: props.preset?.system_prompt || '',
    notes: props.preset?.notes || '',
    dopamine_level: props.preset?.dopamine_level || 0,
    plugins_disabled: props.preset?.plugins_disabled || '',
    engine_config: props.preset?.engine_config || {},
    is_active: props.preset?.is_active ?? true,
    is_default: props.preset?.is_default || false
});

const enabledEngines = computed(() => {
    return Object.fromEntries(
        Object.entries(props.engines).filter(([name, engine]) => engine.enabled)
    );
});

const currentEngineData = computed(() => {
    return form.value.engine_name ? props.engines[form.value.engine_name] : null;
});

const configFields = computed(() => {
    return currentEngineData.value?.config_fields || {};
});

const recommendedPresets = computed(() => {
    return currentEngineData.value?.recommended_presets || [];
});

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    isDark.value ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const buttonPrimaryClass = computed(() => [
    'px-8 py-3 rounded-xl font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed',
    'bg-indigo-600 hover:bg-indigo-700 text-white'
]);

const buttonSecondaryClass = computed(() => [
    'px-4 py-2 rounded-lg text-sm font-medium transition-all hover:scale-105',
    isDark.value
        ? 'bg-gray-600 text-gray-200 hover:bg-gray-500'
        : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
]);

const isFormValid = computed(() => {
    return form.value.name &&
        form.value.engine_name &&
        validationErrors.value.length === 0;
});

const clearSystemPrompt = () => {
    form.value.system_prompt = '';
};

const clearNotes = () => {
    form.value.notes = '';
};

const onEngineChange = async () => {
    // Clear previous config and errors
    form.value.engine_config = {};
    errors.value = {};
    validationErrors.value = [];
    testResult.value = null;

    if (form.value.engine_name) {
        await loadDefaults();
    }
};

/**
 * Insert placeholder at cursor position
 * @param {string} placeholder - Placeholder to insert
 * @param {string} field - Field name to insert into
 */
const insertPlaceholder = (placeholder, field) => {
    const textarea = systemPromptRef.value;
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const currentValue = form.value[field];

    form.value[field] = currentValue.substring(0, start) + `[[${placeholder}]]` + currentValue.substring(end);

    nextTick(() => {
        textarea.focus();
        const newPosition = start + placeholder.length + 4; // +4 for braces [[]]
        textarea.setSelectionRange(newPosition, newPosition);
    });
};

/**
 * Handle keydown events for smart placeholder deletion
 * @param {KeyboardEvent} event
 */
const handleKeydown = (event) => {
    const textarea = event.target;
    const { selectionStart, selectionEnd, value } = textarea;

    if (event.key !== 'Backspace' && event.key !== 'Delete') return;

    if (selectionStart !== selectionEnd) return;

    let checkPosition;

    if (event.key === 'Backspace') {
        checkPosition = selectionStart - 1;
    } else {
        checkPosition = selectionStart;
    }

    const placeholder = findPlaceholderAt(value, checkPosition);

    if (placeholder) {
        event.preventDefault();

        const newValue = value.substring(0, placeholder.start) + value.substring(placeholder.end);
        form.value.system_prompt = newValue;

        nextTick(() => {
            textarea.focus();
            textarea.setSelectionRange(placeholder.start, placeholder.start);
        });
    }
};

/**
 * Handle beforeinput for typing over placeholders
 * @param {InputEvent} event
 */
const handleBeforeInput = (event) => {
    if (event.inputType !== 'insertText' && event.inputType !== 'insertCompositionText') return;

    const textarea = event.target;
    const { selectionStart, selectionEnd, value } = textarea;

    if (selectionStart !== selectionEnd) return;

    const placeholder = findPlaceholderAt(value, selectionStart);

    if (placeholder) {
        event.preventDefault();

        const newValue = value.substring(0, placeholder.start) + event.data + value.substring(placeholder.end);
        form.value.system_prompt = newValue;

        nextTick(() => {
            textarea.focus();
            const newPosition = placeholder.start + event.data.length;
            textarea.setSelectionRange(newPosition, newPosition);
        });
    }
};

/**
 * Find placeholder at specific position
 * @param {string} text - Text to search in
 * @param {number} position - Position to check
 * @returns {Object|null} Placeholder info or null
 */
const findPlaceholderAt = (text, position) => {
    if (!props.placeholders) return null;

    const placeholderKeys = Object.keys(props.placeholders);
    const regex = new RegExp(`\\[\\[(${placeholderKeys.join('|')})\\]\\]`, 'g');

    let match;
    while ((match = regex.exec(text)) !== null) {
        const start = match.index;
        const end = match.index + match[0].length;

        if (position >= start && position <= end) {
            return {
                start,
                end,
                text: match[0],
                key: match[1]
            };
        }
    }

    return null;
};

const loadDefaults = async () => {
    if (!form.value.engine_name) return;

    try {
        const response = await axios.get(`/admin/engines/${form.value.engine_name}/defaults`);
        if (response.data.success) {
            form.value.engine_config = { ...response.data.data.default_config };
        }
    } catch (error) {
        console.error('Failed to load defaults:', error);
        if (currentEngineData.value?.default_config) {
            form.value.engine_config = { ...currentEngineData.value.default_config };
        }
    }
};

const loadRecommended = (recommended) => {
    if (recommended.config) {
        form.value.engine_config = {
            ...form.value.engine_config,
            ...recommended.config
        };

        // Clear validation errors and test result
        validationErrors.value = [];
        testResult.value = null;
    }
};

const validateConfiguration = async () => {
    if (!form.value.engine_name || !form.value.engine_config) {
        validationErrors.value = [];
        return;
    }

    isValidating.value = true;

    try {
        const response = await axios.post(`/admin/engines/${form.value.engine_name}/validate`, {
            config: form.value.engine_config
        });

        if (response.data.success) {
            validationErrors.value = response.data.data.errors ?
                Object.values(response.data.data.errors) : [];
        }
    } catch (error) {
        console.error('Validation failed:', error);
        validationErrors.value = [t('p_modal_error_validation_config')];
    } finally {
        isValidating.value = false;
    }
};

const testConfiguration = async () => {
    if (!form.value.engine_name || !form.value.engine_config) return;

    isTestingConnection.value = true;
    testResult.value = null;

    try {
        const response = await axios.post(`/admin/engines/${form.value.engine_name}/test-config`, {
            config: form.value.engine_config
        });

        testResult.value = response.data.data;
    } catch (error) {
        testResult.value = {
            success: false,
            error: error.response?.data?.message || t('p_modal_error_testing')
        };
    } finally {
        isTestingConnection.value = false;
    }
};

const submit = async () => {
    errors.value = {};

    // Basic validation
    if (!form.value.name) {
        errors.value.name = t('p_modal_name_is_required');
        return;
    }
    if (!form.value.engine_name) {
        errors.value.engine_name = t('p_modal_choose_engine');
        return;
    }

    if (form.value.system_prompt && form.value.system_prompt.length > 10000) {
        errors.value.system_prompt = t('p_modal_sys_mess_to_long');
        return;
    }

    if (form.value.plugins_disabled && form.value.plugins_disabled.length > 255) {
        errors.value.plugins_disabled = t('p_modal_plugins_disabled_to_long');
        return;
    }

    if (form.value.notes && form.value.notes.length > 2000) {
        errors.value.notes = t('p_modal_notes_to_long');
        return;
    }

    await validateConfiguration();
    if (validationErrors.value.length > 0) {
        return;
    }

    emit('save', form.value);
};

// Watchers
watch(() => form.value.engine_config,
    () => {
        // Clear test result when config changes
        testResult.value = null;

        // Debounced validation
        clearTimeout(window.configValidationTimeout);
        window.configValidationTimeout = setTimeout(validateConfiguration, 1000);
    },
    { deep: true }
);

// Lifecycle
onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    isDark.value = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);

    // Load defaults if creating new preset
    if (!props.preset && form.value.engine_name) {
        nextTick(() => loadDefaults());
    }
});

// Watch engine change to load defaults
watch(() => form.value.engine_name, (newEngine) => {
    if (newEngine && !props.preset) {
        nextTick(() => loadDefaults());
    }
});
</script>
