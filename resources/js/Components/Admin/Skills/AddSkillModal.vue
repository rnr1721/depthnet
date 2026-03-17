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
                                            class="w-8 h-8 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                                                </path>
                                            </svg>
                                        </div>
                                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ t('sk_add_skill') }}</h3>
                                    </div>
                                    <button @click="close"
                                        :class="['rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Form -->
                            <form @submit.prevent="submit">
                                <div class="px-6 py-4 space-y-4">

                                    <!-- Title -->
                                    <div>
                                        <label
                                            :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('sk_title') }} *</label>
                                        <input v-model="form.title" type="text" :placeholder="t('sk_title_placeholder')"
                                            :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3', form.errors.title ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-emerald-500', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                        <div v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{
                                            form.errors.title }}</div>
                                    </div>

                                    <!-- Description (optional) -->
                                    <div>
                                        <label
                                            :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                            {{ t('sk_description') }}
                                            <span
                                                :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                                t('sk_optional') }}</span>
                                        </label>
                                        <input v-model="form.description" type="text"
                                            :placeholder="t('sk_description_placeholder')"
                                            :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                    </div>

                                    <!-- First item (optional) -->
                                    <div>
                                        <label
                                            :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                            {{ t('sk_first_item') }}
                                            <span
                                                :class="['ml-1 text-xs font-normal', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                                t('sk_optional') }}</span>
                                        </label>
                                        <textarea v-model="form.first_item" rows="4"
                                            :placeholder="t('sk_first_item_placeholder')"
                                            :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all resize-none px-4 py-3 text-sm', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']"></textarea>
                                        <div
                                            :class="['text-xs mt-1 text-right', isDark ? 'text-gray-500' : 'text-gray-400']">
                                            {{ form.first_item?.length || 0 }} {{ t('sk_chars') }}</div>
                                    </div>

                                    <!-- Preset info -->
                                    <div v-if="preset"
                                        :class="['p-3 rounded-lg text-sm', isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-700']">
                                        <strong>{{ t('sk_preset') }}:</strong> {{ preset.name }}
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div
                                    :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <button type="button" @click="close"
                                        :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                        t('sk_cancel') }}</button>
                                    <button type="submit" :disabled="form.processing || !form.title?.trim()"
                                        :class="['w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white']">
                                        <span v-if="form.processing" class="flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            {{ t('sk_processing') }}
                                        </span>
                                        <span v-else>{{ t('sk_create') }}</span>
                                    </button>
                                </div>
                            </form>
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
    preset: Object,
    isDark: Boolean,
});

const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const form = useForm({ preset_id: null, title: '', description: '', first_item: '' });

const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
    document.body.style.overflow = '';
};

const submit = () => {
    form.preset_id = props.preset?.id;
    form.post(route('admin.skills.store'), {
        onSuccess: () => { close(); emit('success'); },
    });
};

watch(() => show.value, (visible) => {
    if (visible) {
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleEscape);
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleEscape);
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>