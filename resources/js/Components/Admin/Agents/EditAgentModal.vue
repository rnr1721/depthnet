<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity"
                        @click="close"></div>
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <div v-if="show"
                            :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                            role="dialog">

                            <!-- Header -->
                            <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div
                                            class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-violet-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                                </path>
                                            </svg>
                                        </div>
                                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ t('ag_edit_agent') }}</h3>
                                    </div>
                                    <button @click="close"
                                        :class="['rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                                <!-- Tabs -->
                                <div class="flex gap-1 mt-4">
                                    <button v-for="tab in tabs" :key="tab.value" @click="activeTab = tab.value"
                                        :class="['px-4 py-1.5 rounded-lg text-sm font-medium transition-all focus:outline-none', activeTab === tab.value ? 'bg-indigo-600 text-white' : (isDark ? 'text-gray-400 hover:bg-gray-700' : 'text-gray-600 hover:bg-gray-100')]">
                                        {{ t(tab.labelKey) }}
                                    </button>
                                </div>
                            </div>

                            <!-- Tab: General -->
                            <div v-if="activeTab === 'general'" class="px-6 py-4 space-y-4">
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                        t('ag_name') }} *</label>
                                    <input v-model="form.name" type="text"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3', form.errors.name ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-indigo-500', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']" />
                                    <div v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name
                                        }}</div>
                                </div>
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ t('ag_code') }}
                                        <span
                                            :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                            t('ag_optional') }}</span>
                                    </label>
                                    <input v-model="form.code" type="text"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3 font-mono', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']" />
                                    <div v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code
                                        }}</div>
                                </div>
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ t('ag_description') }}
                                        <span
                                            :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                            t('ag_optional') }}</span>
                                    </label>
                                    <input v-model="form.description" type="text"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']" />
                                </div>
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                        t('ag_planner_preset') }} *</label>
                                    <select v-model="form.planner_preset_id"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                        <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                            {{ preset.name }}{{ preset.code ? ` (${preset.code})` : '' }}
                                        </option>
                                    </select>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <label
                                            :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('ag_is_active') }}</label>
                                    </div>
                                    <button type="button" @click="form.is_active = !form.is_active"
                                        :class="['relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500', form.is_active ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-200')]">
                                        <span
                                            :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out', form.is_active ? 'translate-x-5' : 'translate-x-0']"></span>
                                    </button>
                                </div>
                            </div>

                            <!-- Tab: Roles -->
                            <div v-if="activeTab === 'roles'" class="px-6 py-4">
                                <!-- Existing roles -->
                                <div v-if="localRoles.length > 0" class="mb-4 space-y-2">
                                    <div v-for="role in localRoles" :key="role.id">
                                        <!-- View mode -->
                                        <div v-if="editingRole?.id !== role.id"
                                            :class="['flex items-center justify-between p-3 rounded-xl', isDark ? 'bg-gray-700' : 'bg-gray-50']">
                                            <div>
                                                <span
                                                    :class="['font-mono text-sm font-semibold', isDark ? 'text-violet-400' : 'text-violet-600']">{{
                                                    role.code }}</span>
                                                <span
                                                    :class="['ml-2 text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">→
                                                    {{ role.preset.name }}</span>
                                                <span v-if="role.validator"
                                                    :class="['ml-2 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
                                                    ✓ {{ t('ag_validator') }}: {{ role.validator.name }}
                                                </span>
                                                <div
                                                    :class="['text-xs mt-0.5', isDark ? 'text-gray-500' : 'text-gray-400']">
                                                    {{ t('ag_max_attempts') }}: {{ role.max_attempts }}
                                                    <span v-if="role.auto_proceed" class="ml-2">· {{
                                                        t('ag_auto_proceed') }}</span>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <button @click="startEditRole(role)"
                                                    :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-gray-600 text-gray-400 hover:text-indigo-400' : 'hover:bg-indigo-50 text-gray-400 hover:text-indigo-600']">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                                        </path>
                                                    </svg>
                                                </button>
                                                <button @click="deleteRole(role)"
                                                    :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-red-900 hover:bg-opacity-40 text-gray-500 hover:text-red-400' : 'hover:bg-red-50 text-gray-400 hover:text-red-600']">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Edit mode (inline) -->
                                        <div v-else
                                            :class="['p-3 rounded-xl border-2 border-indigo-500', isDark ? 'bg-gray-700' : 'bg-indigo-50']">
                                            <div class="space-y-2">
                                                <div class="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <label
                                                            :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                            t('ag_role_code') }} *</label>
                                                        <input v-model="roleForm.code" type="text"
                                                            :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 px-3 py-2 text-sm font-mono', isDark ? 'bg-gray-600 text-white ring-gray-500' : 'bg-white text-gray-900 ring-gray-300']" />
                                                    </div>
                                                    <div>
                                                        <label
                                                            :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                            t('ag_role_preset') }} *</label>
                                                        <select v-model="roleForm.preset_id"
                                                            :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 px-3 py-2 text-sm', isDark ? 'bg-gray-600 text-white ring-gray-500' : 'bg-white text-gray-900 ring-gray-300']">
                                                            <option v-for="preset in presets" :key="preset.id"
                                                                :value="preset.id">{{ preset.name }}</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div>
                                                    <label
                                                        :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                        t('ag_validator_preset') }}</label>
                                                    <select v-model="roleForm.validator_preset_id"
                                                        :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 px-3 py-2 text-sm', isDark ? 'bg-gray-600 text-white ring-gray-500' : 'bg-white text-gray-900 ring-gray-300']">
                                                        <option value="">{{ t('ag_no_validator') }}</option>
                                                        <option v-for="preset in presets" :key="preset.id"
                                                            :value="preset.id">{{ preset.name }}</option>
                                                    </select>
                                                </div>
                                                <div class="grid grid-cols-2 gap-2 items-center">
                                                    <div>
                                                        <label
                                                            :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                            t('ag_max_attempts') }}</label>
                                                        <input v-model.number="roleForm.max_attempts" type="number"
                                                            min="1" max="10"
                                                            :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 px-3 py-2 text-sm', isDark ? 'bg-gray-600 text-white ring-gray-500' : 'bg-white text-gray-900 ring-gray-300']" />
                                                    </div>
                                                    <div class="flex items-center gap-2 pt-5">
                                                        <button type="button"
                                                            @click="roleForm.auto_proceed = !roleForm.auto_proceed"
                                                            :class="['relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none', roleForm.auto_proceed ? 'bg-indigo-600' : (isDark ? 'bg-gray-500' : 'bg-gray-200')]">
                                                            <span
                                                                :class="['pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition duration-200', roleForm.auto_proceed ? 'translate-x-4' : 'translate-x-0']"></span>
                                                        </button>
                                                        <label
                                                            :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                            t('ag_auto_proceed') }}</label>
                                                    </div>
                                                </div>
                                                <div class="flex gap-2 pt-1">
                                                    <button @click="cancelEdit"
                                                        :class="['flex-1 px-3 py-1.5 rounded-lg text-sm font-medium transition-all focus:outline-none', isDark ? 'bg-gray-600 text-gray-300 hover:bg-gray-500' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                                        {{ t('ag_cancel') }}
                                                    </button>
                                                    <button @click="saveRole"
                                                        :disabled="roleForm.processing || !roleForm.code?.trim() || !roleForm.preset_id"
                                                        :class="['flex-1 px-3 py-1.5 rounded-lg text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white']">
                                                        {{ roleForm.processing ? t('ag_saving') : t('ag_save') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else
                                    :class="['text-sm text-center py-4 mb-4', isDark ? 'text-gray-500' : 'text-gray-400']">
                                    {{ t('ag_no_roles_yet') }}
                                </div>

                                <!-- Add role form — hidden when editing -->
                                <div v-if="!editingRole"
                                    :class="['p-4 rounded-xl border', isDark ? 'border-gray-600 bg-gray-750' : 'border-gray-200 bg-gray-50']">
                                    <p
                                        :class="['text-sm font-medium mb-3', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ t('ag_add_role') }}</p>
                                    <div class="space-y-3">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label
                                                    :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                    t('ag_role_code') }} *</label>
                                                <input v-model="roleForm.code" type="text"
                                                    :placeholder="t('ag_role_code_placeholder')"
                                                    :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-3 py-2 text-sm font-mono', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-500' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-400']" />
                                            </div>
                                            <div>
                                                <label
                                                    :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                    t('ag_role_preset') }} *</label>
                                                <select v-model="roleForm.preset_id"
                                                    :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-white text-gray-900 ring-gray-300']">
                                                    <option value="">{{ t('ag_select_preset') }}</option>
                                                    <option v-for="preset in presets" :key="preset.id"
                                                        :value="preset.id">{{ preset.name }}</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div>
                                            <label
                                                :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">
                                                {{ t('ag_validator_preset') }}
                                                <span
                                                    :class="['ml-1 font-normal', isDark ? 'text-gray-600' : 'text-gray-400']">{{
                                                    t('ag_optional') }}</span>
                                            </label>
                                            <select v-model="roleForm.validator_preset_id"
                                                :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-white text-gray-900 ring-gray-300']">
                                                <option value="">{{ t('ag_no_validator') }}</option>
                                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">{{
                                                    preset.name }}</option>
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3 items-center">
                                            <div>
                                                <label
                                                    :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                    t('ag_max_attempts') }}</label>
                                                <input v-model.number="roleForm.max_attempts" type="number" min="1"
                                                    max="10"
                                                    :class="['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-white text-gray-900 ring-gray-300']" />
                                            </div>
                                            <div class="flex items-center gap-2 pt-5">
                                                <button type="button"
                                                    @click="roleForm.auto_proceed = !roleForm.auto_proceed"
                                                    :class="['relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none', roleForm.auto_proceed ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-200')]">
                                                    <span
                                                        :class="['pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition duration-200', roleForm.auto_proceed ? 'translate-x-4' : 'translate-x-0']"></span>
                                                </button>
                                                <label
                                                    :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                                    t('ag_auto_proceed') }}</label>
                                            </div>
                                        </div>
                                        <button @click="addRole"
                                            :disabled="roleForm.processing || !roleForm.code?.trim() || !roleForm.preset_id"
                                            :class="['w-full px-4 py-2 rounded-xl text-sm font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white']">
                                            {{ roleForm.processing ? t('ag_saving') : t('ag_add_role_btn') }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions (general tab only) -->
                            <div v-if="activeTab === 'general'"
                                :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button type="button" @click="close"
                                    :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('ag_cancel') }}
                                </button>
                                <button @click="submit" :disabled="form.processing || !form.name?.trim()"
                                    :class="['w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white']">
                                    <span v-if="form.processing" class="flex items-center justify-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('ag_saving') }}
                                    </span>
                                    <span v-else>{{ t('ag_save') }}</span>
                                </button>
                            </div>
                            <!-- Close button for roles tab -->
                            <div v-else
                                :class="['px-6 py-4 border-t flex justify-end', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button type="button" @click="close"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('ag_close') }}
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
import { ref, computed, watch, onUnmounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    agent: Object,
    presets: Array,
    isDark: Boolean,
    agents: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({ get: () => props.modelValue, set: (v) => emit('update:modelValue', v) });
const activeTab = ref('general');

const tabs = [
    { value: 'general', labelKey: 'ag_tab_general' },
    { value: 'roles', labelKey: 'ag_tab_roles' },
];

// Local roles copy — updated without full page reload
const localRoles = ref([]);
const editingRole = ref(null); // role being edited, null = add mode

const form = useForm({
    name: '',
    code: '',
    description: '',
    planner_preset_id: '',
    is_active: true,
});

const roleForm = useForm({
    code: '',
    preset_id: '',
    validator_preset_id: '',
    max_attempts: 3,
    auto_proceed: false,
});

// Sync form and local roles when agent changes
watch(() => props.agent, (agent) => {
    if (!agent) return;
    form.name = agent.name;
    form.code = agent.code ?? '';
    form.description = agent.description ?? '';
    form.planner_preset_id = agent.planner.id;
    form.is_active = agent.is_active;
    localRoles.value = agent.roles ? [...agent.roles] : [];
    editingRole.value = null;
    roleForm.reset();
}, { immediate: true });

const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

const close = () => {
    show.value = false;
    activeTab.value = 'general';
    editingRole.value = null;
    roleForm.reset();
    roleForm.clearErrors();
    document.body.style.overflow = '';
};

// ── General form ──────────────────────────────────────────────────────────────

const submit = () => {
    form.put(route('admin.agents.update', props.agent.id), {
        onSuccess: () => { close(); emit('success'); },
    });
};

// ── Role form helpers ─────────────────────────────────────────────────────────

const startEditRole = (role) => {
    editingRole.value = role;
    roleForm.code = role.code;
    roleForm.preset_id = role.preset.id;
    roleForm.validator_preset_id = role.validator?.id ?? '';
    roleForm.max_attempts = role.max_attempts;
    roleForm.auto_proceed = role.auto_proceed;
};

const cancelEdit = () => {
    editingRole.value = null;
    roleForm.reset();
    roleForm.clearErrors();
};

/**
 * Reload only the agents prop from the server — no full page reload.
 * After success, re-sync localRoles from the freshly loaded agent.
 */
const reloadRoles = (callback) => {
    router.reload({
        only: ['agents'],
        onSuccess: () => {
            // Find updated agent in the refreshed agents list
            const updatedAgent = props.agents?.find(a => a.id === props.agent.id);
            if (updatedAgent) {
                localRoles.value = updatedAgent.roles ? [...updatedAgent.roles] : [];
            }
            callback?.();
        },
    });
};

const addRole = () => {
    roleForm.post(route('admin.agents.roles.store', props.agent.id), {
        preserveState: true,
        onSuccess: () => {
            reloadRoles(() => {
                roleForm.reset();
            });
        },
    });
};

const saveRole = () => {
    roleForm.put(
        route('admin.agents.roles.update', { id: props.agent.id, roleId: editingRole.value.id }),
        {
            preserveState: true,
            onSuccess: () => {
                reloadRoles(() => {
                    editingRole.value = null;
                    roleForm.reset();
                });
            },
        }
    );
};

const deleteRole = (role) => {
    if (!confirm(t('ag_confirm_delete_role', { code: role.code }))) return;
    router.delete(
        route('admin.agents.roles.destroy', { id: props.agent.id, roleId: role.id }),
        {
            preserveState: true,
            onSuccess: () => {
                reloadRoles();
            },
        }
    );
};

watch(() => show.value, (visible) => {
    document.body.style.overflow = visible ? 'hidden' : '';
    visible
        ? document.addEventListener('keydown', handleEscape)
        : document.removeEventListener('keydown', handleEscape);
});
onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>