<template>
    <PageTitle :title="t('known_sources')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('known_sources')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-violet-500' : 'bg-violet-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-indigo-500' : 'bg-indigo-300']">
                </div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Flash messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash?.success"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800']">
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
                    <div v-if="$page.props.flash?.error"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800']">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.error }}</span>
                        </div>
                    </div>
                </Transition>

                <template v-if="true">
                    <!-- Preset selector + explanation -->
                    <div
                        :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                        <div
                            class="flex flex-col lg:flex-row lg:items-start lg:justify-between space-y-4 lg:space-y-0 lg:space-x-8">
                            <div class="flex-1">
                                <label
                                    :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                        t('ks_select_preset') }}</label>
                                <select v-model="selectedPresetId" @change="changePreset"
                                    :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                    <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                        {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}{{
                                            !preset.is_pool_mode ? ' — ' + t('ks_not_pool') : '' }}
                                    </option>
                                </select>
                            </div>
                            <!-- What is this? -->
                            <div
                                :class="['lg:max-w-md p-4 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-violet-50 border-violet-200']">
                                <div class="flex items-start space-x-3">
                                    <div
                                        class="w-8 h-8 bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <p
                                            :class="['text-sm font-semibold mb-1', isDark ? 'text-gray-200' : 'text-violet-900']">
                                            {{ t('ks_what_is_this') }}</p>
                                        <p
                                            :class="['text-xs leading-relaxed', isDark ? 'text-gray-400' : 'text-violet-700']">
                                            {{ t('ks_explanation') }}</p>
                                        <p
                                            :class="['text-xs mt-2 font-mono px-2 py-1 rounded inline-block', isDark ? 'bg-gray-600 text-violet-300' : 'bg-violet-100 text-violet-800']">
                                            [[known_sources]]</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pool mode warning -->
                    <div v-if="!currentPreset?.is_pool_mode"
                        :class="['mb-6 p-5 rounded-2xl border-l-4 backdrop-blur-sm flex items-start space-x-4', isDark ? 'bg-yellow-900 bg-opacity-20 border-yellow-500' : 'bg-yellow-50 border-yellow-400']">
                        <svg class="w-6 h-6 flex-shrink-0 mt-0.5"
                            :class="isDark ? 'text-yellow-400' : 'text-yellow-500'" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z">
                            </path>
                        </svg>
                        <div>
                            <p :class="['font-semibold text-sm mb-1', isDark ? 'text-yellow-300' : 'text-yellow-800']">
                                {{ t('ks_pool_mode_required') }}</p>
                            <p :class="['text-sm', isDark ? 'text-yellow-400' : 'text-yellow-700']">{{
                                t('ks_pool_mode_required_hint') }}</p>
                        </div>
                    </div>

                    <template v-if="currentPreset?.is_pool_mode">

                        <!-- Action bar -->
                        <div
                            :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                            <div class="flex items-center justify-between">
                                <div :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ sources.length }} {{ sources.length === 1 ? t('ks_source') : t('ks_sources') }}
                                    <span v-if="sources.length > 1"
                                        :class="['ml-2', isDark ? 'text-gray-500' : 'text-gray-400']">· {{
                                            t('ks_drag_hint') }}</span>
                                </div>
                                <button @click="showAddModal = true"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white focus:ring-offset-gray-800' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                    {{ t('ks_add_source') }}
                                </button>
                            </div>
                        </div>

                        <!-- Sources list -->
                        <div v-if="sources.length > 0"
                            :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                            <ul ref="listEl">
                                <li v-for="(source, index) in sources" :key="source.id" :data-id="source.id"
                                    :class="['flex items-center space-x-4 px-5 py-4 group transition-colors cursor-grab active:cursor-grabbing', index > 0 ? (isDark ? 'border-t border-gray-700' : 'border-t border-gray-100') : '', isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-50']">
                                    <div
                                        :class="['flex-shrink-0 opacity-30 group-hover:opacity-70 transition-opacity', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4 8h16M4 16h16"></path>
                                        </svg>
                                    </div>
                                    <span
                                        :class="['font-mono text-xs font-bold w-6 text-center flex-shrink-0', isDark ? 'text-violet-400' : 'text-violet-600']">{{
                                            index + 1 }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2 mb-0.5">
                                            <span
                                                :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">{{
                                                    source.label }}</span>
                                            <span
                                                :class="['font-mono text-xs px-1.5 py-0.5 rounded', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500']">{{
                                                    source.source_name }}</span>
                                        </div>
                                        <p v-if="source.description"
                                            :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                                source.description }}</p>
                                        <p v-if="source.default_value"
                                            :class="['text-xs mt-0.5 font-mono', isDark ? 'text-violet-400 opacity-60' : 'text-violet-600 opacity-60']">
                                            ↳ {{ t('ks_field_default_value') }}: {{ source.default_value }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex items-center space-x-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="openEdit(source)"
                                            :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-gray-600 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700']"
                                            :title="t('ks_edit')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                                </path>
                                            </svg>
                                        </button>
                                        <button @click="deleteSource(source)"
                                            :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-500 hover:text-red-700']"
                                            :title="t('ks_delete')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                                </path>
                                            </svg>
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <!-- Empty state -->
                        <div v-else
                            :class="['text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                            <div
                                class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-violet-400 to-indigo-600 rounded-full flex items-center justify-center">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                                t('ks_no_sources') }}</h3>
                            <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                t('ks_no_sources_description') }}</p>
                            <button @click="showAddModal = true"
                                :class="['px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                {{ t('ks_add_first_source') }}
                            </button>
                        </div>

                        <!-- Current pool contents -->
                        <div class="mt-8">
                            <div
                                :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                                <div
                                    :class="['px-5 py-4 flex items-center justify-between border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-2 h-2 rounded-full"
                                            :class="poolItems.length ? 'bg-green-400 animate-pulse' : 'bg-gray-400'">
                                        </div>
                                        <h3 :class="['font-semibold text-sm', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ t('ks_pool_title') }}</h3>
                                        <span
                                            :class="['text-xs px-2 py-0.5 rounded font-mono', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500']">{{
                                                poolItems.length }} {{ t('ks_pool_items') }}</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <button @click="showPoolAddModal = true"
                                            :class="['px-3 py-1.5 rounded-lg text-xs font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                            + {{ t('ks_pool_add') }}
                                        </button>
                                        <button v-if="poolItems.length" @click="clearPool"
                                            :class="['px-3 py-1.5 rounded-lg text-xs font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-900 bg-opacity-40 hover:bg-opacity-60 text-red-400' : 'bg-red-50 hover:bg-red-100 text-red-600']">
                                            {{ t('ks_pool_clear') }}
                                        </button>
                                    </div>
                                </div>
                                <div v-if="!poolItems.length"
                                    :class="['px-5 py-8 text-center text-sm', isDark ? 'text-gray-500' : 'text-gray-400']">
                                    {{ t('ks_pool_empty') }}
                                </div>
                                <ul v-else class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-100'">
                                    <li v-for="item in poolItems" :key="item.id"
                                        :class="['flex items-start space-x-4 px-5 py-3 group', isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-50']">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center space-x-2 mb-0.5">
                                                <span
                                                    :class="['font-mono text-xs font-semibold', isDark ? 'text-violet-400' : 'text-violet-600']">{{
                                                        item.source_name }}</span>
                                                <span
                                                    :class="['text-xs', isDark ? 'text-gray-600' : 'text-gray-400']">{{
                                                        item.updated_at }}</span>
                                            </div>
                                            <p
                                                :class="['text-sm font-mono break-all', isDark ? 'text-gray-300' : 'text-gray-700']">
                                                {{ item.content }}</p>
                                        </div>
                                        <button @click="deletePoolItem(item)"
                                            :class="['flex-shrink-0 p-1.5 rounded-lg transition-colors opacity-0 group-hover:opacity-100 focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400' : 'hover:bg-red-100 text-red-500']"
                                            :title="t('ks_delete')">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                    </template>
                </template>
            </div>
        </main>

        <!-- Add / Edit known source modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
                enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showAddModal || showEditModal" class="fixed inset-0 z-[9999] overflow-y-auto">
                    <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="closeModal"></div>
                        <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                        <Transition enter-active-class="transition ease-out duration-300"
                            enter-from-class="transform opacity-0 translate-y-4 sm:scale-95"
                            enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                            leave-active-class="transition ease-in duration-200"
                            leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                            leave-to-class="transform opacity-0 translate-y-4 sm:scale-95">
                            <div :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                                role="dialog">
                                <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <div
                                                class="w-8 h-8 bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg flex items-center justify-center">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                </svg>
                                            </div>
                                            <h3
                                                :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                                {{
                                                    showEditModal ? t('ks_edit_source') : t('ks_add_source') }}</h3>
                                        </div>
                                        <button @click="closeModal"
                                            :class="['rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-violet-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <form @submit.prevent="submitModal">
                                    <div class="px-6 py-4 space-y-4">
                                        <div>
                                            <label
                                                :class="['block text-sm font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                                    t('ks_field_source_name') }} *</label>
                                            <input v-model="form.source_name" type="text"
                                                :placeholder="t('ks_field_source_name_hint')"
                                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3 font-mono text-sm', form.errors.source_name ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-violet-500', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                            <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                                t('ks_field_source_name_desc') }}</p>
                                            <div v-if="form.errors.source_name" class="mt-1 text-xs text-red-500">{{
                                                form.errors.source_name }}</div>
                                        </div>
                                        <div>
                                            <label
                                                :class="['block text-sm font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                                    t('ks_field_label') }} *</label>
                                            <input v-model="form.label" type="text"
                                                :placeholder="t('ks_field_label_hint')"
                                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3', form.errors.label ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-violet-500', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                            <div v-if="form.errors.label" class="mt-1 text-xs text-red-500">{{
                                                form.errors.label
                                                }}</div>
                                        </div>
                                        <div>
                                            <label
                                                :class="['block text-sm font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                                                {{ t('ks_field_description') }}
                                                <span
                                                    :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                                        t('ks_optional') }}</span>
                                            </label>
                                            <input v-model="form.description" type="text"
                                                :placeholder="t('ks_field_description_hint')"
                                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                        </div>
                                        <div>
                                            <label
                                                :class="['block text-sm font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                                                {{ t('ks_field_default_value') }}
                                                <span
                                                    :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                                        t('ks_optional') }}</span>
                                            </label>
                                            <input v-model="form.default_value" type="text"
                                                :placeholder="t('ks_field_default_value_hint')"
                                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3 font-mono text-sm', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                        </div>
                                    </div>
                                    <div
                                        :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                        <button type="button" @click="closeModal"
                                            :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                                t('ks_cancel') }}</button>
                                        <button type="submit"
                                            :disabled="form.processing || !form.source_name?.trim() || !form.label?.trim()"
                                            :class="['w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                            <span v-if="form.processing" class="flex items-center justify-center">
                                                <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                        stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                {{ t('ks_processing') }}
                                            </span>
                                            <span v-else>{{ showEditModal ? t('ks_save') : t('ks_add_source') }}</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </Transition>
                    </div>
                </div>
            </Transition>

            <!-- Pool add modal -->
            <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
                enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showPoolAddModal" class="fixed inset-0 z-[9999] overflow-y-auto">
                    <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm"
                            @click="showPoolAddModal = false; poolForm.reset(); document.body.style.overflow = ''">
                        </div>
                        <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                        <div :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                            role="dialog">
                            <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    t('ks_pool_add_title') }}</h3>
                            </div>
                            <form @submit.prevent="submitPoolItem">
                                <div class="px-6 py-4 space-y-4">
                                    <div>
                                        <label
                                            :class="['block text-sm font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                                t('ks_field_source_name') }} *</label>
                                        <input v-model="poolForm.source_name" type="text"
                                            :placeholder="t('ks_field_source_name_hint')"
                                            :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3 font-mono text-sm', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                    </div>
                                    <div>
                                        <label
                                            :class="['block text-sm font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                                t('ks_pool_content') }} *</label>
                                        <textarea v-model="poolForm.content" rows="3"
                                            :placeholder="t('ks_pool_content_hint')"
                                            :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3 text-sm resize-none font-mono', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']"></textarea>
                                    </div>
                                </div>
                                <div
                                    :class="['px-6 py-4 border-t flex justify-end space-x-3', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <button type="button" @click="showPoolAddModal = false; poolForm.reset()"
                                        :class="['px-4 py-2 rounded-xl font-medium transition-all', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                            t('ks_cancel') }}</button>
                                    <button type="submit"
                                        :disabled="poolForm.processing || !poolForm.source_name?.trim() || !poolForm.content?.trim()"
                                        :class="['px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500 disabled:opacity-50', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">{{
                                            t('ks_pool_add') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, useForm } from '@inertiajs/vue3';
import Sortable from 'sortablejs';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();

const props = defineProps({
    presets: { type: Array, default: () => [] },
    currentPreset: { type: Object, default: null },
    sources: { type: Array, default: () => [] },
    poolItems: { type: Array, default: () => [] },
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const showAddModal = ref(false);
const showEditModal = ref(false);
const showPoolAddModal = ref(false);
const editingSource = ref(null);
const listEl = ref(null);
let sortable = null;

const form = useForm({ preset_id: null, source_name: '', label: '', description: '', default_value: '' });
const poolForm = useForm({ preset_id: null, source_name: '', content: '' });

// ---- Preset switch ----
const changePreset = () => {
    router.get(route('admin.known-sources.index'), { preset_id: selectedPresetId.value });
};

// ---- Known source modal ----
const openEdit = (source) => {
    editingSource.value = source;
    form.source_name = source.source_name;
    form.label = source.label;
    form.description = source.description ?? '';
    form.default_value = source.default_value ?? '';
    showEditModal.value = true;
};

const closeModal = () => {
    showAddModal.value = false;
    showEditModal.value = false;
    editingSource.value = null;
    form.reset();
    form.clearErrors();
    document.body.style.overflow = '';
};

const submitModal = () => {
    form.preset_id = props.currentPreset?.id;
    if (showEditModal.value && editingSource.value) {
        form.put(route('admin.known-sources.update', editingSource.value.id), { onSuccess: closeModal });
    } else {
        form.post(route('admin.known-sources.store'), { onSuccess: closeModal });
    }
};

// ---- Delete known source ----
const deleteSource = (source) => {
    if (!confirm(t('ks_confirm_delete'))) return;
    router.delete(route('admin.known-sources.destroy', source.id), {
        data: { preset_id: props.currentPreset?.id },
    });
};

// ---- Pool management ----
const submitPoolItem = () => {
    poolForm.preset_id = props.currentPreset?.id;
    poolForm.post(route('admin.known-sources.pool.store'), {
        onSuccess: () => {
            showPoolAddModal.value = false;
            poolForm.reset();
            document.body.style.overflow = '';
        },
    });
};

const deletePoolItem = (item) => {
    if (!confirm(t('ks_pool_confirm_delete'))) return;
    router.delete(route('admin.known-sources.pool.destroy', item.id), {
        data: { preset_id: props.currentPreset?.id },
        preserveScroll: true,
    });
};

const clearPool = () => {
    if (!confirm(t('ks_pool_confirm_clear'))) return;
    router.post(route('admin.known-sources.pool.clear'), {
        preset_id: props.currentPreset?.id,
    }, { preserveScroll: true });
};

// ---- Escape key ----
const handleEscape = (e) => {
    if (e.key === 'Escape') {
        if (showAddModal.value || showEditModal.value) closeModal();
        if (showPoolAddModal.value) { showPoolAddModal.value = false; poolForm.reset(); document.body.style.overflow = ''; }
    }
};

// ---- Drag-and-drop ----
const initSortable = () => {
    if (!listEl.value || props.sources.length < 2) return;
    sortable = Sortable.create(listEl.value, {
        animation: 150,
        handle: 'li',
        ghostClass: isDark.value ? 'bg-gray-700' : 'bg-violet-50',
        onEnd({ newIndex, oldIndex }) {
            if (newIndex === oldIndex) return;
            const items = [...listEl.value.querySelectorAll('li[data-id]')];
            const orderedIds = items.map(el => parseInt(el.dataset.id));
            router.post(route('admin.known-sources.reorder'), {
                preset_id: props.currentPreset?.id,
                ordered_ids: orderedIds,
            }, { preserveScroll: true });
        },
    });
};

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
    document.addEventListener('keydown', handleEscape);
    initSortable();
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    sortable?.destroy();
});
</script>