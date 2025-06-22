<template>
    <!-- Modal Overlay with higher z-index and better positioning -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <!-- Background overlay with better styling -->
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity" @click="close"
                        aria-hidden="true"></div>

                    <!-- Modal panel with better z-index and positioning -->
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <div v-if="show" :class="[
                            'relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle',
                            isDark ? 'bg-gray-800' : 'bg-white'
                        ]" role="dialog" aria-modal="true" aria-labelledby="modal-title">

                            <!-- Header -->
                            <div :class="[
                                'px-6 py-4 border-b',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <div class="flex items-center justify-between">
                                    <h3 id="modal-title" :class="[
                                        'text-lg font-semibold',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('mm_add_memory_item') }}
                                    </h3>
                                    <button @click="close" :class="[
                                        'rounded-lg p-2 transition-colors hover:bg-opacity-75 focus:outline-none focus:ring-2 focus:ring-indigo-500',
                                        isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600'
                                    ]" aria-label="Close modal">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Form -->
                            <form @submit.prevent="submit">
                                <div class="px-6 py-4">
                                    <div class="mb-4">
                                        <label :class="[
                                            'block text-sm font-medium mb-2',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            {{ t('mm_memory_content') }}
                                        </label>
                                        <textarea v-model="form.content" rows="6"
                                            :placeholder="t('mm_enter_memory_content')" :class="[
                                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all resize-none px-4 py-3 text-sm leading-relaxed',
                                                form.errors.content
                                                    ? 'ring-red-500 focus:ring-red-500'
                                                    : 'ring-gray-300 focus:ring-indigo-500',
                                                isDark
                                                    ? (form.errors.content
                                                        ? 'bg-red-900 bg-opacity-20 text-white placeholder-gray-400'
                                                        : 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600')
                                                    : (form.errors.content
                                                        ? 'bg-red-50 text-gray-900 placeholder-gray-500'
                                                        : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                            ]"></textarea>
                                        <div class="flex justify-between items-center mt-2">
                                            <div v-if="form.errors.content" class="text-red-500 text-xs">
                                                {{ form.errors.content }}
                                            </div>
                                            <div class="text-xs opacity-50 ml-auto">
                                                {{ form.content?.length || 0 }} {{ t('mm_chars') }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Preset Info -->
                                    <div v-if="preset" :class="[
                                        'p-3 rounded-lg text-sm',
                                        isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-700'
                                    ]">
                                        <strong>{{ t('mm_preset') }}:</strong> {{ preset.name }}
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div :class="[
                                    'px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0',
                                    isDark ? 'border-gray-700' : 'border-gray-200'
                                ]">
                                    <button type="button" @click="close" :class="[
                                        'w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                        isDark
                                            ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                    ]">
                                        {{ t('mm_cancel') }}
                                    </button>
                                    <button type="submit" :disabled="form.processing || !form.content?.trim()" :class="[
                                        'w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                        'disabled:opacity-50 disabled:cursor-not-allowed',
                                        isDark
                                            ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
                                            : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                                    ]">
                                        <span v-if="form.processing" class="flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            {{ t('mm_adding') }}...
                                        </span>
                                        <span v-else>{{ t('mm_add_item') }}</span>
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
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
});

const emit = defineEmits(['update:modelValue', 'success']);

const isDark = ref(false);

const show = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const form = useForm({
    preset_id: null,
    content: '',
});

/**
 * Handle escape key to close modal
 */
const handleEscape = (event) => {
    if (event.key === 'Escape' && show.value) {
        close();
    }
};

/**
 * Prevent body scroll when modal is open
 */
const toggleBodyScroll = (disable) => {
    if (disable) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
};

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
    toggleBodyScroll(false);
};

const submit = () => {
    form.preset_id = props.preset?.id;

    form.post(route('admin.memory.store'), {
        onSuccess: () => {
            close();
            emit('success');
        }
    });
};

// Watch for preset changes
watch(() => props.preset, (newPreset) => {
    if (newPreset) {
        form.preset_id = newPreset.id;
    }
}, { immediate: true });

// Watch modal state changes
watch(() => show.value, (isVisible) => {
    if (isVisible) {
        const savedTheme = localStorage.getItem('chat-theme');
        isDark.value = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
        toggleBodyScroll(true);

        // Focus management
        document.addEventListener('keydown', handleEscape);
    } else {
        toggleBodyScroll(false);
        document.removeEventListener('keydown', handleEscape);
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    toggleBodyScroll(false);
});
</script>