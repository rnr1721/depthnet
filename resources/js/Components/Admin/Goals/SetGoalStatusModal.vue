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
                                        <div class="w-8 h-8 bg-gradient-to-br from-gray-500 to-gray-700 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{ t('gm_change_status') }}</h3>
                                            <p v-if="goal" :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">#{{ goal.number }} {{ goal.title }}</p>
                                        </div>
                                    </div>
                                    <button @click="close" :class="['rounded-lg p-2 transition-colors focus:outline-none', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="px-6 py-5 space-y-3">
                                <button v-for="option in statusOptions" :key="option.value"
                                    @click="selectStatus(option.value)"
                                    :class="['w-full flex items-center justify-between px-4 py-3 rounded-xl border-2 transition-all focus:outline-none', selectedStatus === option.value ? option.selectedClass : (isDark ? 'border-gray-700 hover:border-gray-500' : 'border-gray-200 hover:border-gray-300')]">
                                    <div class="flex items-center gap-3">
                                        <span :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', option.dotClass]"></span>
                                        <div class="text-left">
                                            <div :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">{{ t(option.labelKey) }}</div>
                                            <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">{{ t(option.hintKey) }}</div>
                                        </div>
                                    </div>
                                    <svg v-if="selectedStatus === option.value" class="w-5 h-5 text-current flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </button>
                            </div>

                            <div :class="['px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button type="button" @click="close"
                                    :class="['w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('gm_cancel') }}
                                </button>
                                <button @click="submit" :disabled="processing || !selectedStatus || selectedStatus === goal?.status"
                                    :class="['w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white']">
                                    {{ t('gm_apply_status') }}
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
const props = defineProps({ modelValue: Boolean, preset: Object, goal: Object, isDark: Boolean });
const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({ get: () => props.modelValue, set: (v) => emit('update:modelValue', v) });
const selectedStatus = ref(null);
const processing = ref(false);

const statusOptions = [
    { value: 'active', labelKey: 'gm.status_active', hintKey: 'gm.status_active_hint', dotClass: 'bg-amber-500',  selectedClass: 'border-amber-500 text-amber-500' },
    { value: 'paused', labelKey: 'gm.status_paused', hintKey: 'gm.status_paused_hint', dotClass: 'bg-gray-400',   selectedClass: 'border-gray-400 text-gray-400' },
    { value: 'done',   labelKey: 'gm.status_done',   hintKey: 'gm.status_done_hint',   dotClass: 'bg-green-500',  selectedClass: 'border-green-500 text-green-500' },
];

const selectStatus = (value) => { selectedStatus.value = value; };

const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };
const close = () => { show.value = false; selectedStatus.value = null; document.body.style.overflow = ''; };

const submit = () => {
    if (!selectedStatus.value) return;
    processing.value = true;
    router.patch(route('admin.goals.set-status', props.goal?.number), {
        preset_id: props.preset?.id,
        status: selectedStatus.value,
    }, {
        onSuccess: () => { close(); emit('success'); },
        onFinish: () => { processing.value = false; },
    });
};

watch(() => show.value, (v) => {
    if (v) selectedStatus.value = props.goal?.status ?? null;
    document.body.style.overflow = v ? 'hidden' : '';
    v ? document.addEventListener('keydown', handleEscape) : document.removeEventListener('keydown', handleEscape);
});
watch(() => props.goal, (g) => { if (show.value && g) selectedStatus.value = g.status; });
onUnmounted(() => { document.removeEventListener('keydown', handleEscape); document.body.style.overflow = ''; });
</script>
