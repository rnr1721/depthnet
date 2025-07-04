<template>
    <!-- Sandbox Grid -->
    <div v-if="sandboxes.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <div v-for="sandbox in sandboxes" :key="sandbox.id" :class="[
            'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all duration-200',
            'hover:shadow-2xl hover:scale-105',
            isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
        ]">
            <!-- Sandbox Header -->
            <div :class="[
                'px-6 py-4 border-b',
                isDark ? 'border-gray-700' : 'border-gray-200'
            ]">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div>
                            <h3 :class="[
                                'font-semibold',
                                isDark ? 'text-white' : 'text-gray-900'
                            ]">{{ sandbox.id }}</h3>
                            <p :class="[
                                'text-sm',
                                isDark ? 'text-gray-400' : 'text-gray-600'
                            ]">{{ sandbox.type }}</p>
                        </div>
                    </div>
                    <span :class="getSandboxStatusClass(sandbox.status)">
                        {{ sandbox.status }}
                    </span>
                </div>
            </div>

            <!-- Sandbox Details -->
            <div class="px-6 py-4">
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span :class="[
                            'text-sm font-medium',
                            isDark ? 'text-gray-300' : 'text-gray-700'
                        ]">{{ t('hv_name') }}:</span>
                        <span :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">{{ sandbox.name || sandbox.id }}</span>
                    </div>

                    <!-- Ports -->
                    <div class="flex justify-between">
                        <span :class="[
                            'text-sm font-medium',
                            isDark ? 'text-gray-300' : 'text-gray-700'
                        ]">{{ t('hv_ports') }}:</span>
                        <div class="flex flex-col items-end space-y-1">
                            <!-- No ports -->
                            <span v-if="!sandboxHasPorts(sandbox)" :class="[
                                'text-sm',
                                isDark ? 'text-gray-500' : 'text-gray-500'
                            ]">{{ t('hv_no_ports') }}</span>

                            <!-- Port list -->
                            <div v-else class="flex flex-wrap gap-1 justify-end">
                                <a v-for="port in getSandboxPorts(sandbox)" :key="port" :href="getPortUrl(port)"
                                    target="_blank" rel="noopener noreferrer" :class="[
                                        'inline-flex items-center px-2 py-1 rounded text-xs font-medium transition-colors',
                                        'hover:scale-105 transform duration-150',
                                        isDark
                                            ? 'bg-blue-900 text-blue-200 hover:bg-blue-800'
                                            : 'bg-blue-100 text-blue-800 hover:bg-blue-200'
                                    ]" :title="t('hv_open_port', { port })">
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14">
                                        </path>
                                    </svg>
                                    {{ port }}
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Sandbox Actions -->
            <div :class="[
                'px-6 py-4 border-t',
                isDark ? 'border-gray-700 bg-gray-800' : 'border-gray-200 bg-gray-50'
            ]">
                <div class="flex items-center justify-center space-x-2">
                    <!-- Start/Stop buttons -->
                    <button v-if="sandbox.status === 'stopped'" @click="$emit('start', sandbox.id)" :disabled="loading"
                        :class="[
                            'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-green-900 text-green-400 hover:bg-green-800'
                                : 'bg-green-100 text-green-600 hover:bg-green-200'
                        ]" :title="t('hv_start')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M19 10a9 9 0 11-18 0 9 9 0 0118 0z">
                            </path>
                        </svg>
                    </button>

                    <button v-if="sandbox.status === 'running'" @click="$emit('stop', sandbox.id)" :disabled="loading"
                        :class="[
                            'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-orange-900 text-orange-400 hover:bg-orange-800'
                                : 'bg-orange-100 text-orange-600 hover:bg-orange-200'
                        ]" :title="t('hv_stop')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10h6v4H9z">
                            </path>
                        </svg>
                    </button>

                    <button @click="$emit('executeCommand', sandbox.id)" :disabled="sandbox.status !== 'running'"
                        :class="[
                            'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-blue-900 text-blue-400 hover:bg-blue-800'
                                : 'bg-blue-100 text-blue-600 hover:bg-blue-200'
                        ]" :title="t('hv_execute_command')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 9l3 3-3 3m5 0h3"></path>
                        </svg>
                    </button>

                    <button @click="$emit('executeCode', sandbox.id)" :disabled="sandbox.status !== 'running'" :class="[
                        'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        isDark
                            ? 'bg-purple-900 text-purple-400 hover:bg-purple-800'
                            : 'bg-purple-100 text-purple-600 hover:bg-purple-200'
                    ]" :title="t('hv_execute_code')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                        </svg>
                    </button>

                    <button @click="$emit('reset', sandbox.id)" :disabled="loading" :class="[
                        'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        isDark
                            ? 'bg-yellow-900 text-yellow-400 hover:bg-yellow-800'
                            : 'bg-yellow-100 text-yellow-600 hover:bg-yellow-200'
                    ]" :title="t('hv_reset')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                    </button>

                    <button @click="$emit('destroy', sandbox.id)" :disabled="loading" :class="[
                        'p-2 rounded-lg transition-all duration-200 hover:scale-110',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        isDark
                            ? 'bg-red-900 text-red-400 hover:bg-red-800'
                            : 'bg-red-100 text-red-600 hover:bg-red-200'
                    ]" :title="t('hv_destroy')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                            </path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!$page?.props?.error" :class="[
        'backdrop-blur-sm border shadow-xl rounded-2xl p-12 text-center',
        isDark
            ? 'bg-gray-800 bg-opacity-90 border-gray-700'
            : 'bg-white bg-opacity-90 border-gray-200'
    ]">
        <div
            class="w-24 h-24 mx-auto mb-6 bg-gradient-to-br from-cyan-500 to-purple-600 rounded-2xl flex items-center justify-center">
            <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10">
                </path>
            </svg>
        </div>
        <h3 :class="[
            'text-2xl font-bold mb-4',
            isDark ? 'text-white' : 'text-gray-900'
        ]">{{ t('hv_no_sandboxes') }}</h3>
        <p :class="[
            'text-lg mb-8',
            isDark ? 'text-gray-400' : 'text-gray-600'
        ]">{{ t('hv_create_first_sandbox') }}</p>
        <button @click="$emit('createSandbox')" :class="[
            'px-8 py-4 rounded-xl font-semibold transition-all duration-200 transform',
            'focus:outline-none focus:ring-2 focus:ring-offset-2',
            'hover:scale-105 active:scale-95',
            'bg-gradient-to-r from-cyan-600 to-purple-600 hover:from-cyan-700 hover:to-purple-700',
            'text-white shadow-lg',
            isDark ? 'focus:ring-cyan-500 focus:ring-offset-gray-800' : 'focus:ring-cyan-500'
        ]">
            <span class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                {{ t('hv_create_sandbox') }}
            </span>
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    sandboxes: {
        type: Array,
        required: true
    },
    isDark: {
        type: Boolean,
        default: false
    },
    loading: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits([
    'start',
    'stop',
    'executeCommand',
    'executeCode',
    'reset',
    'destroy',
    'createSandbox'
]);

const { t } = useI18n();
const page = usePage();

const config = computed(() => page.props.config || {});

/**
 * Get CSS classes for sandbox status badge
 */
const getSandboxStatusClass = (status) => {
    const baseClass = 'px-2 py-1 rounded-full text-xs font-medium ';
    const statusClasses = {
        running: props.isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800',
        stopped: props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-800',
        starting: props.isDark ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-800',
        stopping: props.isDark ? 'bg-orange-900 text-orange-200' : 'bg-orange-100 text-orange-800'
    };
    return baseClass + (statusClasses[status] || statusClasses.stopped);
};

const getPortUrl = (port) => {
    let baseHost = config.value.baseHost || 'localhost';
    let scheme = config.value.scheme || 'http';

    if (baseHost === 'localhost') {
        baseHost = window.location.hostname;
        scheme = window.location.protocol.slice(0, -1);
    }

    return `${scheme}://${baseHost}:${port}`;
};

/**
 * Get ports for specific sandbox
 */
const getSandboxPorts = (sandbox) => {
    // Try to get ports from different sources
    const ports = sandbox.ports ||
        sandbox.metadata?.ports ||
        [];

    // Filter and sort
    return Array.isArray(ports)
        ? ports.filter(port => port && typeof port === 'number').sort((a, b) => a - b)
        : [];
};

/**
 * Check if sandbox has ports
 */
const sandboxHasPorts = (sandbox) => {
    const ports = getSandboxPorts(sandbox);
    return ports && ports.length > 0;
};

</script>
