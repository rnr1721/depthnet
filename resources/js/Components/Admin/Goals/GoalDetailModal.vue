<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="close"></div>
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:scale-95">
                        <div v-if="show"
                            :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                            role="dialog">

                            <!-- Header -->
                            <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3 min-w-0">
                                        <span :class="['font-mono text-sm font-bold px-2.5 py-1 rounded-lg flex-shrink-0', isDark ? 'bg-amber-900 text-amber-300' : 'bg-amber-100 text-amber-800']">#{{ goal?.number }}</span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <h3 :class="['text-lg font-semibold truncate', isDark ? 'text-white' : 'text-gray-900']">{{ goal?.title }}</h3>
                                                <span v-if="goal" :class="statusBadgeClass(goal.status)">{{ t('gm_status_' + goal.status) }}</span>
                                            </div>
                                            <p v-if="goal?.motivation" :class="['text-xs mt-0.5', isDark ? 'text-gray-400' : 'text-gray-500']">💡 {{ goal.motivation }}</p>
                                        </div>
                                    </div>
                                    <button @click="close" :class="['ml-4 flex-shrink-0 rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Progress list -->
                            <div class="max-h-[50vh] overflow-y-auto">
                                <div v-if="!goal?.progress?.length"
                                    :class="['px-6 py-8 text-center text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                                    {{ t('gm_no_progress') }}
                                </div>
                                <div v-else class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                                    <div v-for="(entry, idx) in goal.progress" :key="entry.id"
                                        :class="['px-6 py-4 flex items-start space-x-3', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']">
                                        <span :class="['font-mono text-xs font-bold mt-0.5 flex-shrink-0', isDark ? 'text-amber-400' : 'text-amber-600']">{{ idx + 1 }}.</span>
                                        <div class="flex-1 min-w-0">
                                            <p :class="['text-sm leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-700']">{{ entry.content }}</p>
                                            <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">{{ entry.created_at }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Inline add progress -->
                            <div :class="['px-6 py-4 border-t', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{ t('gm_add_progress') }}</label>
                                <div class="flex gap-2">
                                    <textarea v-model="progressNote" rows="2" :placeholder="t('gm_progress_placeholder')"
                                        :class="['flex-1 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 transition-all resize-none px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']"
                                        @keydown.ctrl.enter="submitProgress"></textarea>
                                    <button @click="submitProgress" :disabled="progressForm.processing || !progressNote.trim()"
                                        :class="['px-4 rounded-xl font-medium text-sm transition-all focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50 self-end py-2', isDark ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white']">
                                        {{ t('gm_save') }}
                                    </button>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div :class="['px-6 py-4 border-t flex justify-end', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button @click="close"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('gm_close') }}
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
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
    goal: Object,
    isDark: Boolean,
});
const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const progressNote = ref('');
const progressForm = useForm({ preset_id: null, goal_number: null, content: '' });

const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

const close = () => {
    show.value = false;
    progressNote.value = '';
    progressForm.reset();
    document.body.style.overflow = '';
};

const statusBadgeClass = (status) => {
    const base = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium';
    if (status === 'active') return `${base} ${props.isDark ? 'bg-amber-900 text-amber-300' : 'bg-amber-100 text-amber-800'}`;
    if (status === 'done')   return `${base} ${props.isDark ? 'bg-green-900 text-green-300' : 'bg-green-100 text-green-800'}`;
    if (status === 'paused') return `${base} ${props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600'}`;
    return base;
};

const submitProgress = () => {
    if (!progressNote.value.trim()) return;
    progressForm.preset_id   = props.preset?.id;
    progressForm.goal_number = props.goal?.number;
    progressForm.content     = progressNote.value;
    progressForm.post(route('admin.goals.progress'), {
        onSuccess: () => {
            progressNote.value = '';
            progressForm.reset();
            emit('success');
        },
    });
};

watch(() => show.value, (visible) => {
    document.body.style.overflow = visible ? 'hidden' : '';
    visible ? document.addEventListener('keydown', handleEscape) : document.removeEventListener('keydown', handleEscape);
});
onUnmounted(() => { document.removeEventListener('keydown', handleEscape); document.body.style.overflow = ''; });
</script>
