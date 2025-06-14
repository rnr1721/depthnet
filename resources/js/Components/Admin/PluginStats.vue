<template>
    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <!-- Total Plugins -->
        <div :class="[
            'p-6 rounded-2xl border backdrop-blur-sm',
            isDark
                ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                : 'bg-white bg-opacity-90 border-gray-200'
        ]">
            <div class="flex items-center">
                <div
                    class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z">
                        </path>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ statistics.total_plugins }}
                    </h3>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('plugins_total_plugins') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Enabled Plugins -->
        <div :class="[
            'p-6 rounded-2xl border backdrop-blur-sm',
            isDark
                ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                : 'bg-white bg-opacity-90 border-gray-200'
        ]">
            <div class="flex items-center">
                <div
                    class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ statistics.enabled_plugins }}
                    </h3>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('plugins_enabled_plugins') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Disabled Plugins -->
        <div :class="[
            'p-6 rounded-2xl border backdrop-blur-sm',
            isDark
                ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                : 'bg-white bg-opacity-90 border-gray-200'
        ]">
            <div class="flex items-center">
                <div
                    class="w-12 h-12 bg-gradient-to-br from-red-500 to-pink-600 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ statistics.disabled_plugins }}
                    </h3>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('plugins_disabled_plugins') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Health Status -->
        <div :class="[
            'p-6 rounded-2xl border backdrop-blur-sm',
            isDark
                ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                : 'bg-white bg-opacity-90 border-gray-200'
        ]">
            <div class="flex items-center">
                <div :class="[
                    'w-12 h-12 rounded-xl flex items-center justify-center',
                    getHealthStatusGradient(health_status.overall_status)
                ]">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z">
                        </path>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 :class="['text-lg font-bold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t(`plugins_health_${health_status.overall_status}`) }}
                    </h3>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('plugins_system_health') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    statistics: {
        type: Object,
        required: true,
        default: () => ({
            total_plugins: 0,
            enabled_plugins: 0,
            disabled_plugins: 0,
            healthy_plugins: 0,
            error_plugins: 0
        })
    },
    health_status: {
        type: Object,
        required: true,
        default: () => ({
            overall_status: 'unknown'
        })
    },
    isDark: {
        type: Boolean,
        default: false
    }
});

/**
 * Get health status gradient based on status
 */
const getHealthStatusGradient = (status) => {
    switch (status) {
        case 'healthy':
            return 'bg-gradient-to-br from-green-500 to-emerald-600';
        case 'warning':
            return 'bg-gradient-to-br from-yellow-500 to-orange-600';
        case 'error':
            return 'bg-gradient-to-br from-red-500 to-pink-600';
        default:
            return 'bg-gradient-to-br from-gray-500 to-gray-600';
    }
};
</script>