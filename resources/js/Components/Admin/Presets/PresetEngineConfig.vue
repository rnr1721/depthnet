<template>
    <div v-if="engineName && currentEngineData"
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
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
        <div v-if="testResult" :class="[
            'mb-4 p-3 rounded-lg text-sm',
            testResult.success
                ? (isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-100 text-green-800')
                : (isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-100 text-red-800')
        ]">
            {{ testResult.success ? t('p_modal_conn_success') : t('p_modal_conn_error') + testResult.error }}
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

        <!-- Dynamic config fields -->
        <div v-if="configFields" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="(fieldConfig, fieldName) in configFields" :key="fieldName" class="space-y-2">
                <label :class="['block text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">
                    {{ fieldConfig.label }}
                    <span v-if="fieldConfig.required" class="text-red-500">*</span>
                </label>

                <!-- Text input -->
                <input v-if="fieldConfig.type === 'text'" :value="getFieldValue(fieldName)"
                    @input="updateConfigField(fieldName, $event.target.value)" :type="fieldConfig.type"
                    :required="fieldConfig.required" :placeholder="fieldConfig.placeholder" :class="inputClass" />

                <!-- Password input -->
                <input v-else-if="fieldConfig.type === 'password'" :value="getFieldValue(fieldName)"
                    @input="updateConfigField(fieldName, $event.target.value)" type="password"
                    :required="fieldConfig.required" :placeholder="fieldConfig.placeholder" :class="inputClass"
                    autocomplete="off" />

                <!-- Number input -->
                <input v-else-if="fieldConfig.type === 'number'" :value="getFieldValue(fieldName)"
                    @input="updateConfigField(fieldName, parseNumberValue($event.target.value, fieldConfig))"
                    type="number" :min="fieldConfig.min" :max="fieldConfig.max" :step="fieldConfig.step"
                    :required="fieldConfig.required" :placeholder="fieldConfig.placeholder" :class="inputClass" />

                <!-- URL input -->
                <input v-else-if="fieldConfig.type === 'url'" :value="getFieldValue(fieldName)"
                    @input="updateConfigField(fieldName, $event.target.value)" type="url"
                    :required="fieldConfig.required" :placeholder="fieldConfig.placeholder" :class="inputClass" />

                <!-- Select dropdown -->
                <select v-else-if="fieldConfig.type === 'select'" :value="getFieldValue(fieldName)"
                    @change="updateConfigField(fieldName, $event.target.value)" :required="fieldConfig.required"
                    :class="inputClass">
                    <option value="">{{ t('p_modal_choose') }}</option>
                    <option v-for="(label, value) in fieldConfig.options" :key="value" :value="value">
                        {{ label }}
                    </option>
                </select>

                <!-- Checkbox -->
                <label v-else-if="fieldConfig.type === 'checkbox'"
                    :class="['flex items-center space-x-2 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                    <input :checked="getFieldValue(fieldName)"
                        @change="updateConfigField(fieldName, $event.target.checked)" type="checkbox"
                        class="w-4 h-4 rounded text-indigo-600" />
                    <span class="text-sm">{{ fieldConfig.label }}</span>
                </label>

                <!-- Textarea -->
                <textarea v-else-if="fieldConfig.type === 'textarea'" :value="getFieldValue(fieldName)"
                    @input="updateConfigField(fieldName, $event.target.value)" :rows="fieldConfig.rows || 4"
                    :required="fieldConfig.required" :placeholder="fieldConfig.placeholder"
                    :class="inputClass"></textarea>

                <!-- Description -->
                <p v-if="fieldConfig.description" :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ fieldConfig.description }}
                </p>

                <!-- Validation errors -->
                <div v-if="errors[`engine_config.${fieldName}`]" class="text-red-500 text-xs">
                    {{ errors[`engine_config.${fieldName}`] }}
                </div>
            </div>
        </div>

        <!-- Validation summary -->
        <div v-if="validationErrors.length > 0" class="mt-4 p-4 border border-red-300 rounded-lg"
            :class="isDark ? 'bg-red-900 bg-opacity-20' : 'bg-red-100'">
            <h5 :class="['text-sm font-medium mb-2', isDark ? 'text-red-300' : 'text-red-800']">
                {{ t('p_modal_config_errors') }}:
            </h5>
            <ul :class="['text-sm space-y-1', isDark ? 'text-red-400' : 'text-red-700']">
                <li v-for="error in validationErrors" :key="error" class="flex items-start">
                    <span class="text-red-500 mr-2">•</span>
                    {{ error }}
                </li>
            </ul>
        </div>

        <!-- Loading state -->
        <div v-if="isLoading" class="flex items-center justify-center py-8">
            <svg class="w-6 h-6 animate-spin mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                </path>
            </svg>
            <span :class="isDark ? 'text-gray-300' : 'text-gray-600'">
                {{ t('p_modal_loading_config') }}
            </span>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Object,
        required: true
    },
    engineName: {
        type: String,
        default: ''
    },
    engines: {
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
    }
});

const emit = defineEmits(['update:modelValue', 'validation-errors', 'success', 'error']);

const isLoading = ref(false);
const isTestingConnection = ref(false);
const testResult = ref(null);
const validationErrors = ref([]);

const currentEngineData = computed(() => {
    return props.engineName ? props.engines[props.engineName] : null;
});

const configFields = computed(() => {
    return currentEngineData.value?.config_fields || {};
});

const recommendedPresets = computed(() => {
    return currentEngineData.value?.recommended_presets || [];
});

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const buttonSecondaryClass = computed(() => [
    'px-4 py-2 rounded-lg text-sm font-medium transition-all hover:scale-105',
    props.isDark
        ? 'bg-gray-600 text-gray-200 hover:bg-gray-500'
        : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
]);

const updateField = (field, value) => {
    const updated = { ...props.modelValue, [field]: value };
    emit('update:modelValue', updated);
};

const getFieldValue = (fieldName) => {
    return props.modelValue.engine_config?.[fieldName] || '';
};

const updateConfigField = (fieldName, value) => {
    const newConfig = {
        ...props.modelValue.engine_config,
        [fieldName]: value
    };
    updateField('engine_config', newConfig);
};

const parseNumberValue = (value, fieldConfig) => {
    const num = fieldConfig.step && fieldConfig.step < 1 ? parseFloat(value) : parseInt(value);
    return isNaN(num) ? '' : num;
};

const loadDefaults = async () => {
    if (!props.engineName) return;

    isLoading.value = true;
    try {
        const response = await axios.get(`/admin/engines/${props.engineName}/defaults`);
        if (response.data.success) {
            updateField('engine_config', { ...response.data.data.default_config });
            emit('success', t('p_modal_defaults_loaded'));
        }
    } catch (error) {
        console.error('Failed to load defaults:', error);
        // Fallback to static defaults
        if (currentEngineData.value?.default_config) {
            updateField('engine_config', { ...currentEngineData.value.default_config });
        }
        emit('error', t('p_modal_failed_load_defaults'));
    } finally {
        isLoading.value = false;
    }
};

const loadRecommended = (recommended) => {
    if (recommended.config) {
        const newConfig = {
            ...props.modelValue.engine_config,
            ...recommended.config
        };
        updateField('engine_config', newConfig);

        // Clear validation errors and test result
        validationErrors.value = [];
        testResult.value = null;

        emit('success', t('p_modal_recommended_loaded', { name: recommended.name }));
    }
};

const validateConfiguration = async () => {
    if (!props.engineName || !props.modelValue.engine_config) {
        validationErrors.value = [];
        emit('validation-errors', []);
        return;
    }

    try {
        const response = await axios.post(`/admin/engines/${props.engineName}/validate`, {
            config: props.modelValue.engine_config
        });

        if (response.data.success) {
            const errors = response.data.data.errors ? Object.values(response.data.data.errors) : [];
            validationErrors.value = errors;
            emit('validation-errors', errors);
        }
    } catch (error) {
        console.error('Validation failed:', error);
        const errorMsg = t('p_modal_error_validation_config');
        validationErrors.value = [errorMsg];
        emit('validation-errors', [errorMsg]);
    }
};

const testConfiguration = async () => {
    if (!props.engineName || !props.modelValue.engine_config) return;

    isTestingConnection.value = true;
    testResult.value = null;

    try {
        const response = await axios.post(`/admin/engines/${props.engineName}/test-config`, {
            config: props.modelValue.engine_config
        });

        testResult.value = response.data.data;

        if (response.data.data.success) {
            emit('success', t('p_modal_connection_test_success'));
        } else {
            emit('error', t('p_modal_connection_test_failed'));
        }
    } catch (error) {
        testResult.value = {
            success: false,
            error: error.response?.data?.message || t('p_modal_error_testing')
        };
        emit('error', t('p_modal_connection_test_failed'));
    } finally {
        isTestingConnection.value = false;
    }
};

// Watch for config changes and validate with debounce
let validationTimeout = null;
watch(() => props.modelValue.engine_config, () => {
    // Clear test result when config changes
    testResult.value = null;

    // Debounced validation
    clearTimeout(validationTimeout);
    validationTimeout = setTimeout(validateConfiguration, 1000);
}, { deep: true });

// Watch for engine changes
watch(() => props.engineName, (newEngine) => {
    if (newEngine) {
        // Clear previous state
        testResult.value = null;
        validationErrors.value = [];
        emit('validation-errors', []);

        // Auto-load defaults for new engines
        if (!props.modelValue.engine_config || Object.keys(props.modelValue.engine_config).length === 0) {
            loadDefaults();
        }
    }
});
</script>
