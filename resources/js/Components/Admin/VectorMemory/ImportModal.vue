<template>
    <!-- Modal Overlay with Teleport and higher z-index -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <!-- Background overlay with better styling -->
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity" @click="close"
                        aria-hidden="true"></div>

                    <!-- Helper element for centering -->
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                    <!-- Modal panel -->
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <div v-if="show" :class="[
                            'relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle',
                            isDark ? 'bg-gray-800' : 'bg-white'
                        ]" role="dialog" aria-modal="true" aria-labelledby="modal-title">

                            <!-- Header -->
                            <div :class="[
                                'px-6 py-4 border-b',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div
                                            class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10">
                                                </path>
                                            </svg>
                                        </div>
                                        <h3 id="modal-title" :class="[
                                            'text-lg font-semibold',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">
                                            {{ t('vm_import_vector_memory') }}
                                        </h3>
                                    </div>
                                    <button @click="close" :class="[
                                        'rounded-lg p-2 transition-colors hover:bg-opacity-75 focus:outline-none focus:ring-2 focus:ring-blue-500',
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
                            <form @submit.prevent="submit" enctype="multipart/form-data">
                                <div class="px-6 py-4 space-y-6">
                                    <!-- Import Method Selection -->
                                    <div>
                                        <label :class="[
                                            'block text-sm font-medium mb-3',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            {{ t('vm_import_method') }}
                                        </label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <label :class="[
                                                'flex items-center p-4 rounded-xl border-2 cursor-pointer transition-all',
                                                importMethod === 'file'
                                                    ? (isDark ? 'border-blue-500 bg-blue-900 bg-opacity-20' : 'border-blue-500 bg-blue-50')
                                                    : (isDark ? 'border-gray-600 hover:border-gray-500' : 'border-gray-300 hover:border-gray-400')
                                            ]">
                                                <input v-model="importMethod" type="radio" value="file"
                                                    class="sr-only" />
                                                <div class="flex items-center space-x-3">
                                                    <div :class="[
                                                        'w-4 h-4 border-2 rounded-full flex items-center justify-center',
                                                        importMethod === 'file' ? 'border-blue-500' : 'border-gray-400'
                                                    ]">
                                                        <div v-if="importMethod === 'file'"
                                                            class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                                    </div>
                                                    <div>
                                                        <div
                                                            :class="['font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                                            {{ t('vm_from_file') }}
                                                        </div>
                                                        <div
                                                            :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                                            {{ t('vm_upload_json_txt') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label :class="[
                                                'flex items-center p-4 rounded-xl border-2 cursor-pointer transition-all',
                                                importMethod === 'text'
                                                    ? (isDark ? 'border-blue-500 bg-blue-900 bg-opacity-20' : 'border-blue-500 bg-blue-50')
                                                    : (isDark ? 'border-gray-600 hover:border-gray-500' : 'border-gray-300 hover:border-gray-400')
                                            ]">
                                                <input v-model="importMethod" type="radio" value="text"
                                                    class="sr-only" />
                                                <div class="flex items-center space-x-3">
                                                    <div :class="[
                                                        'w-4 h-4 border-2 rounded-full flex items-center justify-center',
                                                        importMethod === 'text' ? 'border-blue-500' : 'border-gray-400'
                                                    ]">
                                                        <div v-if="importMethod === 'text'"
                                                            class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                                    </div>
                                                    <div>
                                                        <div
                                                            :class="['font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                                            {{ t('vm_from_text') }}
                                                        </div>
                                                        <div
                                                            :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                                            {{ t('vm_paste_content_directly') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- File Upload -->
                                    <div v-if="importMethod === 'file'">
                                        <label :class="[
                                            'block text-sm font-medium mb-2',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            {{ t('vm_select_file') }}
                                        </label>
                                        <div :class="[
                                            'border-2 border-dashed rounded-xl p-6 text-center transition-colors',
                                            form.errors.file
                                                ? 'border-red-400'
                                                : (isDark ? 'border-gray-600 hover:border-gray-500' : 'border-gray-300 hover:border-gray-400')
                                        ]">
                                            <input ref="fileInput" type="file" accept=".json,.txt" @change="handleFileSelect"
                                                class="sr-only" />
                                            <div v-if="!selectedFile" class="space-y-2">
                                                <svg :class="[
                                                    'w-8 h-8 mx-auto',
                                                    isDark ? 'text-gray-400' : 'text-gray-500'
                                                ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12">
                                                    </path>
                                                </svg>
                                                <div>
                                                    <button type="button" @click="$refs.fileInput.click()" :class="[
                                                        'text-sm font-medium',
                                                        isDark ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-500'
                                                    ]">
                                                        {{ t('vm_click_to_upload') }}
                                                    </button>
                                                    <span
                                                        :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                                                        {{ t('vm_or_drag_and_drop') }}
                                                    </span>
                                                </div>
                                                <p :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-600']">
                                                    {{ t('vm_json_txt_files_only') }} ({{ t('vm_max_size_2mb') }})
                                                </p>
                                            </div>
                                            <div v-else class="flex items-center justify-center space-x-3">
                                                <svg :class="[
                                                    'w-6 h-6',
                                                    selectedFile.name.endsWith('.json') ? 'text-purple-500' : 'text-green-500'
                                                ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <div class="flex flex-col">
                                                    <span
                                                        :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                                        {{ selectedFile.name }}
                                                    </span>
                                                    <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                                        {{ formatFileSize(selectedFile.size) }} • 
                                                        {{ selectedFile.name.endsWith('.json') ? t('vm_json_export') : t('vm_text_file') }}
                                                    </span>
                                                </div>
                                                <button type="button" @click="clearFile" :class="[
                                                    'text-xs px-2 py-1 rounded',
                                                    isDark ? 'text-red-400 hover:bg-red-900 hover:bg-opacity-20' : 'text-red-600 hover:bg-red-100'
                                                ]">
                                                    {{ t('vm_remove') }}
                                                </button>
                                            </div>
                                        </div>
                                        <div v-if="form.errors.file" class="text-red-500 text-xs mt-1">
                                            {{ form.errors.file }}
                                        </div>
                                    </div>

                                    <!-- Text Input -->
                                    <div v-else>
                                        <label :class="[
                                            'block text-sm font-medium mb-2',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            {{ t('vm_memory_content') }}
                                        </label>
                                        <textarea v-model="textContent" rows="10"
                                            :placeholder="t('vm_paste_content_placeholder')" :class="[
                                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all resize-none px-4 py-3 text-sm leading-relaxed font-mono',
                                                form.errors.content
                                                    ? 'ring-red-500 focus:ring-red-500'
                                                    : 'ring-gray-300 focus:ring-blue-500',
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
                                                {{ textContent?.length || 0 }} {{ t('vm_chars') }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Import Options -->
                                    <div :class="[
                                        'p-4 rounded-xl border',
                                        isDark ? 'border-gray-600 bg-gray-700 bg-opacity-50' : 'border-gray-300 bg-gray-50'
                                    ]">
                                        <h4 :class="['font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ t('vm_import_options') }}
                                        </h4>
                                        <label :class="[
                                            'flex items-center space-x-3 cursor-pointer',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            <input v-model="replaceExisting" type="checkbox" :class="[
                                                'w-4 h-4 rounded border-2 focus:ring-2 focus:ring-blue-500 text-blue-600',
                                                isDark ? 'focus:ring-offset-gray-800' : ''
                                            ]" />
                                            <div>
                                                <div class="text-sm font-medium">{{ t('vm_replace_existing_memories') }}
                                                </div>
                                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                                    {{ t('vm_clear_current_before_import') }}
                                                </div>
                                            </div>
                                        </label>
                                    </div>

                                    <!-- Vectorization Info -->
                                    <div :class="[
                                        'p-4 rounded-xl border',
                                        isDark ? 'border-purple-600 bg-purple-900 bg-opacity-20' : 'border-purple-300 bg-purple-50'
                                    ]">
                                        <div class="flex items-start space-x-3">
                                            <svg :class="[
                                                'w-5 h-5 mt-0.5 flex-shrink-0',
                                                isDark ? 'text-purple-400' : 'text-purple-600'
                                            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <div>
                                                <h4 :class="[
                                                    'text-sm font-medium mb-1',
                                                    isDark ? 'text-purple-200' : 'text-purple-800'
                                                ]">
                                                    {{ t('vm_automatic_vectorization') }}
                                                </h4>
                                                <p :class="[
                                                    'text-xs leading-relaxed',
                                                    isDark ? 'text-purple-300' : 'text-purple-700'
                                                ]">
                                                    {{ t('vm_import_vectorization_note') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Preset Info -->
                                    <div v-if="preset" :class="[
                                        'p-3 rounded-lg text-sm',
                                        isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-700'
                                    ]">
                                        <strong>{{ t('vm_preset') }}:</strong> {{ preset.name }}
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
                                        {{ t('vm_cancel') }}
                                    </button>
                                    <button type="submit" :disabled="form.processing || !canSubmit" :class="[
                                        'w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                                        'disabled:opacity-50 disabled:cursor-not-allowed',
                                        isDark
                                            ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-offset-gray-800'
                                            : 'bg-blue-600 hover:bg-blue-700 text-white'
                                    ]">
                                        <span v-if="form.processing" class="flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            {{ t('vm_importing') }}...
                                        </span>
                                        <span v-else>{{ t('vm_import_memories') }}</span>
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
import { ref, computed, watch, onUnmounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
});

const emit = defineEmits(['update:modelValue', 'success']);

const isDark = ref(false);
const importMethod = ref('file');
const textContent = ref('');
const selectedFile = ref(null);
const replaceExisting = ref(false);
const fileInput = ref(null);

const show = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const form = useForm({
    preset_id: null,
    file: null,
    content: '',
    replace_existing: false,
});

const canSubmit = computed(() => {
    if (importMethod.value === 'file') {
        return selectedFile.value !== null;
    } else {
        return textContent.value?.trim().length > 0;
    }
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

const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        const isValidType = file.type === 'application/json' || 
                           file.type === 'text/plain' || 
                           file.name.endsWith('.json') || 
                           file.name.endsWith('.txt');
        
        if (!isValidType) {
            alert(t('vm_please_select_json_txt_file'));
            return;
        }
        if (file.size > 2 * 1024 * 1024) { // 2MB
            alert(t('vm_file_too_large'));
            return;
        }
        selectedFile.value = file;
        form.file = file;
    }
};

const clearFile = () => {
    selectedFile.value = null;
    form.file = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
    textContent.value = '';
    selectedFile.value = null;
    replaceExisting.value = false;
    importMethod.value = 'file';
    toggleBodyScroll(false);
};

const submit = () => {
    form.preset_id = props.preset?.id;
    form.replace_existing = replaceExisting.value;

    if (importMethod.value === 'text') {
        form.content = textContent.value;
        form.file = null;
    } else {
        form.content = '';
    }

    form.post(route('admin.vector-memory.import'), {
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

        // Focus management and escape key handling
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