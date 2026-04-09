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
                            :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
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
                                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                                                </path>
                                            </svg>
                                        </div>
                                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ t('ag_add_agent') }}</h3>
                                    </div>
                                    <button @click="close"
                                        :class="['rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Form -->
                            <div class="px-6 py-4 space-y-4">
                                <!-- Name -->
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                        t('ag_name') }} *</label>
                                    <input v-model="form.name" type="text" :placeholder="t('ag_name_placeholder')"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3', form.errors.name ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-indigo-500', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                    <div v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name
                                        }}</div>
                                </div>
                                <!-- Code -->
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ t('ag_code') }}
                                        <span
                                            :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                            t('ag_optional') }}</span>
                                    </label>
                                    <input v-model="form.code" type="text" :placeholder="t('ag_code_placeholder')"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3 font-mono', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                    <p :class="['mt-1 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('ag_code_hint') }}</p>
                                    <div v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code
                                        }}</div>
                                </div>
                                <!-- Description -->
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ t('ag_description') }}
                                        <span
                                            :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                            t('ag_optional') }}</span>
                                    </label>
                                    <input v-model="form.description" type="text"
                                        :placeholder="t('ag_description_placeholder')"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                </div>
                                <!-- Planner preset -->
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                        t('ag_planner_preset') }} *</label>
                                    <select v-model="form.planner_preset_id"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3', form.errors.planner_preset_id ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-indigo-500', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                        <option value="">{{ t('ag_select_preset') }}</option>
                                        <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                            {{ preset.name }}{{ preset.code ? ` (${preset.code})` : '' }}
                                        </option>
                                    </select>
                                    <p :class="['mt-1 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('ag_planner_hint') }}</p>
                                    <div v-if="form.errors.planner_preset_id" class="mt-1 text-xs text-red-500">{{
                                        form.errors.planner_preset_id }}</div>
                                </div>
                                <!-- Active toggle -->
                                <div class="flex items-center justify-between">
                                    <div>
                                        <label
                                            :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('ag_is_active') }}</label>
                                        <p :class="['text-xs mt-0.5', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                            t('ag_is_active_hint') }}</p>
                                    </div>
                                    <button type="button" @click="form.is_active = !form.is_active"
                                        :class="['relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2', form.is_active ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-200')]">
                                        <span
                                            :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out', form.is_active ? 'translate-x-5' : 'translate-x-0']"></span>
                                    </button>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div
                                :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button type="button" @click="close"
                                    :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('ag_cancel') }}
                                </button>
                                <button @click="submit"
                                    :disabled="form.processing || !form.name?.trim() || !form.planner_preset_id"
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
                                    <span v-else>{{ t('ag_create') }}</span>
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
import { computed, watch, onUnmounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    presets: Array,
    isDark: Boolean,
});
const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const form = useForm({
    name: '',
    code: '',
    description: '',
    planner_preset_id: '',
    is_active: true,
});

const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
    document.body.style.overflow = '';
};

const submit = () => {
    form.post(route('admin.agents.store'), {
        onSuccess: () => { close(); emit('success'); },
    });
};

watch(() => show.value, (visible) => {
    document.body.style.overflow = visible ? 'hidden' : '';
    visible ? document.addEventListener('keydown', handleEscape) : document.removeEventListener('keydown', handleEscape);
});
onUnmounted(() => { document.removeEventListener('keydown', handleEscape); document.body.style.overflow = ''; });
</script>