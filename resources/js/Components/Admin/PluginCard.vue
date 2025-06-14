<template>
    <div :class="[
        'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all hover:shadow-2xl',
        plugin.enabled
            ? (isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200')
            : (isDark ? 'bg-gray-900 bg-opacity-50 border-gray-800' : 'bg-gray-100 bg-opacity-50 border-gray-300')
    ]">
        <!-- Plugin Header -->
        <div :class="[
            'px-6 py-4 border-b',
            plugin.enabled
                ? (isDark ? 'border-gray-700' : 'border-gray-200')
                : (isDark ? 'border-gray-800' : 'border-gray-300')
        ]">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="flex items-center space-x-3 mb-2">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z">
                            </path>
                        </svg>
                        <div>
                            <h3 :class="[
                                'font-bold text-lg',
                                isDark ? 'text-white' : 'text-gray-900'
                            ]">{{ plugin.display_name }}</h3>
                            <p :class="[
                                'text-xs',
                                isDark ? 'text-gray-400' : 'text-gray-600'
                            ]">{{ plugin.name }}</p>
                        </div>
                    </div>
                    <p :class="[
                        'text-sm mb-3',
                        isDark ? 'text-gray-300' : 'text-gray-600'
                    ]">{{ plugin.description || t('plugins_no_description') }}</p>
                    <div class="flex items-center space-x-2">
                        <span :class="[
                            'inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium',
                            plugin.enabled
                                ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                : (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                        ]">
                            {{ plugin.enabled ? t('plugins_enabled') : t('plugins_disabled') }}
                        </span>
                        <span :class="[
                            'inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium',
                            getHealthStatusBadge(plugin.health_status)
                        ]">
                            {{ t(`plugins_status_${plugin.health_status}`) }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <!-- Toggle Switch -->
                    <button @click="togglePlugin" :disabled="toggleLoading" :class="[
                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        plugin.enabled
                            ? 'bg-blue-600'
                            : (isDark ? 'bg-gray-600' : 'bg-gray-300'),
                        isDark ? 'focus:ring-offset-gray-900' : ''
                    ]">
                        <span :class="[
                            'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                            plugin.enabled ? 'translate-x-6' : 'translate-x-1'
                        ]"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <div v-if="errorMessage || successMessage" class="px-6 py-3">
            <!-- Error Message -->
            <div v-if="errorMessage" :class="[
                'p-3 rounded-lg text-sm flex items-center',
                isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-800'
            ]">
                <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                        clip-rule="evenodd"></path>
                </svg>
                {{ errorMessage }}
            </div>

            <!-- Success Message -->
            <div v-if="successMessage" :class="[
                'p-3 rounded-lg text-sm flex items-center',
                isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-50 text-green-800'
            ]">
                <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd"></path>
                </svg>
                {{ successMessage }}
            </div>
        </div>

        <!-- Plugin Configuration Form -->
        <div v-if="plugin.enabled && hasConfigFields" class="px-6 py-4 space-y-4">
            <h4 :class="[
                'text-sm font-medium flex items-center',
                isDark ? 'text-white' : 'text-gray-900'
            ]">
            </h4>

            <div class="space-y-3">
                <div v-for="(field, fieldName) in plugin.config_fields" :key="fieldName" class="space-y-1">
                    <label v-if="fieldName !== 'enabled'" :class="[
                        'text-xs font-medium block',
                        isDark ? 'text-gray-300' : 'text-gray-700'
                    ]">
                        {{ field.label || fieldName }}
                        <span v-if="field.required" class="text-red-500 ml-1">*</span>
                    </label>

                    <!-- Text/Password Input -->
                    <input v-if="field.type === 'text' || field.type === 'password'" v-model="localConfig[fieldName]"
                        :type="field.type" :placeholder="field.placeholder || field.default"
                        @input="updateConfig(fieldName, localConfig[fieldName])" :class="getFieldClasses(fieldName)" />
                    <div v-if="hasFieldError(fieldName)" :class="[
                        'text-xs mt-1',
                        'text-red-500'
                    ]">
                        {{ getFieldError(fieldName) }}
                    </div>

                    <!-- Textarea -->
                    <textarea v-else-if="field.type === 'textarea'" v-model="localConfig[fieldName]"
                        :placeholder="field.placeholder || field.default"
                        @input="updateConfig(fieldName, localConfig[fieldName])" rows="3"
                        :class="getFieldClasses(fieldName)"></textarea>
                    <div v-if="hasFieldError(fieldName)" :class="[
                        'text-xs mt-1',
                        'text-red-500'
                    ]">
                        {{ getFieldError(fieldName) }}
                    </div>

                    <!-- Number Input -->
                    <input v-else-if="field.type === 'number'" v-model.number="localConfig[fieldName]" type="number"
                        :min="field.min" :max="field.max" :placeholder="field.placeholder || field.default"
                        @input="updateConfig(fieldName, localConfig[fieldName])" :class="getFieldClasses(fieldName)" />
                    <div v-if="hasFieldError(fieldName)" :class="[
                        'text-xs mt-1',
                        'text-red-500'
                    ]">
                        {{ getFieldError(fieldName) }}
                    </div>

                    <!-- Select -->
                    <select v-else-if="field.type === 'select'" v-model="localConfig[fieldName]"
                        @change="updateConfig(fieldName, localConfig[fieldName])" :class="[
                            'w-full px-3 py-2 text-sm rounded-lg border transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500',
                            isDark
                                ? 'bg-gray-700 border-gray-600 text-white'
                                : 'bg-white border-gray-300 text-gray-900'
                        ]">
                        <option v-for="(label, value) in field.options" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>

                    <!-- Checkbox -->
                    <label v-else-if="field.type === 'checkbox' && fieldName !== 'enabled'"
                        class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" v-model="localConfig[fieldName]"
                            @change="updateConfig(fieldName, localConfig[fieldName])" :class="[
                                'rounded border focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                                isDark
                                    ? 'bg-gray-700 border-gray-600 text-blue-600 focus:ring-offset-gray-800'
                                    : 'bg-white border-gray-300 text-blue-600'
                            ]" />
                        <span :class="[
                            'text-xs',
                            isDark ? 'text-gray-300' : 'text-gray-700'
                        ]">{{ field.description || field.label }}</span>
                    </label>

                    <p v-if="field.description && field.type !== 'checkbox'" :class="[
                        'text-xs',
                        isDark ? 'text-gray-400' : 'text-gray-600'
                    ]">{{ field.description }}</p>
                </div>
            </div>
        </div>

        <!-- Plugin Actions -->
        <div class="px-6 py-4">
            <div class="flex items-center justify-between space-x-2">
                <div class="flex space-x-2">
                    <!-- Test Button -->
                    <button v-if="plugin.enabled" @click="testPlugin" :disabled="testLoading" :class="[
                        'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        isDark
                            ? 'bg-blue-900 bg-opacity-50 text-blue-200 hover:bg-opacity-70'
                            : 'bg-blue-100 text-blue-800 hover:bg-blue-200'
                    ]">
                        <svg v-if="testLoading" class="w-3 h-3 mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <svg v-else class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        {{ t('plugins_test') }}
                    </button>

                    <!-- Reset Config Button -->
                    <button v-if="plugin.enabled && hasConfigFields" @click="resetConfig" :disabled="resetLoading"
                        :class="[
                            'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-yellow-900 bg-opacity-50 text-yellow-200 hover:bg-opacity-70'
                                : 'bg-yellow-100 text-yellow-800 hover:bg-yellow-200'
                        ]">
                        <svg v-if="resetLoading" class="w-3 h-3 mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <svg v-else class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                        {{ t('plugins_reset') }}
                    </button>
                </div>

                <!-- Save Config Button -->
                <button v-if="plugin.enabled && hasConfigFields && hasUnsavedChanges" @click="saveConfig"
                    :disabled="saveLoading" :class="[
                        'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        isDark
                            ? 'bg-green-900 bg-opacity-50 text-green-200 hover:bg-opacity-70'
                            : 'bg-green-100 text-green-800 hover:bg-green-200'
                    ]">
                    <svg v-if="saveLoading" class="w-3 h-3 mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <svg v-else class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    {{ t('plugins_save') }}
                </button>
            </div>

            <!-- Test Results -->
            <div v-if="testResult" :class="[
                'mt-3 p-3 rounded-lg text-sm',
                testResult.is_working
                    ? (isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-50 text-green-800')
                    : (isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-800')
            ]">
                <div class="flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path v-if="testResult.is_working" fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                            clip-rule="evenodd"></path>
                        <path v-else fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span>{{ testResult.message }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    plugin: {
        type: Object,
        required: true
    },
    isDark: {
        type: Boolean,
        default: false
    },
    updateConfigFn: {
        type: Function,
        required: true
    }
});

const emit = defineEmits(['toggle', 'test', 'updateConfig', 'resetConfig']);

// State
const toggleLoading = ref(false);
const testLoading = ref(false);
const saveLoading = ref(false);
const resetLoading = ref(false);
const testResult = ref(null);
const localConfig = ref({});
const originalConfig = ref({});

const validationErrors = ref({});
const errorMessage = ref('');
const successMessage = ref('');

// Computed
const hasConfigFields = computed(() => {
    return props.plugin.config_fields && Object.keys(props.plugin.config_fields).length > 0;
});

const hasUnsavedChanges = computed(() => {
    return JSON.stringify(localConfig.value) !== JSON.stringify(originalConfig.value);
});

// Methods
const togglePlugin = async () => {
    toggleLoading.value = true;
    try {
        await emit('toggle', props.plugin.name);
    } finally {
        toggleLoading.value = false;
    }
};

const testPlugin = async () => {
    testLoading.value = true;
    testResult.value = null;
    try {
        const result = await emit('test', props.plugin.name);
        testResult.value = result;
    } finally {
        testLoading.value = false;
    }
};

const updateConfig = (fieldName, value) => {
    const field = props.plugin.config_fields[fieldName];
    if (field?.type === 'textarea' && (fieldName.includes('directories') || fieldName.includes('commands'))) {
        localConfig.value[fieldName] = value ? value.split('\n').map(line => line.trim()).filter(line => line) : [];
    } else {
        localConfig.value[fieldName] = value;
    }

    if (validationErrors.value[fieldName]) {
        delete validationErrors.value[fieldName];
    }
};

const saveConfig = async () => {
    saveLoading.value = true;
    validationErrors.value = {};
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const result = await props.updateConfigFn(props.plugin.name, localConfig.value);

        if (result && result.success === true) {
            originalConfig.value = { ...localConfig.value };
            successMessage.value = 'Configuration saved successfully';
            setTimeout(() => { successMessage.value = ''; }, 3000);
        } else if (result && result.success === false) {
            if (result.errors && Object.keys(result.errors).length > 0) {
                validationErrors.value = result.errors;
                errorMessage.value = result.message || 'Configuration validation failed';
            } else {
                errorMessage.value = result.message || 'Failed to save configuration';
            }
        }
    } catch (error) {
        console.error('Config save error:', error);
        errorMessage.value = 'Failed to save configuration. Please try again.';
    } finally {
        saveLoading.value = false;
    }
};

const getFieldError = (fieldName) => {
    return validationErrors.value[fieldName] || '';
};

const hasFieldError = (fieldName) => {
    return !!validationErrors.value[fieldName];
};

const resetConfig = async () => {
    resetLoading.value = true;
    try {
        const result = await emit('resetConfig', props.plugin.name);
        if (result?.config) {
            localConfig.value = { ...result.config };
            originalConfig.value = { ...result.config };
        }
    } finally {
        resetLoading.value = false;
    }
};

const getHealthStatusBadge = (status) => {
    switch (status) {
        case 'healthy':
            return props.isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800';
        case 'warning':
            return props.isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800';
        case 'error':
            return props.isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800';
        default:
            return props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600';
    }
};

const getFieldClasses = (fieldName) => {
    return [
        'w-full px-3 py-2 text-sm rounded-lg border transition-colors focus:outline-none focus:ring-2',
        hasFieldError(fieldName)
            ? 'border-red-500 focus:ring-red-500 focus:border-red-500'
            : 'focus:ring-blue-500',
        props.isDark
            ? 'bg-gray-700 text-white placeholder-gray-400'
            : 'bg-white text-gray-900 placeholder-gray-500',
        !hasFieldError(fieldName) && props.isDark ? 'border-gray-600' : '',
        !hasFieldError(fieldName) && !props.isDark ? 'border-gray-300' : ''
    ];
};

watch(() => props.plugin, (newPlugin) => {
    if (newPlugin?.current_config) {
        const config = { ...newPlugin.current_config };

        Object.keys(config).forEach(key => {
            const field = newPlugin.config_fields?.[key];
            if (field?.type === 'textarea' && Array.isArray(config[key])) {
                config[key] = config[key].join('\n');
            }
        });

        localConfig.value = config;
        originalConfig.value = { ...newPlugin.current_config };
    }
}, { immediate: true, deep: true });
</script>