<template>
    <PageTitle :title="t('hv_sandbox_manager')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('hv_sandbox_hypervisor')" :isAdmin="true"
            :sandbox-enabled="$page.props.sandboxEnabled" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-cyan-500' : 'bg-cyan-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-purple-500' : 'bg-purple-300'
                ]"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Success/Error Messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="notification" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        notification.type === 'success'
                            ? (isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800')
                            : (isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800')
                    ]">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path v-if="notification.type === 'success'" fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd"></path>
                                    <path v-else fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                        clip-rule="evenodd"></path>
                                </svg>
                                <span class="font-medium">{{ notification.message }}</span>
                            </div>
                            <button @click="notification = null" class="text-current opacity-70 hover:opacity-100">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                        clip-rule="evenodd"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </Transition>

                <!-- Header Section -->
                <div :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all mb-8',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div :class="[
                        'px-6 py-8 border-b flex flex-col sm:flex-row sm:items-center sm:justify-between',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div class="flex items-center space-x-4 mb-4 sm:mb-0">
                            <div
                                class="w-16 h-16 bg-gradient-to-br from-cyan-500 to-purple-600 rounded-2xl flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10">
                                    </path>
                                </svg>
                            </div>
                            <div>
                                <h1 :class="[
                                    'text-3xl font-bold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">{{ t('hv_sandboxes') }}</h1>
                                <p :class="[
                                    'text-sm mt-1',
                                    isDark ? 'text-gray-400' : 'text-gray-600'
                                ]">{{ t('hv_sandbox_description') }}</p>
                            </div>
                        </div>

                        <div class="flex items-center space-x-3">
                            <!-- Stats -->
                            <div class="flex items-center space-x-4 mr-4">
                                <div class="text-center">
                                    <div :class="[
                                        'text-2xl font-bold',
                                        isDark ? 'text-cyan-400' : 'text-cyan-600'
                                    ]">{{ stats.total }}</div>
                                    <div :class="[
                                        'text-xs',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">{{ t('hv_total') }}</div>
                                </div>
                                <div class="text-center">
                                    <div :class="[
                                        'text-2xl font-bold',
                                        isDark ? 'text-green-400' : 'text-green-600'
                                    ]">{{ stats.running }}</div>
                                    <div :class="[
                                        'text-xs',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">{{ t('hv_running') }}</div>
                                </div>
                                <div class="text-center">
                                    <div :class="[
                                        'text-2xl font-bold',
                                        isDark ? 'text-red-400' : 'text-red-600'
                                    ]">{{ stats.stopped }}</div>
                                    <div :class="[
                                        'text-xs',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">{{ t('hv_stopped') }}</div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <button @click="refreshSandboxes" :disabled="loading" :class="[
                                'px-4 py-3 rounded-xl font-semibold transition-all duration-200',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                loading ? 'cursor-not-allowed opacity-50' : 'hover:scale-105 active:scale-95',
                                'bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-700 hover:to-gray-800',
                                'text-white shadow-lg',
                                isDark ? 'focus:ring-gray-500 focus:ring-offset-gray-800' : 'focus:ring-gray-500'
                            ]">
                                <span class="flex items-center">
                                    <svg :class="['w-5 h-5 mr-2', loading ? 'animate-spin' : '']" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                        </path>
                                    </svg>
                                    {{ t('hv_refresh') }}
                                </span>
                            </button>

                            <button @click="showCreateModal = true" :class="[
                                'px-6 py-3 rounded-xl font-semibold transition-all duration-200 transform',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                'hover:scale-105 active:scale-95',
                                'bg-gradient-to-r from-cyan-600 to-purple-600 hover:from-cyan-700 hover:to-purple-700',
                                'text-white shadow-lg',
                                isDark ? 'focus:ring-cyan-500 focus:ring-offset-gray-800' : 'focus:ring-cyan-500'
                            ]">
                                <span class="flex items-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    {{ t('hv_create_sandbox') }}
                                </span>
                            </button>

                            <button @click="cleanupAll" :disabled="loading" :class="[
                                'px-4 py-3 rounded-xl font-semibold transition-all duration-200',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                loading ? 'cursor-not-allowed opacity-50' : 'hover:scale-105 active:scale-95',
                                'bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800',
                                'text-white shadow-lg',
                                isDark ? 'focus:ring-red-500 focus:ring-offset-gray-800' : 'focus:ring-red-500'
                            ]">
                                <span class="flex items-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                        </path>
                                    </svg>
                                    {{ t('hv_cleanup_all') }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sandbox Grid Component -->
                <SandboxGrid :sandboxes="sandboxes" :isDark="isDark" :loading="loading" @start="startSandbox"
                    @stop="stopSandbox" @executeCommand="executeCommand" @executeCode="executeCode"
                    @reset="resetSandbox" @destroy="destroySandbox" @createSandbox="showCreateModal = true" />

                <!-- Error State -->
                <div v-if="$page.props.error" :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl p-12 text-center',
                    isDark
                        ? 'bg-red-900 bg-opacity-50 border-red-700'
                        : 'bg-red-50 border-red-300'
                ]">
                    <div
                        class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-red-500 to-red-600 rounded-2xl flex items-center justify-center">
                        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 :class="[
                        'text-2xl font-bold mb-4',
                        isDark ? 'text-red-200' : 'text-red-800'
                    ]">{{ t('hv_error_loading') }}</h3>
                    <p :class="[
                        'text-lg mb-8',
                        isDark ? 'text-red-300' : 'text-red-600'
                    ]">{{ $page.props.error }}</p>
                    <button @click="refreshSandboxes" :class="[
                        'px-8 py-4 rounded-xl font-semibold transition-all duration-200',
                        'bg-red-600 hover:bg-red-700 text-white'
                    ]">
                        {{ t('hv_try_again') }}
                    </button>
                </div>

                <!-- Operations Console -->
                <OperationsConsole ref="operationsConsole" :initialOperations="operations" :isDark="isDark"
                    @operationCompleted="handleOperationCompleted" @operationUpdated="handleOperationUpdated" />
            </div>
        </main>

        <!-- Modals -->
        <CreateSandboxModal :show="showCreateModal" :isDark="isDark" :sandboxTypes="sandboxTypes"
            @close="showCreateModal = false" @created="handleSandboxCreated" />

        <CommandModal :show="showCommandModal" :isDark="isDark" :sandboxId="selectedSandboxForCommand"
            @close="closeCommandModal" />

        <CodeModal :show="showCodeModal" :isDark="isDark" :sandboxId="selectedSandboxForCode" @close="closeCodeModal" />
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import PageTitle from '@/Components/PageTitle.vue';
import AdminHeader from '@/Components/AdminHeader.vue';
import CreateSandboxModal from '@/Components/Admin/Sandboxes/CreateSandboxModal.vue';
import CommandModal from '@/Components/Admin/Sandboxes/CommandModal.vue';
import CodeModal from '@/Components/Admin/Sandboxes/CodeModal.vue';
import OperationsConsole from '@/Components/Admin/Sandboxes/OperationsConsole.vue';
import SandboxGrid from '@/Components/Admin/Sandboxes/SandboxGrid.vue';

const props = defineProps({
    initialSandboxes: {
        type: Array,
        default: () => []
    },
    sandboxTypes: {
        type: Object,
        default: () => ({})
    },
    stats: {
        type: Object,
        default: () => ({ total: 0, running: 0, stopped: 0 })
    },
    recentOperations: {
        type: Array,
        default: () => []
    },
    isDark: {
        type: Boolean,
        default: false
    }
});

const { t } = useI18n();

const isDark = ref(props.isDark);
const loading = ref(false);
const notification = ref(null);
const showCreateModal = ref(false);
const showCommandModal = ref(false);
const showCodeModal = ref(false);
const selectedSandboxForCommand = ref(null);
const selectedSandboxForCode = ref(null);

const sandboxTypes = ref(props.sandboxTypes);
const sandboxes = ref([...props.initialSandboxes]);
const stats = ref({ ...props.stats });
const operations = ref([...props.recentOperations]);

const operationsConsole = ref(null);
const refreshInterval = ref(null);
const isAutoRefreshEnabled = ref(true);

// Constants
const SLOW_REFRESH_INTERVAL = 30000; // 30 sec
const FAST_REFRESH_INTERVAL = 3000;  // 3 sec


const hasActiveOperations = computed(() => {
    return operations.value.some(op => ['pending', 'processing'].includes(op.status));
});

const showNotification = (message, type = 'success') => {
    notification.value = { message, type };
    setTimeout(() => {
        notification.value = null;
    }, 5000);
};

const refreshSandboxes = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('admin.sandboxes.list'), {
            params: {
                include_all: true
            }
        });

        if (response.data.success) {
            sandboxes.value = response.data.data;
            updateStats();
        } else {
            throw new Error(response.data.message || 'Failed to load sandboxes');
        }
    } catch (error) {
        console.error('Failed to refresh sandboxes:', error);
        showNotification(t('hv_failed_to_load') + ': ' + error.message, 'error');
    } finally {
        loading.value = false;
    }
};

const updateStats = () => {
    stats.value = {
        total: sandboxes.value.length,
        running: sandboxes.value.filter(s => s.status === 'running').length,
        stopped: sandboxes.value.filter(s => s.status === 'stopped').length
    };
};

const startSmartRefresh = () => {
    const interval = hasActiveOperations.value ? FAST_REFRESH_INTERVAL : SLOW_REFRESH_INTERVAL;

    if (refreshInterval.value) {
        clearInterval(refreshInterval.value);
    }

    refreshInterval.value = setInterval(() => {
        if (isAutoRefreshEnabled.value) {
            refreshSandboxes();
        }
    }, interval);
};

const handleOperationStarted = (operationData) => {
    operations.value.unshift(operationData);
};

const handleOperationCompleted = (operation) => {
    console.log('Operation completed:', operation);

    if (['completed', 'failed'].includes(operation.status)) {
        setTimeout(() => {
            refreshSandboxes();
        }, 1000);
    }
};

const handleOperationUpdated = (operation) => {
    console.log('Operation updated:', operation);
    const index = operations.value.findIndex(op => op.operation_id === operation.operation_id);
    if (index !== -1) {
        operations.value[index] = operation;
    }
};

const startSandbox = async (sandboxId) => {
    if (!confirm(t('hv_confirm_start_sandbox'))) return;

    const sandboxIndex = sandboxes.value.findIndex(s => s.id === sandboxId);
    if (sandboxIndex !== -1) {
        sandboxes.value[sandboxIndex] = {
            ...sandboxes.value[sandboxIndex],
            status: 'starting'
        };
    }

    try {
        const response = await axios.post(route('admin.sandboxes.start', sandboxId));

        if (response.data.success) {
            showNotification(response.data.message);

            if (response.data.operation) {
                handleOperationStarted(response.data.operation);
            }
        } else {
            throw new Error(response.data.message);
        }
    } catch (error) {
        if (sandboxIndex !== -1) {
            sandboxes.value[sandboxIndex].status = 'stopped';
        }
        showNotification(t('hv_start_failed') + ': ' + error.message, 'error');
    }
};

const stopSandbox = async (sandboxId) => {
    if (!confirm(t('hv_confirm_stop_sandbox'))) return;

    const sandboxIndex = sandboxes.value.findIndex(s => s.id === sandboxId);
    if (sandboxIndex !== -1) {
        sandboxes.value[sandboxIndex] = {
            ...sandboxes.value[sandboxIndex],
            status: 'stopping'
        };
    }

    try {
        const response = await axios.post(route('admin.sandboxes.stop', sandboxId));

        if (response.data.success) {
            showNotification(response.data.message);

            if (response.data.operation) {
                handleOperationStarted(response.data.operation);
            }
        } else {
            throw new Error(response.data.message);
        }
    } catch (error) {
        if (sandboxIndex !== -1) {
            sandboxes.value[sandboxIndex].status = 'running';
        }
        showNotification(t('hv_stop_failed') + ': ' + error.message, 'error');
    }
};

const executeCommand = (sandboxId) => {
    selectedSandboxForCommand.value = sandboxId;
    showCommandModal.value = true;
};

const executeCode = (sandboxId) => {
    selectedSandboxForCode.value = sandboxId;
    showCodeModal.value = true;
};

const closeCommandModal = () => {
    showCommandModal.value = false;
    selectedSandboxForCommand.value = null;
};

const closeCodeModal = () => {
    showCodeModal.value = false;
    selectedSandboxForCode.value = null;
};

const handleSandboxCreated = (data) => {
    showNotification(data.message);

    if (data.operation) {
        handleOperationStarted(data.operation);
    }
};

const resetSandbox = async (sandboxId) => {
    if (!confirm(t('hv_confirm_reset_sandbox'))) return;

    try {
        const response = await axios.post(route('admin.sandboxes.reset', sandboxId));

        if (response.data.success) {
            showNotification(response.data.message);

            if (response.data.operation) {
                handleOperationStarted(response.data.operation);
            }
        } else {
            throw new Error(response.data.message);
        }
    } catch (error) {
        showNotification(t('hv_reset_failed') + ': ' + error.message, 'error');
    }
};

const destroySandbox = async (sandboxId) => {
    if (!confirm(t('hv_confirm_destroy_sandbox'))) return;

    try {
        const response = await axios.delete(route('admin.sandboxes.destroy', sandboxId));

        if (response.data.success) {
            showNotification(response.data.message);

            if (response.data.operation) {
                handleOperationStarted(response.data.operation);
            }
        } else {
            throw new Error(response.data.message);
        }
    } catch (error) {
        showNotification(t('hv_destroy_failed') + ': ' + error.message, 'error');
    }
};

const cleanupAll = async () => {
    if (!confirm(t('hv_confirm_cleanup_all'))) return;

    try {
        const response = await axios.post(route('admin.sandboxes.cleanup'));

        if (response.data.success) {
            showNotification(response.data.message);

            if (response.data.operation) {
                handleOperationStarted(response.data.operation);
            }
        } else {
            throw new Error(response.data.message);
        }
    } catch (error) {
        showNotification(t('hv_cleanup_failed') + ': ' + error.message, 'error');
    }
};

watch(hasActiveOperations, (hasActive) => {
    console.log(`Active operations: ${hasActive}, adjusting refresh rate`);
    startSmartRefresh();
});

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }

    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });

    refreshSandboxes();
    startSmartRefresh();
});

onUnmounted(() => {
    if (refreshInterval.value) {
        clearInterval(refreshInterval.value);
    }
});
</script>