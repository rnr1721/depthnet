<template>
    <div :class="[
        'rounded-xl border backdrop-blur-sm transition-all duration-200',
        isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700'
            : 'bg-white bg-opacity-90 border-gray-200 shadow-sm'
    ]">
        <!-- Card Header -->
        <div class="p-5 flex items-start justify-between">
            <div class="flex items-center">
                <div :class="[
                    'w-2.5 h-2.5 rounded-full mr-3 mt-1 flex-shrink-0',
                    capability.configured && capability.is_active ? 'bg-green-400' : 'bg-gray-400'
                ]"></div>
                <div>
                    <h3 :class="['text-base font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ capability.label }}
                    </h3>
                    <p :class="['text-sm mt-0.5', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ capability.description }}
                    </p>
                </div>
            </div>

            <span v-if="capability.configured" :class="[
                'inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium flex-shrink-0 ml-4',
                capability.is_active
                    ? isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800'
                    : isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500'
            ]">
                {{ capability.is_active ? t('capabilities_badge_active') : t('capabilities_badge_inactive') }}
            </span>
            <span v-else :class="[
                'inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium flex-shrink-0 ml-4',
                isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-700'
            ]">
                {{ t('capabilities_badge_not_configured') }}
            </span>
        </div>

        <!-- Expand toggle -->
        <button @click="expanded = !expanded" :class="[
            'w-full px-5 py-2 flex items-center justify-between text-sm font-medium transition-colors border-t',
            isDark ? 'border-gray-700 text-gray-400 hover:text-white hover:bg-gray-700'
                : 'border-gray-100 text-gray-500 hover:text-gray-900 hover:bg-gray-50'
        ]">
            <span>{{ expanded ? t('capabilities_hide_config') : t('capabilities_configure') }}</span>
            <svg :class="['w-4 h-4 transition-transform', expanded ? 'rotate-180' : '']" fill="none"
                stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Configuration Form -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0" leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1">
            <div v-if="expanded" :class="['px-5 pb-5 border-t', isDark ? 'border-gray-700' : 'border-gray-100']">
                <div class="pt-4 space-y-4">

                    <!-- Driver selector -->
                    <div>
                        <label :class="labelClass">{{ t('capabilities_provider_label') }}</label>
                        <select v-model="form.driver" @change="onDriverChange" :class="inputClass">
                            <option value="">{{ t('capabilities_provider_placeholder') }}</option>
                            <option v-for="(driver, key) in capability.drivers" :key="key" :value="key">
                                {{ driver.display_name }}
                            </option>
                        </select>
                    </div>

                    <!-- Dynamic config fields -->
                    <template v-if="form.driver && currentDriver">
                        <div v-for="(field, fieldKey) in currentDriver.config_fields" :key="fieldKey">
                            <label :class="labelClass">
                                {{ field.label }}
                                <span v-if="field.required" class="text-red-400 ml-0.5">*</span>
                            </label>

                            <!-- password -->
                            <input v-if="field.type === 'password'" type="password" v-model="form.config[fieldKey]"
                                :placeholder="field.placeholder ?? ''"
                                :class="[inputClass, validationErrors[fieldKey] ? errorBorderClass : '']" />

                            <!-- select -->
                            <select v-else-if="field.type === 'select'" v-model="form.config[fieldKey]"
                                :class="[inputClass, validationErrors[fieldKey] ? errorBorderClass : '']">
                                <option v-for="(label, val) in (field.options ?? {})" :key="val" :value="val">
                                    {{ label }}
                                </option>
                            </select>

                            <!-- number -->
                            <input v-else-if="field.type === 'number'" type="number" v-model="form.config[fieldKey]"
                                :min="field.min" :max="field.max" :step="field.step ?? 1"
                                :placeholder="field.placeholder ?? ''"
                                :class="[inputClass, validationErrors[fieldKey] ? errorBorderClass : '']" />

                            <!-- checkbox -->
                            <button v-else-if="field.type === 'checkbox'" type="button"
                                @click="form.config[fieldKey] = !form.config[fieldKey]" :class="[
                                    'relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none',
                                    form.config[fieldKey] ? 'bg-indigo-600' : isDark ? 'bg-gray-600' : 'bg-gray-300'
                                ]">
                                <span :class="[
                                    'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                                    form.config[fieldKey] ? 'translate-x-4' : 'translate-x-1'
                                ]"></span>
                            </button>

                            <!-- url / text (+ optional model listing) -->
                            <template v-else>
                                <div class="flex gap-2">
                                    <input type="text" v-model="form.config[fieldKey]"
                                        :placeholder="field.placeholder ?? ''"
                                        :list="canListModels(fieldKey) ? `models-${capability.capability}` : undefined"
                                        :class="[inputClass, validationErrors[fieldKey] ? errorBorderClass : '', 'flex-1']" />

                                    <button v-if="canListModels(fieldKey)" type="button" @click="loadModels"
                                        :disabled="loadingModels" :class="[
                                            'inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 whitespace-nowrap',
                                            loadingModels ? 'opacity-50 cursor-not-allowed' : '',
                                            isDark ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-800'
                                        ]">
                                        <svg v-if="loadingModels" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none"
                                            viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        {{ loadingModels ? t('capabilities_loading_models') :
                                            t('capabilities_load_models') }}
                                    </button>
                                </div>

                                <datalist v-if="canListModels(fieldKey)" :id="`models-${capability.capability}`">
                                    <option v-for="m in availableModels" :key="m.id" :value="m.id">
                                        {{ m.title }}{{ m.description ? ' — ' + m.description : '' }}
                                    </option>
                                </datalist>

                                <p v-if="canListModels(fieldKey) && modelsMessage"
                                    :class="['text-xs mt-1', modelsError ? 'text-red-400' : (isDark ? 'text-gray-500' : 'text-gray-400')]">
                                    {{ modelsMessage }}
                                </p>
                            </template>

                            <p v-if="field.description"
                                :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                                {{ field.description }}
                            </p>
                            <p v-if="validationErrors[fieldKey]" class="text-xs mt-1 text-red-400">
                                {{ validationErrors[fieldKey] }}
                            </p>
                        </div>

                        <!-- Active toggle + explanation -->
                        <div :class="[
                            'rounded-lg p-3 border',
                            form.isActive
                                ? isDark ? 'border-green-800 bg-green-900 bg-opacity-20' : 'border-green-200 bg-green-50'
                                : isDark ? 'border-gray-700 bg-gray-900 bg-opacity-30' : 'border-gray-200 bg-gray-50'
                        ]">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span :class="['text-sm font-medium', isDark ? 'text-gray-200' : 'text-gray-800']">
                                        {{ t('capabilities_badge_active') }}
                                    </span>
                                    <p :class="['text-xs mt-0.5', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ form.isActive ? t('capabilities_active_hint_on') :
                                            t('capabilities_active_hint_off') }}
                                    </p>
                                </div>
                                <button type="button" @click="form.isActive = !form.isActive" :class="[
                                    'relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none flex-shrink-0',
                                    form.isActive ? 'bg-indigo-600' : isDark ? 'bg-gray-600' : 'bg-gray-300'
                                ]">
                                    <span :class="[
                                        'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                                        form.isActive ? 'translate-x-4' : 'translate-x-1'
                                    ]"></span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Save/validation feedback -->
                    <div v-if="feedbackMessage" :class="[
                        'p-3 rounded-lg text-sm',
                        feedbackSuccess
                            ? isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-50 text-green-700'
                            : isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-700'
                    ]">
                        {{ feedbackMessage }}
                    </div>

                    <!-- Test result -->
                    <div v-if="testResult" :class="[
                        'p-3 rounded-lg text-sm',
                        testResult.success
                            ? isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-50 text-green-700'
                            : isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-700'
                    ]">
                        <div class="flex items-start justify-between gap-3">
                            <span class="flex items-center">
                                <svg v-if="testResult.success" class="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor"
                                    viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                        clip-rule="evenodd" />
                                </svg>
                                <svg v-else class="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor"
                                    viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                        clip-rule="evenodd" />
                                </svg>
                                <span>{{ testResult.message }}</span>
                            </span>
                            <span v-if="testResult.latency_ms != null" class="text-xs opacity-70 flex-shrink-0">{{
                                testResult.latency_ms
                                }}ms</span>
                        </div>
                        <div v-if="testResult.dimension" class="text-xs mt-1 opacity-70">
                            {{ t('capabilities_vector_dimension') }}: {{ testResult.dimension }}
                        </div>
                        <div v-if="testResult.description" class="text-xs mt-1 opacity-70 italic">
                            "{{ testResult.description }}"
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex items-center gap-3 pt-1">
                        <button @click="handleSave" :disabled="saving || !form.driver" :class="[
                            'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500',
                            saving || !form.driver ? 'opacity-50 cursor-not-allowed bg-indigo-600 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                        ]">
                            <svg v-if="saving" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ saving ? t('capabilities_saving') : t('capabilities_save') }}
                        </button>

                        <button @click="handleTest" :disabled="testing || !capability.configured" :class="[
                            'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-400',
                            testing || !capability.configured ? 'opacity-50 cursor-not-allowed' : '',
                            isDark ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-800'
                        ]">
                            <svg v-if="testing" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ testing ? t('capabilities_testing') : t('capabilities_test') }}
                        </button>

                        <span v-if="!capability.configured"
                            :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
                            {{ t('capabilities_test_needs_save') }}
                        </span>
                    </div>

                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    capability: { type: Object, required: true },
    presetId: { type: [Number, String], default: null },
    isDark: { type: Boolean, default: false },
    // Callback props — return values, unlike emit() which is void.
    onSave: { type: Function, required: true },
    onTest: { type: Function, required: true },
});

// ── State ─────────────────────────────────────────────────────────────────────
const expanded = ref(false);
const saving = ref(false);
const testing = ref(false);
const feedbackMessage = ref(null);
const feedbackSuccess = ref(false);
const testResult = ref(null);
const validationErrors = ref({});

const loadingModels = ref(false);
const availableModels = ref([]);
const modelsMessage = ref(null);
const modelsError = ref(false);

// Active defaults to true for a brand-new (unconfigured) capability so users
// don't have to discover the toggle. Configured capabilities keep their state.
const form = ref({
    driver: props.capability.current_driver ?? '',
    config: { ...(props.capability.current_config ?? {}) },
    isActive: props.capability.configured ? (props.capability.is_active ?? true) : true,
});

// ── Computed ──────────────────────────────────────────────────────────────────
const currentDriver = computed(() =>
    form.value.driver ? props.capability.drivers[form.value.driver] : null
);

const canListModels = (fieldKey) =>
    fieldKey === 'model' && !!currentDriver.value?.lists_models;

// ── Driver change — reset to the new driver's defaults ────────────────────────
// Full reset (per design decision): switching driver clears the old driver's
// fields and loads the new one's defaults. Avoids leaking e.g. Novita's fields
// into a Claude config.
const onDriverChange = () => {
    testResult.value = null;
    feedbackMessage.value = null;
    validationErrors.value = {};
    availableModels.value = [];
    modelsMessage.value = null;

    if (currentDriver.value) {
        // If the freshly selected driver is the one already saved in DB,
        // restore its saved (masked) config; otherwise start from defaults.
        const isSavedDriver = form.value.driver === props.capability.current_driver;
        form.value.config = isSavedDriver
            ? { ...(props.capability.current_config ?? {}) }
            : { ...(currentDriver.value.default_config ?? {}) };
    } else {
        form.value.config = {};
    }
};

// ── Save ──────────────────────────────────────────────────────────────────────
const handleSave = async () => {
    saving.value = true;
    feedbackMessage.value = null;
    validationErrors.value = {};
    testResult.value = null;

    try {
        const result = await props.onSave({
            capability: props.capability.capability,
            driver: form.value.driver,
            config: form.value.config,
            isActive: form.value.isActive,
        });

        if (result?.success === false) {
            validationErrors.value = result.errors ?? {};
            feedbackMessage.value = result.message ?? t('capabilities_save_failed');
            feedbackSuccess.value = false;
        } else if (result?.success) {
            feedbackSuccess.value = true;
            feedbackMessage.value = form.value.isActive
                ? t('capabilities_saved')
                : t('capabilities_saved_inactive');
            setTimeout(() => { feedbackMessage.value = null; }, 4000);
        }
    } catch (e) {
        feedbackSuccess.value = false;
        feedbackMessage.value = t('capabilities_save_failed');
    } finally {
        saving.value = false;
    }
};

// ── Test ──────────────────────────────────────────────────────────────────────
const handleTest = async () => {
    testing.value = true;
    testResult.value = null;

    try {
        const result = await props.onTest(props.capability.capability);
        testResult.value = result ?? { success: false, message: t('capabilities_test_no_response') };
    } catch (e) {
        testResult.value = { success: false, message: t('capabilities_test_failed') };
    } finally {
        testing.value = false;
    }
};

// ── Model listing ──────────────────────────────────────────────────────────────
const loadModels = async () => {
    loadingModels.value = true;
    modelsMessage.value = null;
    modelsError.value = false;

    try {
        const response = await axios.get(
            route('admin.capabilities.models', {
                presetId: props.presetId,
                capability: props.capability.capability,
            })
        );

        if (response.data.success) {
            availableModels.value = response.data.models ?? [];
            modelsError.value = false;
            modelsMessage.value = t('capabilities_models_loaded', { count: response.data.count ?? availableModels.value.length });
        } else {
            availableModels.value = [];
            modelsError.value = true;
            modelsMessage.value = response.data.message ?? t('capabilities_models_failed');
        }
    } catch (e) {
        availableModels.value = [];
        modelsError.value = true;
        modelsMessage.value = e.response?.data?.message ?? t('capabilities_models_failed');
    } finally {
        loadingModels.value = false;
    }
};

// ── Styles ────────────────────────────────────────────────────────────────────
const labelClass = computed(() =>
    ['block text-sm font-medium mb-1.5', props.isDark ? 'text-gray-300' : 'text-gray-700'].join(' ')
);

const inputClass = computed(() => [
    'w-full px-3 py-2 rounded-lg border text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500',
    props.isDark
        ? 'bg-gray-700 border-gray-600 text-white placeholder-gray-400'
        : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400',
].join(' '));

const errorBorderClass = 'border-red-500 focus:ring-red-500';
</script>