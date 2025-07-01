<template>
    <!-- Sandbox Section -->
    <div v-if="$page.props.sandboxEnabled"
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Show message for new presets -->
        <div v-if="!preset?.id" class="text-center py-8">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4">
                </path>
            </svg>
            <h4 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-400' : 'text-gray-600']">
                {{ t('p_modal_sandbox_save_first') }}
            </h4>
            <p :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-500']">
                {{ t('p_modal_sandbox_save_first_desc') }}
            </p>
        </div>

        <!-- Normal sandbox management for existing presets -->
        <div v-else>
            <div class="flex items-center justify-between mb-4">
                <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_sandbox') }}
                </h4>
                <div class="flex items-center space-x-2">
                    <span v-if="isLoading" :class="['text-xs px-2 py-1 rounded-lg animate-pulse',
                        isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-100 text-gray-600'
                    ]">
                        {{ t('p_modal_loading') }}
                    </span>
                    <span v-else :class="['text-xs px-2 py-1 rounded-lg',
                        sandboxAssignment ?
                            (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800') :
                            (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-100 text-gray-600')
                    ]">
                        {{ sandboxAssignment ? t('p_modal_sandbox_assigned') : t('p_modal_sandbox_none') }}
                    </span>
                </div>
            </div>

            <!-- Current sandbox info -->
            <div v-if="sandboxAssignment" class="mb-4 p-3 rounded-lg border"
                :class="isDark ? 'bg-gray-800 border-gray-600' : 'bg-gray-50 border-gray-200'">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <h6 :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">
                            {{ t('p_modal_current_sandbox') }}
                        </h6>
                        <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-600']">
                            ID: {{ sandboxAssignment.sandbox_id }}
                        </p>
                        <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('p_modal_sandbox_type') }}: {{ sandboxAssignment.sandbox_type }}
                        </p>
                        <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('p_modal_sandbox_status') }}:
                            <span :class="getSandboxStatusColor(sandboxAssignment.sandbox?.status)">
                                {{ sandboxAssignment.sandbox?.status || 'unknown' }}
                            </span>
                        </p>
                        <p v-if="sandboxAssignment.assigned_at"
                            :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('p_modal_assigned_at') }}: {{ formatDate(sandboxAssignment.assigned_at) }}
                        </p>
                    </div>
                    <button type="button" @click="unassignSandbox" :disabled="isManaging" :class="['px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed',
                        isDark ? 'bg-red-900 bg-opacity-50 text-red-200 hover:bg-opacity-70' : 'bg-red-100 text-red-800 hover:bg-red-200'
                    ]">
                        {{ t('p_modal_sandbox_unassign') }}
                    </button>
                </div>
            </div>

            <!-- Sandbox management buttons -->
            <div class="flex flex-wrap gap-2 mb-4">
                <button v-if="!sandboxAssignment" type="button" @click="createAndAssignSandbox" :disabled="isManaging"
                    :class="buttonSecondaryClass">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    {{ isManaging ? t('p_modal_creating') : t('p_modal_create_assign_sandbox') }}
                </button>

                <button type="button" @click="toggleAvailableSandboxes" :disabled="isManaging"
                    :class="buttonSecondaryClass">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z">
                        </path>
                    </svg>
                    {{ showSandboxList ? t('p_modal_hide_sandboxes') : t('p_modal_assign_existing_sandbox') }}
                </button>

                <button v-if="sandboxAssignment" type="button" @click="refreshSandboxInfo" :disabled="isManaging"
                    :class="buttonSecondaryClass">
                    <svg class="w-4 h-4 mr-2" :class="{ 'animate-spin': isRefreshing }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                        </path>
                    </svg>
                    {{ t('p_modal_refresh_status') }}
                </button>
            </div>

            <!-- Available sandboxes list -->
            <Transition enter-active-class="transition ease-out duration-200"
                enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-150" leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95">
                <div v-if="showSandboxList" class="border rounded-lg overflow-hidden"
                    :class="isDark ? 'border-gray-600' : 'border-gray-200'">

                    <!-- Loading state -->
                    <div v-if="isLoadingSandboxes" class="p-4 text-center"
                        :class="isDark ? 'text-gray-400' : 'text-gray-600'">
                        <svg class="w-6 h-6 mx-auto animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                        <p class="mt-2 text-sm">{{ t('p_modal_loading_sandboxes') }}</p>
                    </div>

                    <!-- Sandboxes list -->
                    <div v-else-if="availableSandboxes.length > 0" class="max-h-48 overflow-y-auto">
                        <div v-for="sandbox in availableSandboxes" :key="sandbox.id"
                            class="p-3 border-b cursor-pointer transition-colors" :class="[
                                isDark ? 'border-gray-600 hover:bg-gray-700' : 'border-gray-200 hover:bg-gray-50',
                                sandbox.status !== 'running' ? 'opacity-60' : '',
                                isCurrentSandbox(sandbox.id) ? (isDark ? 'bg-gray-700' : 'bg-gray-100') : ''
                            ]" @click="assignExistingSandbox(sandbox.id)">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2">
                                        <span :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ sandbox.name }}
                                        </span>
                                        <span v-if="isCurrentSandbox(sandbox.id)" :class="['text-xs px-2 py-1 rounded',
                                            isDark ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-800'
                                        ]">
                                            {{ t('p_modal_current') }}
                                        </span>
                                    </div>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                            {{ t('p_modal_type') }}: {{ sandbox.type }}
                                        </span>
                                        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                            ID: {{ sandbox.id }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span :class="['text-xs px-2 py-1 rounded',
                                        sandbox.status === 'running'
                                            ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                            : (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                                    ]">
                                        {{ sandbox.status }}
                                    </span>
                                    <svg v-if="sandbox.status === 'running'" class="w-4 h-4 text-green-500"
                                        fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- No sandboxes available -->
                    <div v-else class="p-4 text-center text-sm" :class="isDark ? 'text-gray-400' : 'text-gray-600'">
                        <svg class="w-8 h-8 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4">
                            </path>
                        </svg>
                        {{ t('p_modal_no_available_sandboxes') }}
                    </div>
                </div>
            </Transition>

            <!-- Error message -->
            <div v-if="errorMessage"
                class="mt-4 p-3 rounded-lg border-l-4 border-red-400 bg-red-50 dark:bg-red-900 dark:bg-opacity-20">
                <div class="flex">
                    <svg class="w-5 h-5 text-red-400 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <p :class="['text-sm font-medium', isDark ? 'text-red-300' : 'text-red-800']">
                            {{ t('p_modal_sandbox_error') }}
                        </p>
                        <p :class="['text-sm mt-1', isDark ? 'text-red-400' : 'text-red-600']">
                            {{ errorMessage }}
                        </p>
                        <button type="button" @click="clearError"
                            :class="['text-xs underline mt-1', isDark ? 'text-red-300 hover:text-red-200' : 'text-red-700 hover:text-red-600']">
                            {{ t('p_modal_dismiss') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Disabled message -->
    <div v-else :class="['p-6 rounded-xl border border-dashed text-center',
        isDark ? 'border-gray-600 bg-gray-800 bg-opacity-30' : 'border-gray-300 bg-gray-50 bg-opacity-50']">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4">
            </path>
        </svg>
        <h4 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-400' : 'text-gray-600']">
            {{ t('p_modal_sandboxes_disabled') }}
        </h4>
        <p :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-500']">
            {{ t('p_modal_sandboxes_disabled_desc') }}
        </p>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const { t } = useI18n();
const page = usePage();

const props = defineProps({
    preset: Object,
    isDark: Boolean
});

const emit = defineEmits(['error', 'success']);

// State
const sandboxAssignment = ref(null);
const availableSandboxes = ref([]);
const isLoading = ref(false);
const isManaging = ref(false);
const isLoadingSandboxes = ref(false);
const isRefreshing = ref(false);
const showSandboxList = ref(false);
const errorMessage = ref('');

// Computed
const buttonSecondaryClass = computed(() => [
    'inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-all hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed',
    props.isDark
        ? 'bg-gray-600 text-gray-200 hover:bg-gray-500 disabled:hover:bg-gray-600'
        : 'bg-gray-200 text-gray-700 hover:bg-gray-300 disabled:hover:bg-gray-200'
]);

// Methods
const getSandboxStatusColor = (status) => {
    switch (status) {
        case 'running':
            return 'text-green-500';
        case 'stopped':
            return 'text-red-500';
        default:
            return props.isDark ? 'text-gray-400' : 'text-gray-600';
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleString();
};

const isCurrentSandbox = (sandboxId) => {
    return sandboxAssignment.value?.sandbox_id === sandboxId;
};

const clearError = () => {
    errorMessage.value = '';
};

const handleError = (error, defaultMessage) => {
    const message = error.response?.data?.message || error.message || defaultMessage;
    errorMessage.value = message;
    emit('error', message);
};

const handleSuccess = (message) => {
    errorMessage.value = '';
    emit('success', message);
};

/**
 * Load sandbox assignment for preset
 */
const loadSandboxAssignment = async () => {
    if (!props.preset?.id || !page.props.sandboxEnabled) return;

    isLoading.value = true;

    try {
        const response = await axios.get(`/admin/presets/${props.preset.id}/sandbox`);
        if (response.data.success && response.data.data) {
            sandboxAssignment.value = response.data.data;
        } else {
            sandboxAssignment.value = null;
        }
    } catch (error) {
        if (error.response?.status !== 404) {
            console.error('Failed to load sandbox assignment:', error);
            handleError(error, t('p_modal_error_loading_assignment'));
        } else {
            sandboxAssignment.value = null;
        }
    } finally {
        isLoading.value = false;
    }
};

/**
 * Create and assign new sandbox
 */
const createAndAssignSandbox = async () => {
    if (!props.preset?.id || isManaging.value) return;

    // Show confirmation dialog about potential delays
    const confirmed = confirm(
        t('p_modal_sandbox_create_warning') + '\n\n' +
        t('p_modal_sandbox_create_warning_desc')
    );

    if (!confirmed) return;

    isManaging.value = true;

    try {
        handleSuccess(t('p_modal_sandbox_create_started'));

        const response = await axios.post(`/admin/presets/${props.preset.id}/sandbox/create`, {
            sandbox_type: 'ubuntu-full'
        });

        if (response.data.success) {
            sandboxAssignment.value = {
                sandbox_id: response.data.data.sandbox_id,
                sandbox: response.data.data.sandbox,
                sandbox_type: response.data.data.sandbox.type,
                assigned_at: new Date().toISOString()
            };
            showSandboxList.value = false;
            handleSuccess(t('p_modal_sandbox_created_assigned'));
        }
    } catch (error) {
        console.error('Failed to create and assign sandbox:', error);
        handleError(error, t('p_modal_sandbox_create_error'));
    } finally {
        isManaging.value = false;
    }
};

/**
 * Toggle available sandboxes list
 */
const toggleAvailableSandboxes = async () => {
    showSandboxList.value = !showSandboxList.value;

    if (showSandboxList.value && availableSandboxes.value.length === 0) {
        await loadAvailableSandboxes();
    }
};

/**
 * Load available sandboxes
 */
const loadAvailableSandboxes = async () => {
    isLoadingSandboxes.value = true;

    try {
        const response = await axios.get('/admin/sandboxes/list');
        if (response.data.success) {
            availableSandboxes.value = response.data.data;
        }
    } catch (error) {
        console.error('Failed to load available sandboxes:', error);
        handleError(error, t('p_modal_error_loading_sandboxes'));
        availableSandboxes.value = [];
    } finally {
        isLoadingSandboxes.value = false;
    }
};

/**
 * Assign existing sandbox to preset
 */
const assignExistingSandbox = async (sandboxId) => {
    if (!props.preset?.id || isManaging.value || isCurrentSandbox(sandboxId)) return;

    isManaging.value = true;

    try {
        const response = await axios.post(`/admin/presets/${props.preset.id}/sandbox/assign`, {
            sandbox_id: sandboxId
        });

        if (response.data.success) {
            await loadSandboxAssignment();
            showSandboxList.value = false;
            handleSuccess(t('p_modal_sandbox_assigned_success'));
        }
    } catch (error) {
        console.error('Failed to assign sandbox:', error);
        handleError(error, t('p_modal_sandbox_assign_error'));
    } finally {
        isManaging.value = false;
    }
};

/**
 * Unassign sandbox from preset
 */
const unassignSandbox = async () => {
    if (!props.preset?.id || isManaging.value) return;

    if (!confirm(t('p_modal_sandbox_unassign_confirm'))) {
        return;
    }

    isManaging.value = true;

    try {
        const response = await axios.delete(`/admin/presets/${props.preset.id}/sandbox`);

        if (response.data.success) {
            sandboxAssignment.value = null;
            handleSuccess(t('p_modal_sandbox_unassigned_success'));
        }
    } catch (error) {
        console.error('Failed to unassign sandbox:', error);
        handleError(error, t('p_modal_sandbox_unassign_error'));
    } finally {
        isManaging.value = false;
    }
};

/**
 * Refresh sandbox information
 */
const refreshSandboxInfo = async () => {
    if (!sandboxAssignment.value || isRefreshing.value) return;

    isRefreshing.value = true;

    try {
        await loadSandboxAssignment();
        handleSuccess(t('p_modal_sandbox_status_refreshed'));
    } catch (error) {
        console.error('Failed to refresh sandbox info:', error);
        handleError(error, t('p_modal_error_refreshing'));
    } finally {
        isRefreshing.value = false;
    }
};

// Lifecycle
onMounted(() => {
    if (props.preset?.id && page.props.sandboxEnabled) {
        loadSandboxAssignment();
    }
});

// Watch for preset changes
watch(() => props.preset?.id, (newId) => {
    if (newId && page.props.sandboxEnabled) {
        loadSandboxAssignment();
    } else {
        sandboxAssignment.value = null;
    }
});
</script>
