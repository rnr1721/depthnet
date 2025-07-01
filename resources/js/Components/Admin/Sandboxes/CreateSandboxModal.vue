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
                            'relative w-full max-w-2xl rounded-2xl shadow-2xl backdrop-blur-sm border',
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
                                            class="w-12 h-12 bg-gradient-to-br from-cyan-500 to-purple-600 rounded-xl flex items-center justify-center">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 :class="[
                                                'text-2xl font-bold',
                                                isDark ? 'text-white' : 'text-gray-900'
                                            ]">{{ t('hv_create_sandbox') }}</h3>
                                            <p :class="[
                                                'text-sm mt-1',
                                                isDark ? 'text-gray-400' : 'text-gray-600'
                                            ]">{{ t('hv_create_sandbox_description') }}</p>
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
                            <form @submit.prevent="createSandbox" class="px-6 py-6 space-y-6">
                                <!-- Sandbox Type -->
                                <div>
                                    <label :class="[
                                        'block text-lg font-semibold mb-4',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('hv_sandbox_type') }}
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div v-for="(description, type) in sandboxTypes" :key="type"
                                            @click="selectSandboxType(type)" :class="[
                                                'relative p-4 rounded-xl border-2 cursor-pointer transition-all duration-200',
                                                'hover:scale-105 hover:shadow-lg',
                                                form.type === type
                                                    ? (isDark
                                                        ? 'border-cyan-500 bg-cyan-900 bg-opacity-50'
                                                        : 'border-cyan-500 bg-cyan-50')
                                                    : (isDark
                                                        ? 'border-gray-600 bg-gray-700 hover:border-gray-500'
                                                        : 'border-gray-300 bg-gray-50 hover:border-gray-400')
                                            ]">
                                            <div class="flex items-start space-x-3">
                                                <div :class="[
                                                    'w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0',
                                                    form.type === type
                                                        ? 'bg-cyan-500 text-white'
                                                        : (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-200 text-gray-600')
                                                ]">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10">
                                                        </path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1">
                                                    <h4 :class="[
                                                        'font-semibold capitalize',
                                                        form.type === type
                                                            ? (isDark ? 'text-cyan-200' : 'text-cyan-700')
                                                            : (isDark ? 'text-white' : 'text-gray-900')
                                                    ]">{{ type }}</h4>
                                                    <p :class="[
                                                        'text-sm mt-1',
                                                        form.type === type
                                                            ? (isDark ? 'text-cyan-300' : 'text-cyan-600')
                                                            : (isDark ? 'text-gray-400' : 'text-gray-600')
                                                    ]">{{ description || t('hv_sandbox_type_description', { type }) }}
                                                    </p>
                                                </div>
                                                <!-- Selected indicator -->
                                                <div v-if="form.type === type" class="absolute top-2 right-2">
                                                    <div
                                                        class="w-5 h-5 bg-cyan-500 rounded-full flex items-center justify-center">
                                                        <svg class="w-3 h-3 text-white" fill="currentColor"
                                                            viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd"
                                                                d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                                clip-rule="evenodd"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Validation error for type -->
                                    <div v-if="validationErrors.type" :class="[
                                        'mt-3 p-3 rounded-lg border-l-4',
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
                                            <span class="text-sm">{{ validationErrors.type }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Custom Name -->
                                <div>
                                    <label :for="`sandbox-name-${uniqueId}`" :class="[
                                        'block text-lg font-semibold mb-3',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('hv_sandbox_name') }} <span class="text-sm font-normal opacity-70">({{
                                            t('hv_optional') }})</span>
                                    </label>
                                    <div class="relative">
                                        <input :id="`sandbox-name-${uniqueId}`" v-model="form.name" type="text" :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all',
                                            'px-4 py-3 text-sm',
                                            validationErrors.name
                                                ? 'ring-red-500 focus:ring-red-500'
                                                : 'ring-gray-300 focus:ring-cyan-500',
                                            isDark
                                                ? (validationErrors.name
                                                    ? 'bg-red-900 bg-opacity-20 text-white placeholder-gray-400'
                                                    : 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600')
                                                : (validationErrors.name
                                                    ? 'bg-red-50 text-gray-900 placeholder-gray-500'
                                                    : 'bg-gray-50 text-gray-900 placeholder-gray-500')
                                        ]" :placeholder="t('hv_sandbox_name_placeholder')" maxlength="64" />
                                    </div>
                                    <!-- Validation error for name -->
                                    <div v-if="validationErrors.name" :class="[
                                        'mt-2 p-3 rounded-lg border-l-4',
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
                                            <span class="text-sm">{{ validationErrors.name }}</span>
                                        </div>
                                    </div>
                                    <p :class="[
                                        'text-xs mt-2',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">{{ t('hv_sandbox_name_help') }}</p>
                                </div>

                                <!-- Ports -->
                                <div>
                                    <label :for="`sandbox-ports-${uniqueId}`" :class="[
                                        'block text-lg font-semibold mb-3',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('hv_sandbox_ports') }} <span class="text-sm font-normal opacity-70">({{
                                            t('hv_optional') }})</span>
                                    </label>
                                    <div class="relative">
                                        <input :id="`sandbox-ports-${uniqueId}`" v-model="form.ports" type="text"
                                            :class="[
                                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all',
                                                'px-4 py-3 text-sm',
                                                validationErrors.ports
                                                    ? 'ring-red-500 focus:ring-red-500'
                                                    : 'ring-gray-300 focus:ring-cyan-500',
                                                isDark
                                                    ? (validationErrors.ports
                                                        ? 'bg-red-900 bg-opacity-20 text-white placeholder-gray-400'
                                                        : 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600')
                                                    : (validationErrors.ports
                                                        ? 'bg-red-50 text-gray-900 placeholder-gray-500'
                                                        : 'bg-gray-50 text-gray-900 placeholder-gray-500')
                                            ]" :placeholder="t('hv_sandbox_ports_placeholder')" maxlength="64" />
                                    </div>
                                    <!-- Validation error for ports -->
                                    <div v-if="validationErrors.ports" :class="[
                                        'mt-2 p-3 rounded-lg border-l-4',
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
                                            <span class="text-sm">{{ validationErrors.ports }}</span>
                                        </div>
                                    </div>
                                    <p :class="[
                                        'text-xs mt-2',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">{{ t('hv_sandbox_ports_help') }}</p>
                                </div>

                                <!-- General Error message -->
                                <div v-if="errorMessage" :class="[
                                    'p-4 rounded-xl border-l-4',
                                    isDark
                                        ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200'
                                        : 'bg-red-50 border-red-400 text-red-800'
                                ]">
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                        <span class="font-medium">{{ errorMessage }}</span>
                                    </div>
                                </div>
                            </form>

                            <!-- Footer -->
                            <div :class="[
                                'px-6 py-6 border-t flex flex-col sm:flex-row sm:justify-end space-y-3 sm:space-y-0 sm:space-x-4',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <button type="button" @click="$emit('close')" :class="[
                                    'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                    isDark
                                        ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]">
                                    {{ t('hv_cancel') }}
                                </button>
                                <button type="submit" @click="createSandbox" :disabled="form.processing || !isFormValid"
                                    :class="[
                                        'px-8 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2',
                                        'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                        'enabled:hover:scale-105 enabled:active:scale-95',
                                        isDark
                                            ? 'bg-gradient-to-r from-cyan-600 to-purple-600 hover:from-cyan-700 hover:to-purple-700 text-white focus:ring-offset-gray-800'
                                            : 'bg-gradient-to-r from-cyan-600 to-purple-600 hover:from-cyan-700 hover:to-purple-700 text-white'
                                    ]">
                                    <span v-if="form.processing" class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('hv_creating') }}...
                                    </span>
                                    <span v-else class="flex items-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                        </svg>
                                        {{ t('hv_create_sandbox') }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
const props = defineProps({
    show: Boolean,
    isDark: Boolean,
    sandboxTypes: {
        type: Object,
        default: () => ({})
    }
});

const emit = defineEmits(['close', 'created']);

import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const form = ref({
    type: '',
    name: '',
    ports: '',
    processing: false
});

const errorMessage = ref('');
const validationErrors = ref({});
const uniqueId = Math.random().toString(36).substr(2, 9);

const sandboxTypes = computed(() => props.sandboxTypes || {});

const isFormValid = computed(() => {
    return form.value.type.trim() !== '' && !hasValidationErrors.value;
});

const hasValidationErrors = computed(() => {
    return Object.keys(validationErrors.value).length > 0;
});

const validateName = (name) => {
    const errors = {};

    if (name && name.trim()) {
        if (name.length > 64) {
            errors.name = t('hv_name_too_long');
        }

        if (!/^[a-zA-Z0-9\-_]+$/.test(name)) {
            errors.name = t('hv_name_invalid_chars');
        }
    }

    return errors;
};

const validateType = (type) => {
    const errors = {};

    if (!type || type.trim() === '') {
        errors.type = t('hv_sandbox_type_required');
    }

    return errors;
};

const validatePorts = (ports) => {
    const errors = {};

    if (!ports || ports.trim() === '') {
        return errors;
    }

    const trimmedPorts = ports.trim();

    const portsRegex = /^[0-9]+(?:,[0-9]+)*$/;
    if (!portsRegex.test(trimmedPorts)) {
        errors.ports = t('hv_sandbox_ports_invalid_format');
        return errors;
    }

    const portNumbers = trimmedPorts.split(',').map(p => parseInt(p, 10));
    const invalidPorts = portNumbers.filter(port => port < 1 || port > 65535);

    if (invalidPorts.length > 0) {
        errors.ports = t('hv_sandbox_ports_invalid_range'); // 'Port numbers must be between 1 and 65535'
        return errors;
    }

    return errors;
};

const selectSandboxType = (type) => {
    form.value.type = type;
    if (validationErrors.value.type) {
        const newErrors = { ...validationErrors.value };
        delete newErrors.type;
        validationErrors.value = newErrors;
    }
};

const createSandbox = async () => {
    validationErrors.value = {};
    errorMessage.value = '';

    const typeErrors = validateType(form.value.type);
    const nameErrors = validateName(form.value.name);
    const portsErrors = validatePorts(form.value.ports);

    validationErrors.value = { ...typeErrors, ...nameErrors, ...portsErrors };

    if (hasValidationErrors.value) {
        return;
    }

    form.value.processing = true;

    try {
        const response = await axios.post('/admin/sandboxes', {
            type: form.value.type,
            name: form.value.name || null,
            ports: form.value.ports || null
        });

        if (response.data.success) {
            emit('created', response.data);
            resetForm();
            emit('close');
        } else {
            if (response.data.errors) {
                validationErrors.value = response.data.errors;
            } else {
                errorMessage.value = response.data.message || t('hv_creation_failed');
            }
        }
    } catch (error) {
        if (error.response?.status === 422 && error.response?.data?.errors) {
            validationErrors.value = error.response.data.errors;
        } else {
            errorMessage.value = t('hv_network_error') + ': ' + (error.response?.data?.message || error.message);
        }
    } finally {
        form.value.processing = false;
    }
};

const resetForm = () => {
    form.value = {
        type: '',
        name: '',
        ports: '',
        processing: false
    };
    errorMessage.value = '';
    validationErrors.value = {};
};

watch(() => form.value.name, (newName) => {
    if (validationErrors.value.name) {
        const nameErrors = validateName(newName);
        if (!nameErrors.name) {
            const newErrors = { ...validationErrors.value };
            delete newErrors.name;
            validationErrors.value = newErrors;
        } else {
            validationErrors.value.name = nameErrors.name;
        }
    }
});

// Reset form when modal closes
watch(() => props.show, (newValue) => {
    if (!newValue) {
        setTimeout(resetForm, 300); // Wait for transition
    }
});
</script>
