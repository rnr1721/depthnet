<template>
    <PageTitle :title="t('engines_page_title')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('engines_management')" :isAdmin="true" />

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
                <!-- Error message -->
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
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Total Engines -->
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
                                        d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                    </path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ stats.total_engines }}
                                </h3>
                                <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('engines_total_engines') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Available Engines -->
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
                                    {{ stats.available_engines }}
                                </h3>
                                <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('engines_available_engines') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Default Engine -->
                    <div :class="[
                        'p-6 rounded-2xl border backdrop-blur-sm',
                        isDark
                            ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                            : 'bg-white bg-opacity-90 border-gray-200'
                    ]">
                        <div class="flex items-center">
                            <div
                                class="w-12 h-12 bg-gradient-to-br from-yellow-500 to-orange-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z">
                                    </path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 :class="['text-lg font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ stats.default_engine || t('engines_no_default') }}
                                </h3>
                                <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('engines_default_engine') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Engines List -->
                <div :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- Header -->
                    <div :class="[
                        'px-6 py-6 border-b',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <!-- Headers -->
                            <div>
                                <h2 :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('engines_ai_engines') }}
                                </h2>
                                <p :class="['text-sm mt-1', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('engines_manage_ai_engines_desc') }}
                                </p>
                            </div>

                            <!-- Buttons -->
                            <div class="flex flex-wrap gap-3">
                                <!-- Refresh button -->
                                <button @click="refreshEngines" :disabled="loading" :class="[
                                    'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-blue-500',
                                    'disabled:opacity-50 disabled:cursor-not-allowed',
                                    isDark
                                        ? 'bg-blue-600 hover:bg-blue-700 text-white'
                                        : 'bg-blue-600 hover:bg-blue-700 text-white'
                                ]">
                                    <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                            stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                        </path>
                                    </svg>
                                    <span class="ml-2">{{ t('engines_refresh') }}</span>
                                </button>

                                <!-- Go to presets -->
                                <Link :href="route('admin.presets.index')" :class="[
                                    'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                                    isDark
                                        ? 'bg-gray-700 hover:bg-gray-800 text-white focus:ring-gray-500 focus:ring-offset-gray-900'
                                        : 'bg-gray-200 hover:bg-gray-300 text-gray-800 focus:ring-gray-400'
                                ]">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7"></path>
                                </svg>
                                {{ t('engines_presets') }}
                                </Link>
                            </div>
                        </div>
                    </div>


                    <!-- Engines Grid -->
                    <div class="p-6">
                        <div v-if="loading" class="text-center py-12">
                            <svg class="w-8 h-8 animate-spin mx-auto mb-4"
                                :class="isDark ? 'text-gray-400' : 'text-gray-600'" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                {{ t('engines_loading') }}
                            </p>
                        </div>

                        <div v-else-if="Object.keys(engines).length === 0" class="text-center py-12">
                            <svg class="w-16 h-16 mx-auto mb-4" :class="isDark ? 'text-gray-600' : 'text-gray-400'"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                </path>
                            </svg>
                            <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-600']">
                                {{ t('engines_no_engines') }}
                            </h3>
                            <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('engines_no_engines_desc') }}
                            </p>
                        </div>

                        <div v-else class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                            <div v-for="(engine, engineName) in engines" :key="engineName" :class="[
                                'border rounded-2xl p-6 transition-all hover:shadow-lg',
                                isDark
                                    ? 'bg-gray-700 bg-opacity-50 border-gray-600 hover:border-gray-500'
                                    : 'bg-gray-50 border-gray-200 hover:border-gray-300'
                            ]">
                                <!-- Engine Header -->
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <div :class="[
                                            'w-10 h-10 rounded-lg flex items-center justify-center',
                                            engine.enabled
                                                ? 'bg-gradient-to-br from-green-500 to-emerald-600'
                                                : 'bg-gradient-to-br from-gray-500 to-gray-600'
                                        ]">
                                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ engine.display_name }}
                                            </h3>
                                            <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                                {{ engineName }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <!-- Status Badge -->
                                        <span :class="[
                                            'px-2 py-1 text-xs font-medium rounded-full',
                                            engine.enabled
                                                ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                                : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
                                        ]">
                                            {{ engine.enabled ? t('engines_enabled') : t('engines_disabled') }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Engine Description -->
                                <p :class="['text-sm mb-4', isDark ? 'text-gray-300' : 'text-gray-600']">
                                    {{ engine.description }}
                                </p>

                                <!-- Engine Info -->
                                <div class="space-y-2 mb-4">
                                    <div class="flex justify-between text-sm">
                                        <span :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                            {{ t('engines_presets_count') }}:
                                        </span>
                                        <span :class="isDark ? 'text-white' : 'text-gray-900'">
                                            {{ engine.recommended_presets?.length || 0 }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                            {{ t('engines_config_fields') }}:
                                        </span>
                                        <span :class="isDark ? 'text-white' : 'text-gray-900'">
                                            {{ Object.keys(engine.config_fields || {}).length }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex space-x-2">
                                    <button @click="testEngine(engineName)"
                                        :disabled="!engine.enabled || testingEngines[engineName]" :class="[
                                            'flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all focus:outline-none focus:ring-2',
                                            'disabled:opacity-50 disabled:cursor-not-allowed',
                                            engine.enabled
                                                ? (isDark
                                                    ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500'
                                                    : 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500')
                                                : (isDark
                                                    ? 'bg-gray-700 text-gray-400'
                                                    : 'bg-gray-200 text-gray-500')
                                        ]">
                                        <span v-if="testingEngines[engineName]"
                                            class="flex items-center justify-center">
                                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                        </span>
                                        <span v-else>{{ t('engines_test') }}</span>
                                    </button>
                                    <button @click="showEngineDetails(engineName)" :class="[
                                        'px-3 py-2 text-sm font-medium rounded-lg transition-all focus:outline-none focus:ring-2',
                                        isDark
                                            ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 focus:ring-gray-500'
                                            : 'bg-gray-200 hover:bg-gray-300 text-gray-700 focus:ring-gray-500'
                                    ]">
                                        {{ t('engines_details') }}
                                    </button>
                                </div>

                                <!-- Test Results -->
                                <div v-if="testResults[engineName]" :class="[
                                    'mt-3 p-3 rounded-lg text-sm',
                                    testResults[engineName].success
                                        ? (isDark ? 'bg-green-900 bg-opacity-50 text-green-200' : 'bg-green-50 text-green-800')
                                        : (isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-800')
                                ]">
                                    <div class="flex items-center">
                                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path v-if="testResults[engineName].success" fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                clip-rule="evenodd"></path>
                                            <path v-else fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                        <span>
                                            {{ testResults[engineName].success ? t('engines_test_success') :
                                                t('engines_test_failed') }}
                                            <span v-if="testResults[engineName].response_time">
                                                ({{ testResults[engineName].response_time }}ms)
                                            </span>
                                        </span>
                                    </div>
                                    <div v-if="testResults[engineName].error" class="mt-1 text-xs opacity-80">
                                        {{ testResults[engineName].error }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Engine Details Modal -->
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showDetailsModal" class="fixed inset-0 z-50 overflow-y-auto">
                <!-- Backdrop -->
                <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="showDetailsModal = false">
                </div>

                <!-- Modal -->
                <div class="flex items-center justify-center min-h-screen p-4">
                    <div :class="[
                        'relative w-full max-w-2xl rounded-2xl shadow-2xl transform transition-all',
                        isDark
                            ? 'bg-gray-800 border border-gray-700'
                            : 'bg-white border border-gray-200'
                    ]">
                        <!-- Modal Header -->
                        <div :class="[
                            'flex items-center justify-between p-6 border-b',
                            isDark ? 'border-gray-700' : 'border-gray-200'
                        ]">
                            <div class="flex items-center space-x-3">
                                <div :class="[
                                    'w-10 h-10 rounded-lg flex items-center justify-center',
                                    selectedEngine?.enabled
                                        ? 'bg-gradient-to-br from-green-500 to-emerald-600'
                                        : 'bg-gradient-to-br from-gray-500 to-gray-600'
                                ]">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                        {{ selectedEngine?.display_name }}
                                    </h3>
                                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                        {{ selectedEngine?.name }}
                                    </p>
                                </div>
                            </div>
                            <button @click="showDetailsModal = false" :class="[
                                'p-2 rounded-lg transition-colors',
                                isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600'
                            ]">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                        <!-- Modal Content -->
                        <div class="p-6 space-y-6">
                            <!-- Description -->
                            <div>
                                <h4 :class="['font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('engines_description') }}
                                </h4>
                                <p :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-600']">
                                    {{ selectedEngine?.description }}
                                </p>
                            </div>

                            <!-- Status -->
                            <div>
                                <h4 :class="['font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('engines_status') }}
                                </h4>
                                <span :class="[
                                    'px-3 py-1 text-sm font-medium rounded-full',
                                    selectedEngine?.enabled
                                        ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                        : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
                                ]">
                                    {{ selectedEngine?.enabled ? t('engines_enabled') : t('engines_disabled') }}
                                </span>
                            </div>

                            <!-- Configuration Fields -->
                            <div
                                v-if="selectedEngine?.config_fields && Object.keys(selectedEngine.config_fields).length > 0">
                                <h4 :class="['font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('engines_configuration_fields') }}
                                </h4>
                                <div class="space-y-3">
                                    <div v-for="(field, fieldName) in selectedEngine.config_fields" :key="fieldName"
                                        :class="[
                                            'p-3 rounded-lg border',
                                            isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
                                        ]">
                                        <div class="flex justify-between items-start mb-1">
                                            <span
                                                :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ field.label || fieldName }}
                                            </span>
                                            <span :class="[
                                                'text-xs px-2 py-1 rounded',
                                                field.required
                                                    ? (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                                                    : (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-200 text-gray-600')
                                            ]">
                                                {{ field.required ? t('engines_required') : t('engines_optional') }}
                                            </span>
                                        </div>
                                        <p v-if="field.description"
                                            :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                            {{ field.description }}
                                        </p>
                                        <div class="flex justify-between items-center mt-2 text-xs">
                                            <span :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                                {{ t('engines_type') }}: {{ field.type }}
                                            </span>
                                            <span v-if="field.default"
                                                :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                                {{ t('engines_default') }}: {{ field.default }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Recommended Presets -->
                            <div
                                v-if="selectedEngine?.recommended_presets && selectedEngine.recommended_presets.length > 0">
                                <h4 :class="['font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('engines_recommended_presets') }}
                                </h4>
                                <div class="space-y-2">
                                    <div v-for="(preset, index) in selectedEngine.recommended_presets" :key="index"
                                        :class="[
                                            'p-3 rounded-lg border',
                                            isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
                                        ]">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <span
                                                    :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">
                                                    {{ preset.name }}
                                                </span>
                                                <p v-if="preset.description"
                                                    :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-600']">
                                                    {{ preset.description }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Default Configuration -->
                            <div
                                v-if="selectedEngine?.default_config && Object.keys(selectedEngine.default_config).length > 0">
                                <h4 :class="['font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('engines_default_config') }}
                                </h4>
                                <div :class="[
                                    'p-3 rounded-lg border font-mono text-xs',
                                    isDark ? 'bg-gray-900 border-gray-600 text-gray-300' : 'bg-gray-100 border-gray-200 text-gray-800'
                                ]">
                                    <pre>{{ JSON.stringify(selectedEngine.default_config, null, 2) }}</pre>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div :class="[
                            'flex justify-end space-x-3 p-6 border-t',
                            isDark ? 'border-gray-700' : 'border-gray-200'
                        ]">
                            <button @click="showDetailsModal = false" :class="[
                                'px-4 py-2 rounded-lg font-medium transition-colors',
                                isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                            ]">
                                {{ t('engines_close') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import PageTitle from '@/Components/PageTitle.vue';
import AdminHeader from '@/Components/AdminHeader.vue';

const { t } = useI18n();

// Theme management
const isDark = ref(false);

// Props
const props = defineProps({
    engines: {
        type: Object,
        default: () => ({})
    },
    stats: {
        type: Object,
        default: () => ({
            total_engines: 0,
            available_engines: 0,
            default_engine: null
        })
    }
});

// State
const loading = ref(false);
const errorMessage = ref('');
const engines = ref(props.engines);
const stats = ref(props.stats);
const testingEngines = ref({});
const testResults = ref({});
const showDetailsModal = ref(false);
const selectedEngine = ref(null);

// Methods
const refreshEngines = async () => {
    loading.value = true;
    try {
        router.reload({ only: ['engines', 'stats'] });
    } finally {
        loading.value = false;
    }
};

const testEngine = async (engineName) => {
    testingEngines.value[engineName] = true;
    testResults.value[engineName] = null;

    try {
        const response = await axios.get(route('admin.engines.test', { engineName }));
        testResults.value[engineName] = response.data.data;
    } catch (error) {
        testResults.value[engineName] = {
            success: false,
            error: error.response?.data?.message || 'Test failed'
        };
    } finally {
        testingEngines.value[engineName] = false;
    }
};

const showEngineDetails = async (engineName) => {
    try {
        const response = await axios.get(route('admin.engines.info', { engineName }));
        selectedEngine.value = response.data.data;
        showDetailsModal.value = true;
    } catch (error) {
        errorMessage.value = 'Failed to load engine details: ' + (error.response?.data?.message || error.message);
    }
};

onMounted(() => {
    // Load theme from localStorage
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }

    // Listen for theme changes from other components
    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>