<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="transform opacity-0"
            enter-to-class="transform opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="transform opacity-100" leave-to-class="transform opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
                <!-- Backdrop -->
                <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="$emit('close')"></div>

                <!-- Modal -->
                <div class="flex min-h-full items-center justify-center p-4">
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 scale-100"
                        leave-to-class="transform opacity-0 scale-95">
                        <div v-if="show" :class="[
                            'relative w-full max-w-6xl max-h-[95vh] rounded-2xl shadow-2xl backdrop-blur-sm border overflow-hidden',
                            isDark
                                ? 'bg-gray-800 bg-opacity-95 border-gray-700'
                                : 'bg-white bg-opacity-95 border-gray-200'
                        ]">
                            <!-- Header -->
                            <div :class="[
                                'px-6 py-6 border-b',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <div
                                            class="w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-600 rounded-xl flex items-center justify-center">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 :class="[
                                                'text-2xl font-bold',
                                                isDark ? 'text-white' : 'text-gray-900'
                                            ]">{{ t('hv_execute_code') }}</h3>
                                            <p :class="[
                                                'text-sm mt-1',
                                                isDark ? 'text-gray-400' : 'text-gray-600'
                                            ]">{{ t('hv_sandbox') }}: {{ sandboxId }}</p>
                                        </div>
                                    </div>
                                    <button @click="$emit('close')" :class="[
                                        'p-2 rounded-lg transition-colors',
                                        isDark
                                            ? 'text-gray-400 hover:text-white hover:bg-gray-700'
                                            : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100'
                                    ]">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Content -->
                            <div class="flex h-[80vh]">
                                <!-- Left Panel - Code Editor -->
                                <div class="flex-1 flex flex-col border-r"
                                    :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                                    <!-- Controls -->
                                    <div :class="[
                                        'px-6 py-4 border-b',
                                        isDark ? 'border-gray-700 bg-gray-750' : 'border-gray-200 bg-gray-50'
                                    ]">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-4">
                                                <!-- Language Select -->
                                                <div>
                                                    <label :class="[
                                                        'block text-sm font-medium mb-1',
                                                        isDark ? 'text-white' : 'text-gray-900'
                                                    ]">
                                                        {{ t('hv_language') }}
                                                    </label>
                                                    <select v-model="form.language" @change="updateCodeTemplate" :class="[
                                                        'rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-purple-500 transition-all',
                                                        'px-3 py-2 text-sm min-w-[120px]',
                                                        isDark
                                                            ? 'bg-gray-700 text-white ring-gray-600'
                                                            : 'bg-white text-gray-900 ring-gray-300'
                                                    ]">
                                                        <option v-for="(config, lang) in supportedLanguages" :key="lang"
                                                            :value="lang">
                                                            {{ config.display_name }}
                                                        </option>
                                                    </select>
                                                </div>

                                                <!-- Filename -->
                                                <div>
                                                    <label :class="[
                                                        'block text-sm font-medium mb-1',
                                                        isDark ? 'text-white' : 'text-gray-900'
                                                    ]">
                                                        {{ t('hv_filename') }} <span class="text-xs opacity-70">({{
                                                            t('hv_optional') }})</span>
                                                    </label>
                                                    <input v-model="form.filename" type="text" :class="[
                                                        'rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-purple-500 transition-all',
                                                        'px-3 py-2 text-sm w-32',
                                                        isDark
                                                            ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400'
                                                            : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
                                                    ]" :placeholder="getDefaultFilename()" />
                                                </div>

                                                <!-- Timeout -->
                                                <div>
                                                    <label :class="[
                                                        'block text-sm font-medium mb-1',
                                                        isDark ? 'text-white' : 'text-gray-900'
                                                    ]">
                                                        {{ t('hv_timeout') }}
                                                    </label>
                                                    <input v-model.number="form.timeout" type="number" min="1" max="300"
                                                        :class="[
                                                            'rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-purple-500 transition-all',
                                                            'px-3 py-2 text-sm w-20',
                                                            isDark
                                                                ? 'bg-gray-700 text-white ring-gray-600'
                                                                : 'bg-white text-gray-900 ring-gray-300'
                                                        ]" />
                                                </div>
                                            </div>

                                            <!-- Execute Button -->
                                            <button @click="executeCode" :disabled="!form.code.trim() || executing"
                                                :class="[
                                                    'px-6 py-2 rounded-lg font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2',
                                                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                                    'enabled:hover:scale-105 enabled:active:scale-95',
                                                    isDark
                                                        ? 'bg-purple-600 hover:bg-purple-700 text-white focus:ring-offset-gray-800'
                                                        : 'bg-purple-600 hover:bg-purple-700 text-white'
                                                ]">
                                                <span v-if="executing" class="flex items-center">
                                                    <svg class="w-4 h-4 mr-2 animate-spin" fill="none"
                                                        viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                        </path>
                                                    </svg>
                                                    {{ t('hv_executing') }}...
                                                </span>
                                                <span v-else class="flex items-center">
                                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M19 10a9 9 0 11-18 0 9 9 0 0118 0z">
                                                        </path>
                                                    </svg>
                                                    {{ t('hv_run') }}
                                                </span>
                                            </button>
                                        </div>

                                        <!-- Templates -->
                                        <div v-if="getCurrentTemplates().length > 0" class="mt-4">
                                            <label :class="[
                                                'block text-sm font-medium mb-2',
                                                isDark ? 'text-white' : 'text-gray-900'
                                            ]">
                                                {{ t('hv_templates') }}
                                            </label>
                                            <div class="flex flex-wrap gap-2">
                                                <button v-for="template in getCurrentTemplates()" :key="template.name"
                                                    type="button" @click="loadTemplate(template)" :class="[
                                                        'px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                                        isDark
                                                            ? 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                                                            : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                                                    ]">
                                                    {{ template.name }}
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Error message -->
                                        <div v-if="errorMessage" :class="[
                                            'mt-4 p-3 rounded-lg border-l-4',
                                            isDark
                                                ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200'
                                                : 'bg-red-50 border-red-400 text-red-800'
                                        ]">
                                            <div class="flex items-center">
                                                <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="currentColor"
                                                    viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd"
                                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                                        clip-rule="evenodd"></path>
                                                </svg>
                                                <span class="text-sm">{{ errorMessage }}</span>
                                            </div>
                                        </div>

                                        <!-- Loading message -->
                                        <div v-if="loadingConfig" :class="[
                                            'mt-4 p-3 rounded-lg',
                                            isDark ? 'bg-blue-900 bg-opacity-50 text-blue-200' : 'bg-blue-50 text-blue-800'
                                        ]">
                                            <div class="flex items-center">
                                                <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                        stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                <span class="text-sm">{{ t('hv_loading_config') }}...</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Code Editor -->
                                    <div class="flex-1 relative">
                                        <textarea v-model="form.code" :class="[
                                            'w-full h-full resize-none border-0 focus:ring-0 font-mono text-sm leading-relaxed',
                                            'p-6',
                                            isDark
                                                ? 'bg-gray-900 text-gray-100 placeholder-gray-500'
                                                : 'bg-gray-50 text-gray-900 placeholder-gray-400'
                                        ]" :placeholder="t('hv_code_placeholder')" spellcheck="false"></textarea>
                                    </div>
                                </div>

                                <!-- Right Panel - Output -->
                                <div class="w-1/2 flex flex-col">
                                    <!-- Output Header -->
                                    <div :class="[
                                        'px-6 py-4 border-b',
                                        isDark ? 'border-gray-700 bg-gray-750' : 'border-gray-200 bg-gray-50'
                                    ]">
                                        <div class="flex items-center justify-between">
                                            <h4 :class="[
                                                'font-medium',
                                                isDark ? 'text-white' : 'text-gray-900'
                                            ]">{{ t('hv_output') }}</h4>
                                            <div class="flex items-center space-x-2">
                                                <span v-if="lastResult" :class="[
                                                    'text-xs px-2 py-1 rounded-full',
                                                    lastResult.exitCode === 0
                                                        ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                                        : (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                                                ]">
                                                    {{ t('hv_exit_code') }}: {{ lastResult.exitCode }}
                                                </span>
                                                <span v-if="lastResult" :class="[
                                                    'text-xs px-2 py-1 rounded',
                                                    isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-600'
                                                ]">
                                                    {{ lastResult.executionTime }}ms
                                                </span>
                                                <button @click="clearOutput" :class="[
                                                    'p-1 rounded transition-colors text-xs',
                                                    isDark
                                                        ? 'text-gray-400 hover:text-white hover:bg-gray-700'
                                                        : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200'
                                                ]">
                                                    {{ t('hv_clear') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Output Content -->
                                    <div class="flex-1 overflow-y-auto">
                                        <div v-if="!executionHistory.length" :class="[
                                            'p-6 text-center h-full flex items-center justify-center',
                                            isDark ? 'text-gray-400' : 'text-gray-500'
                                        ]">
                                            <div>
                                                <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none"
                                                    stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4">
                                                    </path>
                                                </svg>
                                                <p>{{ t('hv_no_code_executed') }}</p>
                                            </div>
                                        </div>

                                        <!-- Execution history -->
                                        <div v-else class="p-4 space-y-4">
                                            <div v-for="(result, index) in executionHistory" :key="index" :class="[
                                                'rounded-lg border',
                                                isDark ? 'border-gray-600' : 'border-gray-200'
                                            ]">
                                                <!-- Meta info -->
                                                <div :class="[
                                                    'px-4 py-2 border-b text-sm flex items-center justify-between',
                                                    isDark ? 'border-gray-600 bg-gray-700' : 'border-gray-200 bg-gray-100'
                                                ]">
                                                    <span :class="[
                                                        'font-medium',
                                                        isDark ? 'text-purple-400' : 'text-purple-600'
                                                    ]">
                                                        {{ getLanguageDisplayName(result.language) }} {{ result.filename
                                                            ? `(${result.filename})` : '' }}
                                                    </span>
                                                    <div class="flex items-center space-x-2">
                                                        <span :class="[
                                                            'text-xs px-2 py-1 rounded',
                                                            result.exitCode === 0
                                                                ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                                                : (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                                                        ]">
                                                            {{ result.exitCode }}
                                                        </span>
                                                        <span :class="[
                                                            'text-xs',
                                                            isDark ? 'text-gray-400' : 'text-gray-500'
                                                        ]">
                                                            {{ result.executionTime }}ms
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Output -->
                                                <div class="p-4">
                                                    <div v-if="result.output" :class="[
                                                        'font-mono text-sm whitespace-pre-wrap',
                                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                                    ]">{{ result.output }}</div>

                                                    <div v-if="result.error" :class="[
                                                        'font-mono text-sm whitespace-pre-wrap mt-2',
                                                        isDark ? 'text-red-400' : 'text-red-600'
                                                    ]">{{ result.error }}</div>

                                                    <div v-if="!result.output && !result.error" :class="[
                                                        'text-sm italic',
                                                        isDark ? 'text-gray-500' : 'text-gray-400'
                                                    ]">{{ t('hv_no_output') }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    show: Boolean,
    isDark: Boolean,
    sandboxId: String
});

const emit = defineEmits(['close']);

const form = ref({
    code: '',
    language: 'python',
    filename: '',
    timeout: 30,
    errors: {}
});

const executing = ref(false);
const errorMessage = ref('');
const executionHistory = ref([]);
const lastResult = ref(null);
const loadingConfig = ref(false);

// Configuration from server
const supportedLanguages = ref({});
const codeTemplates = ref({});

/**
 * Load configuration from server
 */
const loadConfig = async () => {
    loadingConfig.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get('/admin/sandboxes/config');

        if (response.data.success) {
            supportedLanguages.value = response.data.data.languages;
            codeTemplates.value = response.data.data.templates;

            // Set default language if current one is not supported
            if (!supportedLanguages.value[form.value.language] && Object.keys(supportedLanguages.value).length > 0) {
                form.value.language = Object.keys(supportedLanguages.value)[0];
            }
        } else {
            errorMessage.value = response.data.message || t('hv_failed_to_load_config');
        }
    } catch (error) {
        console.error('Failed to load sandbox config:', error);
        errorMessage.value = t('hv_network_error') + ': ' + (error.response?.data?.message || error.message);
    } finally {
        loadingConfig.value = false;
    }
};

const getCurrentTemplates = () => {
    return codeTemplates.value[form.value.language] || [];
};

const getDefaultFilename = () => {
    const config = supportedLanguages.value[form.value.language];
    return config ? `script.${config.extension}` : 'script.txt';
};

const getLanguageDisplayName = (language) => {
    const config = supportedLanguages.value[language];
    return config ? config.display_name : language;
};

const updateCodeTemplate = () => {
    if (!form.value.code.trim()) {
        const templates = getCurrentTemplates();
        if (templates.length > 0) {
            form.value.code = templates[0].code;
        }
    }
};

const loadTemplate = (template) => {
    form.value.code = template.code;
};

const executeCode = async () => {
    if (!form.value.code.trim()) return;

    executing.value = true;
    errorMessage.value = '';
    form.value.errors = {};

    try {
        const response = await axios.post(`/admin/sandboxes/${props.sandboxId}/execute-code`, {
            code: form.value.code,
            language: form.value.language,
            filename: form.value.filename || null,
            timeout: form.value.timeout
        });

        if (response.data.success) {
            const result = {
                language: form.value.language,
                filename: form.value.filename || getDefaultFilename(),
                ...response.data.data,
                timestamp: new Date()
            };

            executionHistory.value.unshift(result);
            lastResult.value = result;
        } else {
            if (response.data.errors) {
                form.value.errors = response.data.errors;
            } else {
                errorMessage.value = response.data.message || t('hv_execution_failed');
            }
        }
    } catch (error) {
        errorMessage.value = t('hv_network_error') + ': ' + (error.response?.data?.message || error.message);
    } finally {
        executing.value = false;
    }
};

const clearOutput = () => {
    executionHistory.value = [];
    lastResult.value = null;
};

const resetForm = () => {
    form.value = {
        code: '',
        language: Object.keys(supportedLanguages.value)[0] || 'python',
        filename: '',
        timeout: 30,
        errors: {}
    };
    errorMessage.value = '';
    executionHistory.value = [];
    lastResult.value = null;
};

// Load config when component mounts
onMounted(() => {
    loadConfig();
});

// Reset when modal closes
watch(() => props.show, (newValue) => {
    if (!newValue) {
        setTimeout(resetForm, 300);
    } else {
        // Load default template when opening (if config is already loaded)
        if (!loadingConfig.value && !form.value.code.trim()) {
            updateCodeTemplate();
        }
    }
});

// Update template when language changes
watch(() => form.value.language, () => {
    updateCodeTemplate();
});

// Update template when config loads
watch(() => loadingConfig.value, (isLoading) => {
    if (!isLoading && props.show && !form.value.code.trim()) {
        updateCodeTemplate();
    }
});
</script>
