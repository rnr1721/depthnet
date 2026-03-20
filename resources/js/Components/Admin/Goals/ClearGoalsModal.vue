<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity" @click="close"></div>
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <div v-if="show"
                            :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                            role="dialog">

                            <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 bg-gradient-to-br from-red-500 to-red-700 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                        </div>
                                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{ t('gm_clear_all') }}</h3>
                                    </div>
                                    <button @click="close" :class="['rounded-lg p-2 transition-colors focus:outline-none', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="px-6 py-5">
                                <p :class="['text-sm leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-600']">
                                    {{ t('gm_clear_confirm', { preset: preset?.name }) }}
                                </p>
                                <p :class="['mt-3 text-sm font-medium', isDark ? 'text-red-400' : 'text-red-600']">{{ t('gm_irreversible') }}</p>
                            </div>

                            <div :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button type="button" @click="close"
                                    :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('gm_cancel') }}
                                </button>
                                <button @click="confirm" :disabled="processing"
                                    :class="['w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-red-700 hover:bg-red-600 text-white' : 'bg-red-600 hover:bg-red-700 text-white']">
                                    <span v-if="processing" class="flex items-center justify-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        {{ t('gm_clearing') }}
                                    </span>
                                    <span v-else>{{ t('gm_clear_confirm_btn') }}</span>
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
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({ modelValue: Boolean, preset: Object, isDark: Boolean });
const emit = defineEmits(['update:modelValue', 'success']);
const show = computed({ get: () => props.modelValue, set: (v) => emit('update:modelValue', v) });
const processing = ref(false);
const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };
const close = () => { show.value = false; document.body.style.overflow = ''; };
const confirm = () => {
    processing.value = true;
    router.post(route('admin.goals.clear'), { preset_id: props.preset?.id }, {
        onSuccess: () => { close(); emit('success'); },
        onFinish:  () => { processing.value = false; },
    });
};
watch(() => show.value, (v) => { document.body.style.overflow = v ? 'hidden' : ''; v ? document.addEventListener('keydown', handleEscape) : document.removeEventListener('keydown', handleEscape); });
onUnmounted(() => { document.removeEventListener('keydown', handleEscape); document.body.style.overflow = ''; });
</script>
