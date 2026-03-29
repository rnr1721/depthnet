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
                                            class="w-8 h-8 bg-gradient-to-br from-rose-500 to-pink-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z">
                                                </path>
                                            </svg>
                                        </div>
                                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ isExistingPerson ? t('pm_add_fact_for', { name: person.primary }) :
                                                t('pm_add_fact') }}
                                        </h3>
                                    </div>
                                    <button @click="close"
                                        :class="['rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-rose-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Form -->
                            <div class="px-6 py-4 space-y-4">

                                <!-- Existing person — readonly name badge -->
                                <div v-if="isExistingPerson">
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('pm_person_name') }}</label>
                                    <div
                                        :class="['w-full rounded-xl px-4 py-3 text-sm font-medium', isDark ? 'bg-gray-700 text-white' : 'bg-gray-100 text-gray-900']">
                                        {{ person.name }}
                                    </div>
                                </div>

                                <!-- New person — editable name -->
                                <div v-else>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('pm_person_name') }} *</label>
                                    <input v-model="form.person_name" type="text"
                                        :placeholder="t('pm_person_name_placeholder')"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3', form.errors.person_name ? 'ring-red-500 focus:ring-red-500' : 'focus:ring-rose-500', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                    <div v-if="form.errors.person_name" class="mt-1 text-xs text-red-500">{{
                                        form.errors.person_name }}</div>
                                    <p :class="['mt-1 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('pm_name_hint') }}</p>
                                </div>

                                <!-- Fact content -->
                                <div>
                                    <label
                                        :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('pm_fact_content') }} *</label>
                                    <textarea v-model="form.content" rows="4" :placeholder="t('pm_fact_placeholder')"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-rose-500 transition-all resize-none px-4 py-3 text-sm', form.errors.content ? 'ring-red-500' : '', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']"></textarea>
                                    <div v-if="form.errors.content" class="mt-1 text-xs text-red-500">{{
                                        form.errors.content }}</div>
                                    <div
                                        :class="['text-xs mt-1 text-right', isDark ? 'text-gray-500' : 'text-gray-400']">
                                        {{ form.content?.length || 0 }} {{ t('pm_chars') }}</div>
                                </div>

                                <div v-if="preset"
                                    :class="['p-3 rounded-lg text-sm', isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-700']">
                                    <strong>{{ t('pm_preset') }}:</strong> {{ preset.name }}
                                </div>
                            </div>

                            <!-- Actions -->
                            <div
                                :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button type="button" @click="close"
                                    :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('pm_cancel') }}
                                </button>
                                <button @click="submit" :disabled="form.processing || !canSubmit"
                                    :class="['w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-rose-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-rose-600 hover:bg-rose-700 text-white']">
                                    <span v-if="form.processing" class="flex items-center justify-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('pm_saving') }}
                                    </span>
                                    <span v-else>{{ t('pm_save_fact') }}</span>
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
    preset: Object,
    // Pass null/undefined for "new person" mode,
    // pass person object { name, primary, aliases, facts } for "existing" mode
    person: { type: Object, default: null },
    isDark: Boolean,
});
const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

// True when opened from a person card (adding fact to existing person)
const isExistingPerson = computed(() => !!props.person);

const canSubmit = computed(() => {
    if (!form.content?.trim()) return false;
    if (!isExistingPerson.value && !form.person_name?.trim()) return false;
    return true;
});

const form = useForm({ preset_id: null, person_name: '', content: '' });
const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
    document.body.style.overflow = '';
};

const submit = () => {
    form.preset_id = props.preset?.id;
    // For existing person — use the canonical person_name from the person object
    if (isExistingPerson.value) {
        form.person_name = props.person.name;
    }
    form.post(route('admin.person-memory.add-fact'), {
        onSuccess: () => { close(); emit('success'); },
    });
};

watch(() => show.value, (visible) => {
    if (visible) {
        form.content = '';
        form.person_name = isExistingPerson.value ? props.person.name : '';
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleEscape);
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleEscape);
    }
});

onUnmounted(() => { document.removeEventListener('keydown', handleEscape); document.body.style.overflow = ''; });
</script>