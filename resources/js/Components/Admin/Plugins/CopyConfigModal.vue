<template>
    <!-- Modal Background -->
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto bg-opacity-75" @click.self="$emit('close')">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <!-- Modal panel -->
            <div :class="[
                'relative inline-block align-bottom rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full z-60',
                isDark
                    ? 'bg-gray-800 border border-gray-700'
                    : 'bg-white border border-gray-200'
            ]" @click.stop>
                <!-- Header -->
                <div :class="[
                    'px-6 py-4 border-b',
                    isDark ? 'border-gray-700' : 'border-gray-200'
                ]">
                    <div class="flex items-center justify-between">
                        <h3 :class="[
                            'text-lg font-semibold',
                            isDark ? 'text-white' : 'text-gray-900'
                        ]">
                            {{ t('plugins_copy_configurations') }}
                        </h3>
                        <button @click="$emit('close')" :class="[
                            'rounded-lg p-2 transition-colors hover:bg-opacity-20',
                            isDark
                                ? 'text-gray-400 hover:text-white hover:bg-white'
                                : 'text-gray-500 hover:text-gray-700 hover:bg-gray-500'
                        ]">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-4">
                    <p :class="[
                        'text-sm mb-6',
                        isDark ? 'text-gray-300' : 'text-gray-600'
                    ]">
                        {{ t('plugins_copy_configurations_description') }}
                    </p>

                    <!-- Current Preset Info -->
                    <div v-if="currentPreset" :class="[
                        'p-4 rounded-lg mb-6',
                        isDark ? 'bg-blue-900 bg-opacity-30' : 'bg-blue-50'
                    ]">
                        <div class="flex items-center">
                            <div
                                class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <div :class="[
                                    'text-sm font-medium',
                                    isDark ? 'text-blue-200' : 'text-blue-900'
                                ]">
                                    {{ t('plugins_current_preset') }}
                                </div>
                                <div :class="[
                                    'text-xs',
                                    isDark ? 'text-blue-300' : 'text-blue-700'
                                ]">
                                    {{ currentPreset.name }} (ID: {{ currentPreset.id }})
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form -->
                    <form @submit.prevent="handleCopy">
                        <!-- Source Preset -->
                        <div class="mb-4">
                            <label :class="[
                                'block text-sm font-medium mb-2',
                                isDark ? 'text-gray-300' : 'text-gray-700'
                            ]">
                                {{ t('plugins_copy_from_preset') }}
                            </label>
                            <select v-model="fromPresetId" required :class="[
                                'w-full px-3 py-2 border rounded-lg text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500',
                                isDark
                                    ? 'bg-gray-700 border-gray-600 text-white'
                                    : 'bg-white border-gray-300 text-gray-900'
                            ]">
                                <option value="">{{ t('plugins_select_source_preset') }}</option>
                                <option v-for="preset in availableSourcePresets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} ({{ preset.engine_name }})
                                </option>
                            </select>
                        </div>

                        <!-- Target Preset -->
                        <div class="mb-6">
                            <label :class="[
                                'block text-sm font-medium mb-2',
                                isDark ? 'text-gray-300' : 'text-gray-700'
                            ]">
                                {{ t('plugins_copy_to_preset') }}
                            </label>
                            <select v-model="toPresetId" required :class="[
                                'w-full px-3 py-2 border rounded-lg text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500',
                                isDark
                                    ? 'bg-gray-700 border-gray-600 text-white'
                                    : 'bg-white border-gray-300 text-gray-900'
                            ]">
                                <option value="">{{ t('plugins_select_target_preset') }}</option>
                                <option v-for="preset in availableTargetPresets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} ({{ preset.engine_name }})
                                </option>
                            </select>
                        </div>

                        <!-- Warning -->
                        <div v-if="fromPresetId && toPresetId" :class="[
                            'p-4 rounded-lg mb-6 border-l-4',
                            isDark
                                ? 'bg-yellow-900 bg-opacity-30 border-yellow-500 text-yellow-200'
                                : 'bg-yellow-50 border-yellow-400 text-yellow-800'
                        ]">
                            <div class="flex items-start">
                                <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                        clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <p class="text-sm font-medium">{{ t('plugins_copy_warning') }}</p>
                                    <p class="text-xs mt-1">{{ t('plugins_copy_warning_description') }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Error Message -->
                        <div v-if="errorMessage" :class="[
                            'p-3 rounded-lg mb-4 text-sm',
                            isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-800'
                        ]">
                            {{ errorMessage }}
                        </div>

                        <!-- Success Message -->
                        <div v-if="successMessage" :class="[
                            'p-3 rounded-lg mb-4 text-sm',
                            isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-50 text-green-800'
                        ]">
                            {{ successMessage }}
                        </div>
                    </form>
                </div>

                <!-- Footer -->
                <div :class="[
                    'px-6 py-4 border-t flex justify-end space-x-3',
                    isDark ? 'border-gray-700 bg-gray-800' : 'border-gray-200 bg-gray-50'
                ]">
                    <button type="button" @click="$emit('close')" :class="[
                        'px-4 py-2 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2',
                        isDark
                            ? 'bg-gray-600 text-white hover:bg-gray-700 focus:ring-gray-500 focus:ring-offset-gray-800'
                            : 'bg-gray-200 text-gray-800 hover:bg-gray-300 focus:ring-gray-400'
                    ]">
                        {{ t('plugins_cancel') }}
                    </button>
                    <button type="button" @click="handleCopy" :disabled="!fromPresetId || !toPresetId || copying"
                        :class="[
                            'px-4 py-2 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500 focus:ring-offset-gray-800'
                                : 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500'
                        ]">
                        <svg v-if="copying" class="w-4 h-4 mr-2 animate-spin inline" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        {{ copying ? t('plugins_copying') : t('plugins_copy_configurations') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    currentPreset: {
        type: Object,
        default: null
    },
    availablePresets: {
        type: Array,
        default: () => []
    },
    isDark: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close', 'copy']);

const fromPresetId = ref('');
const toPresetId = ref('');
const copying = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const availableSourcePresets = computed(() => {
    return props.availablePresets.filter(preset => preset.id !== props.currentPreset?.id);
});

const availableTargetPresets = computed(() => {
    return props.availablePresets.filter(preset =>
        preset.id !== props.currentPreset?.id &&
        preset.id !== fromPresetId.value
    );
});

const handleCopy = async () => {
    if (!fromPresetId.value || !toPresetId.value) {
        errorMessage.value = t('plugins_select_both_presets');
        return;
    }

    if (fromPresetId.value === toPresetId.value) {
        errorMessage.value = t('plugins_cannot_copy_same_preset');
        return;
    }

    copying.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const result = await emit('copy', {
            fromPresetId: fromPresetId.value,
            toPresetId: toPresetId.value
        });

        // If the emit returns a promise, wait for it
        if (result && typeof result.then === 'function') {
            await result;
        }

        successMessage.value = t('plugins_copy_success');

        // Auto close after success
        setTimeout(() => {
            emit('close');
        }, 2000);

    } catch (error) {
        errorMessage.value = error.message || t('plugins_copy_failed');
        console.error('Copy operation failed:', error);
    } finally {
        copying.value = false;
    }
};

// Reset form when modal opens
const resetForm = () => {
    fromPresetId.value = '';
    toPresetId.value = '';
    errorMessage.value = '';
    successMessage.value = '';
    copying.value = false;
};

// Watch for show prop changes to reset form
watch(() => props.show, (newValue) => {
    if (newValue) {
        resetForm();
    }
});
</script>
