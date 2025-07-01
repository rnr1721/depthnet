<template>
    <PageTitle :title="t('plugins_page_title')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('plugins_management')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-purple-500' : 'bg-purple-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-blue-500' : 'bg-blue-300'
                ]"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Success/Error messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark
                            ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200'
                            : 'bg-green-50 border-green-400 text-green-800'
                    ]">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.success }}</span>
                        </div>
                    </div>
                </Transition>

                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.error || errorMessage" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
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
                            <span class="font-medium">{{ $page.props.flash.error || errorMessage }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Statistics Cards -->
                <PluginStats :statistics="statistics" :health_status="health_status" :isDark="isDark" />

                <!-- Header with actions -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
                    <div>
                        <h2 :class="[
                            'text-2xl font-bold mb-2',
                            isDark ? 'text-white' : 'text-gray-900'
                        ]">{{ t('plugins_manage_plugins') }}</h2>
                        <p :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">{{ t('plugins_manage_description') }}</p>
                    </div>

                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
                        <!-- Refresh Button -->
                        <button @click="refreshPlugins" :disabled="loading" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500 focus:ring-offset-gray-900'
                                : 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500'
                        ]">
                            <svg v-if="loading" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            {{ t('plugins_refresh') }}
                        </button>

                        <!-- Health Check Button -->
                        <button @click="runHealthCheck" :disabled="healthChecking" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark
                                ? 'bg-green-600 hover:bg-green-700 text-white focus:ring-green-500 focus:ring-offset-gray-900'
                                : 'bg-green-600 hover:bg-green-700 text-white focus:ring-green-500'
                        ]">
                            <svg v-if="healthChecking" class="w-4 h-4 mr-2 animate-spin" fill="none"
                                viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z">
                                </path>
                            </svg>
                            {{ t('plugins_health_check') }}
                        </button>

                        <!-- Route Link to Engines -->
                        <Link :href="route('admin.engines.index')" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                            isDark
                                ? 'bg-gray-700 hover:bg-gray-800 text-white focus:ring-gray-500 focus:ring-offset-gray-900'
                                : 'bg-gray-200 hover:bg-gray-300 text-gray-800 focus:ring-gray-400'
                        ]">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                            </path>
                        </svg>
                        {{ t('plugins_engines') }}
                        </Link>

                        <!-- Route Link to Presets -->
                        <Link :href="route('admin.presets.index')" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                            isDark
                                ? 'bg-gray-700 hover:bg-gray-800 text-white focus:ring-gray-500 focus:ring-offset-gray-900'
                                : 'bg-gray-200 hover:bg-gray-300 text-gray-800 focus:ring-gray-400'
                        ]">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                            </path>
                        </svg>
                        {{ t('plugins_presets') }}
                        </Link>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-12">
                    <svg class="w-8 h-8 animate-spin mx-auto mb-4" :class="isDark ? 'text-gray-400' : 'text-gray-600'"
                        fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('plugins_loading') }}
                    </p>
                </div>

                <!-- Empty State -->
                <div v-else-if="plugins.length === 0" :class="[
                    'text-center py-16 backdrop-blur-sm border rounded-2xl',
                    isDark ? 'bg-gray-800 bg-opacity-50 border-gray-700' : 'bg-white bg-opacity-50 border-gray-200'
                ]">
                    <div
                        class="w-16 h-16 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-2xl mx-auto mb-4 flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-semibold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('plugins_no_plugins') }}
                    </h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('plugins_no_plugins_description') }}
                    </p>
                    <button @click="refreshPlugins" :class="[
                        'inline-flex items-center px-6 py-3 rounded-xl text-sm font-medium transition-all transform hover:scale-105',
                        isDark
                            ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                            : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                    ]">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                        {{ t('plugins_refresh') }}
                    </button>
                </div>

                <!-- Plugins Grid -->
                <div v-else class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                    <PluginCard v-for="plugin in plugins" :key="plugin.name" :plugin="plugin" :isDark="isDark"
                        :updateConfigFn="handleUpdateConfig" @toggle="handleTogglePlugin" @test="handleTestPlugin"
                        @resetConfig="handleResetConfig" />
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import PageTitle from '@/Components/PageTitle.vue';
import AdminHeader from '@/Components/AdminHeader.vue';
import PluginStats from '@/Components/Admin/Plugins/PluginStats.vue';
import PluginCard from '@/Components/Admin/Plugins/PluginCard.vue';

const { t } = useI18n();

const props = defineProps({
    plugins: {
        type: Array,
        default: () => []
    },
    statistics: {
        type: Object,
        default: () => ({
            total_plugins: 0,
            enabled_plugins: 0,
            disabled_plugins: 0
        })
    },
    health_status: {
        type: Object,
        default: () => ({
            overall_status: 'unknown',
            plugins: {}
        })
    },
    error: {
        type: String,
        default: null
    }
});

const isDark = ref(false);
const loading = ref(false);
const healthChecking = ref(false);
const errorMessage = ref(props.error || '');
const plugins = ref(props.plugins);
const statistics = ref(props.statistics);
const health_status = ref(props.health_status);

const refreshPlugins = async () => {
    loading.value = true;
    errorMessage.value = '';

    try {
        router.reload({
            only: ['plugins', 'statistics', 'health_status'],
            onSuccess: (page) => {
                plugins.value = page.props.plugins;
                statistics.value = page.props.statistics;
                health_status.value = page.props.health_status;
            },
            onError: (errors) => {
                errorMessage.value = t('plugins_refresh_failed');
                console.error('Failed to refresh plugins:', errors);
            }
        });
    } finally {
        loading.value = false;
    }
};

const runHealthCheck = async () => {
    healthChecking.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get(route('admin.plugins.health'));
        health_status.value = response.data.data;

        await refreshPlugins();

    } catch (error) {
        errorMessage.value = t('plugins_health_check_failed');
        console.error('Health check failed:', error);
    } finally {
        healthChecking.value = false;
    }
};

const handleTogglePlugin = async (pluginName) => {
    try {
        const response = await axios.post(route('admin.plugins.toggle', { pluginName }));

        if (response.data.success) {
            const newEnabledState = response.data.data?.enabled;

            const pluginIndex = plugins.value.findIndex(p => p.name === pluginName);
            if (pluginIndex !== -1) {
                plugins.value[pluginIndex].enabled = newEnabledState;
            }

            if (newEnabledState) {
                statistics.value.enabled_plugins++;
                statistics.value.disabled_plugins--;
            } else {
                statistics.value.enabled_plugins--;
                statistics.value.disabled_plugins++;
            }

            return { enabled: newEnabledState };
        }

    } catch (error) {
        errorMessage.value = t('plugins_toggle_failed', { plugin: pluginName });
        console.error(`Failed to toggle plugin ${pluginName}:`, error);
        throw error;
    }
};

const handleTestPlugin = async (pluginName) => {
    try {
        const response = await axios.post(route('admin.plugins.test', { pluginName }));
        return response.data.data;

    } catch (error) {
        errorMessage.value = t('plugins_test_failed', { plugin: pluginName });
        console.error(`Failed to test plugin ${pluginName}:`, error);

        return {
            is_working: false,
            message: error.response?.data?.message || t('plugins_test_error')
        };
    }
};

const handleUpdateConfig = async (pluginName, config) => {
    try {
        console.log('Parent: Updating config for', pluginName, config);

        const response = await axios.post(route('admin.plugins.update', { pluginName }), config);

        if (response.data.success) {
            const pluginIndex = plugins.value.findIndex(p => p.name === pluginName);
            if (pluginIndex !== -1) {
                plugins.value[pluginIndex].current_config = response.data.data.config;
            }
            return {
                success: true,
                config: response.data.data.config
            };
        }

    } catch (error) {
        console.error(`Failed to update plugin ${pluginName} config:`, error);

        if (error.response?.status === 422) {
            return {
                success: false,
                errors: error.response.data.errors || {},
                message: error.response.data.message || 'Configuration validation failed'
            };
        } else {
            errorMessage.value = t('plugins_update_failed', { plugin: pluginName });

            return {
                success: false,
                errors: {},
                message: 'Failed to save configuration. Please try again.'
            };
        }
    }
};

const handleResetConfig = async (pluginName) => {
    try {
        const response = await axios.post(route('admin.plugins.reset', { pluginName }));

        if (response.data.success) {
            const pluginIndex = plugins.value.findIndex(p => p.name === pluginName);
            if (pluginIndex !== -1) {
                plugins.value[pluginIndex].current_config = response.data.data.config;
            }

            return response.data.data;
        }

    } catch (error) {
        errorMessage.value = t('plugins_reset_failed', { plugin: pluginName });
        console.error(`Failed to reset plugin ${pluginName} config:`, error);
        throw error;
    }
};

// Theme management
onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }

    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });

    if (props.error) {
        errorMessage.value = props.error;
    }
});
</script>