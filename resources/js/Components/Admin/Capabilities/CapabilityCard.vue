<template>
    <div :class="[
        'rounded-xl border backdrop-blur-sm transition-all duration-200',
        isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700'
            : 'bg-white bg-opacity-90 border-gray-200 shadow-sm'
    ]">
        <!-- Card Header -->
        <div class="p-5 flex items-start justify-between">
            <div class="flex items-center">
                <!-- Status dot -->
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

            <!-- Configured badge -->
            <span v-if="capability.configured" :class="[
                'inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium flex-shrink-0 ml-4',
                capability.is_active
                    ? isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800'
                    : isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500'
            ]">
                {{ capability.is_active ? 'Active' : 'Inactive' }}
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
            <span>{{ expanded ? 'Hide configuration' : 'Configure' }}</span>
            <svg :class="['w-4 h-4 transition-transform', expanded ? 'rotate-180' : '']" fill="none"
                stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Configuration Form -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0" leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1">
            <div v-if="expanded" :class="[
                'px-5 pb-5 border-t',
                isDark ? 'border-gray-700' : 'border-gray-100'
            ]">
                <div class="pt-4 space-y-4">

                    <!-- Driver selector -->
                    <div>
                        <label
                            :class="['block text-sm font-medium mb-1.5', isDark ? 'text-gray-300' : 'text-gray-700']">
                            {{ t('capabilities_provider_label') }}
                        </label>
                        <select v-model="form.driver" @change="onDriverChange" :class="inputClass">
                            <option value="">{{ t('capabilities_provider_placeholder') }}</option>
                            <option v-for="(driver, key) in capability.drivers" :key="key" :value="key">
                                {{ driver.display_name }}
                            </option>
                        </select>
                    </div>

                    <!-- Dynamic config fields from driver.getConfigFields() -->
                    <template v-if="form.driver && currentDriver">
                        <div v-for="(field, fieldKey) in currentDriver.config_fields" :key="fieldKey">
                            <label
                                :class="['block text-sm font-medium mb-1.5', isDark ? 'text-gray-300' : 'text-gray-700']">
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

                            <!-- url / text -->
                            <input v-else type="text" v-model="form.config[fieldKey]"
                                :placeholder="field.placeholder ?? ''"
                                :class="[inputClass, validationErrors[fieldKey] ? errorBorderClass : '']" />

                            <p v-if="field.description"
                                :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                                {{ field.description }}
                            </p>
                            <p v-if="validationErrors[fieldKey]" class="text-xs mt-1 text-red-400">
                                {{ validationErrors[fieldKey] }}
                            </p>
                        </div>
                    </template>

                    <!-- Active toggle -->
                    <div class="flex items-center justify-between pt-1">
                        <span :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">
                            {{ t('capabilities_badge_active') }}
                        </span>
                        <button type="button" @click="form.isActive = !form.isActive" :class="[
                            'relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none',
                            form.isActive ? 'bg-indigo-600' : isDark ? 'bg-gray-600' : 'bg-gray-300'
                        ]">
                            <span :class="[
                                'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                                form.isActive ? 'translate-x-4' : 'translate-x-1'
                            ]"></span>
                        </button>
                    </div>

                    <!-- Validation / feedback messages -->
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
                        <div class="flex items-center justify-between">
                            <span>{{ testResult.message }}</span>
                            <span class="text-xs opacity-70 ml-3">{{ testResult.latency_ms }}ms</span>
                        </div>
                        <div v-if="testResult.dimension" class="text-xs mt-1 opacity-70">
                            {{ t('capabilities_vector_dimension') }}: {{ testResult.dimension }}
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex items-center gap-3 pt-1">
                        <button @click="handleSave" :disabled="saving || !form.driver" :class="[
                            'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500',
                            saving || !form.driver
                                ? 'opacity-50 cursor-not-allowed bg-indigo-600 text-white'
                                : 'bg-indigo-600 hover:bg-indigo-700 text-white'
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
                            testing || !capability.configured
                                ? 'opacity-50 cursor-not-allowed'
                                : '',
                            isDark ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-800'
                        ]">
                            <svg v-if="testing" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ testing ? 'Testing...' : 'Test connection' }}
                        </button>
                    </div>

                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    capability: { type: Object, required: true },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'test']);

// ── State ─────────────────────────────────────────────────────────────────────
const expanded = ref(false);
const saving = ref(false);
const testing = ref(false);
const feedbackMessage = ref(null);
const feedbackSuccess = ref(false);
const testResult = ref(null);
const validationErrors = ref({});

// Form mirrors the current DB config or defaults when driver is first selected
const form = ref({
    driver: props.capability.current_driver ?? '',
    config: { ...(props.capability.current_config ?? {}) },
    isActive: props.capability.is_active ?? true,
});

// ── Computed ──────────────────────────────────────────────────────────────────
const currentDriver = computed(() =>
    form.value.driver ? props.capability.drivers[form.value.driver] : null
);

// ── Driver change — load defaults for new driver ──────────────────────────────
const onDriverChange = () => {
    testResult.value = null;
    feedbackMessage.value = null;
    validationErrors.value = {};

    if (currentDriver.value) {
        // Keep existing values but fill in defaults for fields not yet set
        const defaults = currentDriver.value.default_config ?? {};
        form.value.config = { ...defaults, ...form.value.config };
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
        const result = await emit('save', {
            capability: props.capability.capability,
            driver: form.value.driver,
            config: form.value.config,
            isActive: form.value.isActive,
        });

        if (result?.success === false) {
            validationErrors.value = result.errors ?? {};
            feedbackMessage.value = result.message ?? 'Save failed.';
            feedbackSuccess.value = false;
        } else if (result?.success) {
            feedbackMessage.value = 'Configuration saved.';
            feedbackSuccess.value = true;
            setTimeout(() => { feedbackMessage.value = null; }, 3000);
        }

    } finally {
        saving.value = false;
    }
};

// ── Test ──────────────────────────────────────────────────────────────────────
const handleTest = async () => {
    testing.value = true;
    testResult.value = null;

    try {
        const result = await emit('test', props.capability.capability);
        testResult.value = result;
    } finally {
        testing.value = false;
    }
};

// ── Styles ────────────────────────────────────────────────────────────────────
const inputClass = computed(() => [
    'w-full px-3 py-2 rounded-lg border text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500',
    props.isDark
        ? 'bg-gray-700 border-gray-600 text-white placeholder-gray-400'
        : 'bg-white border-gray-300 text-gray-900 placeholder-gray-400',
].join(' '));

const errorBorderClass = 'border-red-500 focus:ring-red-500';
</script>