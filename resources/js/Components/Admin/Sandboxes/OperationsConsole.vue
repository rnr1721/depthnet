<template>
    <div :class="[
        'mt-8 rounded-2xl border shadow-xl overflow-hidden transition-all duration-300',
        isDark
            ? 'bg-gray-800 bg-opacity-90 border-gray-700'
            : 'bg-white bg-opacity-90 border-gray-200'
    ]">
        <!-- Header -->
        <div :class="[
            'px-6 py-4 border-b cursor-pointer',
            isDark ? 'border-gray-700' : 'border-gray-200'
        ]" @click="isExpanded = !isExpanded">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div
                        class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <h3 :class="[
                            'font-semibold',
                            isDark ? 'text-white' : 'text-gray-900'
                        ]">
                            {{ t('hv_operations_console') }}
                        </h3>
                        <p :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">
                            {{ activeOperations.length }} {{ t('hv_active') }}, {{ completedOperations.length }} {{
                                t('hv_completed') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center space-x-2">
                    <!-- Clear completed button -->
                    <button v-if="completedOperations.length > 0" @click.stop="clearCompletedOperations" :class="[
                        'p-2 rounded-lg transition-colors',
                        isDark
                            ? 'hover:bg-gray-700 text-gray-400 hover:text-white'
                            : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700'
                    ]" :title="t('hv_clear_completed')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>

                    <!-- Expand/collapse button -->
                    <div :class="[
                        'p-2 rounded-lg transition-colors',
                        isDark ? 'text-gray-400' : 'text-gray-500'
                    ]">
                        <svg v-if="isExpanded" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        </svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <Transition enter-active-class="transition-all duration-300" enter-from-class="max-h-0"
            enter-to-class="max-h-96" leave-active-class="transition-all duration-300" leave-from-class="max-h-96"
            leave-to-class="max-h-0">
            <div v-if="isExpanded" class="max-h-96 overflow-hidden flex">
                <!-- Operations List -->
                <div :class="[
                    'w-1/2 border-r overflow-y-auto',
                    isDark ? 'border-gray-700' : 'border-gray-200'
                ]">
                    <div v-if="operations.length === 0" class="p-6 text-center">
                        <p :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">
                            {{ t('hv_no_operations') }}
                        </p>
                    </div>

                    <div v-else class="p-4 space-y-2">
                        <div v-for="operation in operations" :key="operation.id" @click="selectedOperation = operation"
                            :class="[
                                'p-3 rounded-lg cursor-pointer transition-all duration-200',
                                selectedOperation?.id === operation.id
                                    ? (isDark ? 'bg-gray-700 border border-gray-600' : 'bg-gray-100 border border-gray-300')
                                    : (isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-50')
                            ]">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center space-x-2">
                                    <!-- Status Icon -->
                                    <div :class="getStatusIconClass(operation.status)">
                                        <!-- Clock Icon - Pending -->
                                        <svg v-if="operation.status === 'pending'" class="w-4 h-4" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12,6 12,12 16,14"></polyline>
                                        </svg>
                                        <!-- Play Icon - Processing -->
                                        <svg v-else-if="operation.status === 'processing'" class="w-4 h-4" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <polygon points="5,3 19,12 5,21"></polygon>
                                        </svg>
                                        <!-- Check Circle - Completed -->
                                        <svg v-else-if="operation.status === 'completed'" class="w-4 h-4" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="m9 12 2 2 4-4"></path>
                                            <circle cx="12" cy="12" r="10"></circle>
                                        </svg>
                                        <!-- X Circle - Failed -->
                                        <svg v-else-if="operation.status === 'failed'" class="w-4 h-4" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <path d="m15 9-6 6"></path>
                                            <path d="m9 9 6 6"></path>
                                        </svg>
                                        <!-- Default Square Icon -->
                                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <rect width="18" height="18" x="3" y="3" rx="2"></rect>
                                        </svg>
                                    </div>
                                    <span :class="[
                                        'text-sm font-medium',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ operation.type_name }}
                                    </span>
                                </div>
                                <span :class="['text-xs', getStatusColor(operation.status)]">
                                    {{ operation.status }}
                                </span>
                            </div>

                            <p v-if="operation.sandbox_id" :class="[
                                'text-xs mb-2',
                                isDark ? 'text-gray-400' : 'text-gray-600'
                            ]">
                                Sandbox: {{ operation.sandbox_id }}
                            </p>

                            <p :class="[
                                'text-xs mb-2',
                                isDark ? 'text-gray-300' : 'text-gray-700'
                            ]">
                                {{ operation.message }}
                            </p>

                            <!-- Progress bar -->
                            <div :class="[
                                'w-full h-1 rounded-full mb-2',
                                isDark ? 'bg-gray-600' : 'bg-gray-200'
                            ]">
                                <div :class="['h-1 rounded-full transition-all duration-300', getProgressBarColor(operation.status)]"
                                    :style="{ width: `${operation.progress}%` }" />
                            </div>

                            <div class="flex justify-between text-xs">
                                <span :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                    {{ formatTime(operation.created_at) }}
                                </span>
                                <span v-if="operation.completed_at" :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                    {{ t('hv_completed') }}: {{ formatTime(operation.completed_at) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Logs Panel -->
                <div class="w-1/2">
                    <div v-if="selectedOperation" class="h-full flex flex-col">
                        <!-- Logs header -->
                        <div :class="[
                            'px-4 py-3 border-b',
                            isDark ? 'border-gray-700' : 'border-gray-200'
                        ]">
                            <div class="flex items-center justify-between">
                                <h4 :class="[
                                    'font-medium',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">
                                    {{ t('hv_operation_logs') }}
                                </h4>
                            </div>
                        </div>

                        <!-- Logs content -->
                        <div class="flex-1 overflow-y-auto p-4" ref="logsContainer">
                            <div v-if="selectedOperation.logs && selectedOperation.logs.length > 0"
                                class="space-y-1 font-mono text-sm">
                                <div v-for="(log, index) in selectedOperation.logs" :key="index" class="flex space-x-2">
                                    <span :class="[
                                        'text-xs',
                                        isDark ? 'text-gray-500' : 'text-gray-400'
                                    ]">
                                        {{ formatLogTime(log.timestamp) }}
                                    </span>
                                    <span :class="[
                                        'text-xs px-1 rounded',
                                        getLogLevelClass(log.level)
                                    ]">
                                        {{ log.level }}
                                    </span>
                                    <span :class="isDark ? 'text-gray-300' : 'text-gray-700'">
                                        {{ log.message }}
                                    </span>
                                </div>
                                <div ref="logsEnd" />
                            </div>
                            <p v-else :class="[
                                'text-sm text-center',
                                isDark ? 'text-gray-400' : 'text-gray-600'
                            ]">
                                {{ t('hv_no_logs_available') }}
                            </p>
                        </div>
                    </div>

                    <div v-else class="h-full flex items-center justify-center">
                        <p :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">
                            {{ t('hv_select_operation_to_view_logs') }}
                        </p>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const props = defineProps({
    initialOperations: {
        type: Array,
        default: () => []
    },
    isDark: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['operationCompleted', 'operationUpdated']);

const { t } = useI18n();

const operations = ref([...props.initialOperations]);
const isExpanded = ref(true);
const selectedOperation = ref(null);
const logsContainer = ref(null);
const logsEnd = ref(null);

let pollInterval = null;

const activeOperations = computed(() =>
    operations.value.filter(op => ['pending', 'processing'].includes(op.status))
);

const completedOperations = computed(() =>
    operations.value.filter(op => ['completed', 'failed'].includes(op.status))
);

const startPolling = () => {
    pollInterval = setInterval(async () => {
        try {
            const response = await axios.get('/admin/sandboxes/operations/recent');
            if (response.data.success) {
                const newOperations = response.data.data || [];

                const oldOperations = operations.value;
                newOperations.forEach(newOp => {
                    const oldOp = oldOperations.find(op => op.id === newOp.id);

                    if (oldOp && oldOp.status !== newOp.status && ['completed', 'failed'].includes(newOp.status)) {
                        console.log('Operation completed:', newOp.type, newOp.status);
                        emit('operationCompleted', newOp);
                    }

                    if (oldOp && oldOp.status !== newOp.status) {
                        emit('operationUpdated', newOp);
                    }
                });

                operations.value = newOperations;

                if (selectedOperation.value) {
                    const updated = operations.value.find(op => op.id === selectedOperation.value.id);
                    if (updated) {
                        selectedOperation.value = updated;
                    }
                }
            }
        } catch (error) {
            console.error('Failed to fetch operations:', error);
        }
    }, 2000);
};

const stopPolling = () => {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

const getStatusIconClass = (status) => {
    const statusClasses = {
        pending: 'text-yellow-500',
        processing: 'text-blue-500 animate-pulse',
        completed: 'text-green-500',
        failed: 'text-red-500'
    };
    return statusClasses[status] || 'text-gray-500';
};

const getStatusColor = (status) => {
    const colors = {
        pending: props.isDark ? 'text-yellow-400' : 'text-yellow-600',
        processing: props.isDark ? 'text-blue-400' : 'text-blue-600',
        completed: props.isDark ? 'text-green-400' : 'text-green-600',
        failed: props.isDark ? 'text-red-400' : 'text-red-600'
    };
    return colors[status] || (props.isDark ? 'text-gray-400' : 'text-gray-600');
};

const getProgressBarColor = (status) => {
    const colors = {
        processing: 'bg-blue-500',
        completed: 'bg-green-500',
        failed: 'bg-red-500'
    };
    return colors[status] || 'bg-gray-500';
};

const getLogLevelClass = (level) => {
    const baseClass = 'text-xs px-1 rounded ';
    const levelClasses = {
        error: props.isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800',
        warning: props.isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800',
        info: props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-700'
    };
    return baseClass + (levelClasses[level] || levelClasses.info);
};

const formatTime = (timestamp) => {
    if (!timestamp) return '';
    return new Date(timestamp).toLocaleTimeString();
};

const formatLogTime = (timestamp) => {
    if (!timestamp) return '';
    return new Date(timestamp).toLocaleTimeString();
};

const clearCompletedOperations = async () => {
    try {
        const response = await axios.post('/admin/sandboxes/operations/clear');

        if (response.data.success) {
            operations.value = operations.value.filter(op => !['completed', 'failed'].includes(op.status));
            selectedOperation.value = null;
        }
    } catch (error) {
        console.error('Failed to clear operations:', error);
    }
};

watch(() => operations.value, (newOps) => {
    if (selectedOperation.value && !newOps.find(op => op.id === selectedOperation.value.id)) {
        selectedOperation.value = null;
    }
}, { deep: true });

onMounted(() => {
    startPolling();
});

onUnmounted(() => {
    stopPolling();
});

defineExpose({
    addOperation: (operation) => {
        operations.value.unshift(operation);
    },
    updateOperation: (updatedOperation) => {
        const index = operations.value.findIndex(op => op.operation_id === updatedOperation.operation_id);
        if (index !== -1) {
            operations.value[index] = updatedOperation;
        }
    }
});
</script>
