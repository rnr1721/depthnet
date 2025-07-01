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
                            'relative w-full max-w-4xl max-h-[90vh] rounded-2xl shadow-2xl backdrop-blur-sm border overflow-hidden',
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
                                            class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M8 9l3 3-3 3m5 0h3"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 :class="[
                                                'text-2xl font-bold',
                                                isDark ? 'text-white' : 'text-gray-900'
                                            ]">{{ t('hv_execute_command') }}</h3>
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
                            <div class="flex flex-col h-[70vh]">
                                <!-- Command Input -->
                                <div class="p-6 border-b" :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                                    <form @submit.prevent="executeCommand" class="space-y-4">
                                        <div class="flex space-x-4">
                                            <!-- Command input -->
                                            <div class="flex-1">
                                                <label :class="[
                                                    'block text-sm font-medium mb-2',
                                                    isDark ? 'text-white' : 'text-gray-900'
                                                ]">
                                                    {{ t('hv_command') }}
                                                </label>
                                                <div class="relative">
                                                    <div :class="[
                                                        'absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none',
                                                        isDark ? 'text-gray-400' : 'text-gray-500'
                                                    ]">
                                                        <span class="text-sm font-mono">$</span>
                                                    </div>
                                                    <input v-model="form.command" type="text" :class="[
                                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all font-mono',
                                                        'pl-8 pr-4 py-3 text-sm',
                                                        form.errors.command
                                                            ? 'ring-red-500 focus:ring-red-500'
                                                            : 'ring-gray-300 focus:ring-blue-500',
                                                        isDark
                                                            ? (form.errors.command
                                                                ? 'bg-red-900 bg-opacity-20 text-white placeholder-gray-400'
                                                                : 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600')
                                                            : (form.errors.command
                                                                ? 'bg-red-50 text-gray-900 placeholder-gray-500'
                                                                : 'bg-gray-50 text-gray-900 placeholder-gray-500')
                                                    ]" :placeholder="t('hv_command_placeholder')"
                                                        @keydown.enter="executeCommand" />
                                                </div>
                                                <div v-if="form.errors.command" class="text-red-500 text-sm mt-1">
                                                    {{ form.errors.command }}
                                                </div>
                                            </div>

                                            <!-- User select -->
                                            <div class="w-40">
                                                <label :class="[
                                                    'block text-sm font-medium mb-2',
                                                    isDark ? 'text-white' : 'text-gray-900'
                                                ]">
                                                    {{ t('hv_user') }}
                                                </label>
                                                <select v-model="form.user" :class="[
                                                    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-blue-500 transition-all',
                                                    'px-4 py-3 text-sm',
                                                    isDark
                                                        ? 'bg-gray-700 text-white ring-gray-600'
                                                        : 'bg-gray-50 text-gray-900 ring-gray-300'
                                                ]">
                                                    <option v-for="user in availableUsers" :key="user.value"
                                                        :value="user.value">
                                                        {{ user.label }}
                                                    </option>
                                                </select>
                                            </div>

                                            <!-- Timeout -->
                                            <div class="w-32">
                                                <label :class="[
                                                    'block text-sm font-medium mb-2',
                                                    isDark ? 'text-white' : 'text-gray-900'
                                                ]">
                                                    {{ t('hv_timeout') }}
                                                </label>
                                                <input v-model.number="form.timeout" type="number" min="1" max="300"
                                                    :class="[
                                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all',
                                                        'px-4 py-3 text-sm',
                                                        isDark
                                                            ? 'bg-gray-700 text-white ring-gray-600 focus:ring-blue-500'
                                                            : 'bg-gray-50 text-gray-900 ring-gray-300 focus:ring-blue-500'
                                                    ]" />
                                            </div>

                                            <!-- Execute button -->
                                            <div class="flex items-end">
                                                <button type="submit" :disabled="!form.command || executing" :class="[
                                                    'px-6 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                                                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                                    'enabled:hover:scale-105 enabled:active:scale-95',
                                                    isDark
                                                        ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-offset-gray-800'
                                                        : 'bg-blue-600 hover:bg-blue-700 text-white'
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
                                                    <span v-else>{{ t('hv_execute') }}</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Quick commands -->
                                        <div>
                                            <label :class="[
                                                'block text-sm font-medium mb-2',
                                                isDark ? 'text-white' : 'text-gray-900'
                                            ]">
                                                {{ t('hv_quick_commands') }}
                                            </label>
                                            <div class="flex flex-wrap gap-2">
                                                <button v-for="cmd in quickCommands" :key="cmd.command" type="button"
                                                    @click="form.command = cmd.command" :class="[
                                                        'px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                                        isDark
                                                            ? 'bg-gray-700 text-gray-300 hover:bg-gray-600'
                                                            : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                                                    ]">
                                                    {{ cmd.label }}
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Error message -->
                                        <div v-if="errorMessage" :class="[
                                            'p-3 rounded-lg border-l-4',
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
                                    </form>
                                </div>

                                <!-- Output -->
                                <div class="flex-1 flex flex-col min-h-0">
                                    <div class="flex items-center justify-between px-6 py-3 border-b"
                                        :class="isDark ? 'border-gray-700' : 'border-gray-200'">
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

                                    <div class="flex-1 overflow-y-auto">
                                        <div v-if="commandHistory.length === 0" :class="[
                                            'p-6 text-center',
                                            isDark ? 'text-gray-400' : 'text-gray-500'
                                        ]">
                                            <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none"
                                                stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M8 9l3 3-3 3m5 0h3"></path>
                                            </svg>
                                            <p>{{ t('hv_no_commands_executed') }}</p>
                                        </div>

                                        <!-- Command history -->
                                        <div v-else class="p-4 space-y-4">
                                            <div v-for="(result, index) in commandHistory" :key="index" :class="[
                                                'rounded-lg border',
                                                isDark ? 'border-gray-600 bg-gray-750' : 'border-gray-200 bg-gray-50'
                                            ]">
                                                <!-- Command -->
                                                <div :class="[
                                                    'px-4 py-2 border-b font-mono text-sm flex items-center justify-between',
                                                    isDark ? 'border-gray-600 bg-gray-700' : 'border-gray-200 bg-gray-100'
                                                ]">
                                                    <span :class="isDark ? 'text-green-400' : 'text-green-600'">
                                                        $ {{ result.command }}
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
const props = defineProps({
    show: Boolean,
    isDark: Boolean,
    sandboxId: String
});

const emit = defineEmits(['close']);

import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const form = ref({
    command: '',
    user: 'sandbox-user',
    timeout: 30,
    errors: {}
});

const executing = ref(false);
const errorMessage = ref('');
const commandHistory = ref([]);
const lastResult = ref(null);

const availableUsers = [
    { value: 'sandbox-user', label: 'sandbox-user' },
    { value: 'root', label: 'root' }
];

const quickCommands = [
    { label: 'ls -la', command: 'ls -la' },
    { label: 'pwd', command: 'pwd' },
    { label: 'whoami', command: 'whoami' },
    { label: 'ps aux', command: 'ps aux' },
    { label: 'df -h', command: 'df -h' },
    { label: 'free -h', command: 'free -h' },
    { label: 'python --version', command: 'python --version' },
    { label: 'node --version', command: 'node --version' },
    { label: 'php --version', command: 'php --version' }
];

const executeCommand = async () => {
    if (!form.value.command.trim()) return;

    executing.value = true;
    errorMessage.value = '';
    form.value.errors = {};

    try {
        const response = await axios.post(`/admin/sandboxes/${props.sandboxId}/execute-command`, {
            command: form.value.command,
            user: form.value.user,
            timeout: form.value.timeout
        });

        if (response.data.success) {
            const result = {
                command: form.value.command,
                ...response.data.data,
                timestamp: new Date()
            };

            commandHistory.value.unshift(result);
            lastResult.value = result;
            form.value.command = ''; // Clear input
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
    commandHistory.value = [];
    lastResult.value = null;
};

const resetForm = () => {
    form.value = {
        command: '',
        user: 'sandbox-user',
        timeout: 30,
        errors: {}
    };
    errorMessage.value = '';
    commandHistory.value = [];
    lastResult.value = null;
};

// Reset when modal closes
watch(() => props.show, (newValue) => {
    if (!newValue) {
        setTimeout(resetForm, 300);
    }
});
</script>
