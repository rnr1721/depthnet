<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="modelValue" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <!-- Backdrop -->
                    <div class="fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm" @click="close"></div>

                    <!-- Modal -->
                    <div
                        :class="['relative w-full max-w-md rounded-2xl shadow-2xl border transition-all', isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200']">
                        <!-- Header -->
                        <div
                            :class="['flex items-center justify-between p-6 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                            <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('docs_upload_modal_title') }}
                            </h3>
                            <button @click="close"
                                :class="['p-1 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-white hover:bg-gray-700' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100']">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <form @submit.prevent="submit" class="p-6 space-y-5">
                            <!-- Drop zone -->
                            <div @dragover.prevent @drop.prevent="onDrop" @click="$refs.fileInput.click()"
                                :class="['border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-all', selectedFile ? (isDark ? 'border-teal-500 bg-teal-900 bg-opacity-20' : 'border-teal-500 bg-teal-50') : (isDark ? 'border-gray-600 hover:border-gray-500 hover:bg-gray-700' : 'border-gray-300 hover:border-gray-400 hover:bg-gray-50')]">
                                <input ref="fileInput" type="file" class="hidden" @change="onFileSelected" />
                                <div v-if="selectedFile">
                                    <div class="text-3xl mb-2">{{ fileEmoji }}</div>
                                    <div :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">{{
                                        selectedFile.name }}</div>
                                    <div :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                        humanSize(selectedFile.size) }}</div>
                                </div>
                                <div v-else>
                                    <svg :class="['w-10 h-10 mx-auto mb-3', isDark ? 'text-gray-500' : 'text-gray-400']"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                    </svg>
                                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                        t('docs_drop_or_click') }}</p>
                                    <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('docs_max_size') }}</p>
                                </div>
                            </div>

                            <!-- Storage driver -->
                            <div>
                                <label
                                    :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                    {{ t('docs_storage_driver') }}
                                </label>
                                <div class="grid grid-cols-2 gap-3">
                                    <button type="button" v-for="d in drivers" :key="d.value"
                                        @click="form.driver = d.value"
                                        :class="['p-3 rounded-xl border-2 text-left transition-all', form.driver === d.value ? (isDark ? 'border-teal-500 bg-teal-900 bg-opacity-30' : 'border-teal-500 bg-teal-50') : (isDark ? 'border-gray-700 hover:border-gray-600' : 'border-gray-200 hover:border-gray-300')]">
                                        <div :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ d.label }}</div>
                                        <div :class="['text-xs mt-0.5', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                            d.desc }}</div>
                                    </button>
                                </div>
                            </div>

                            <!-- Scope -->
                            <div>
                                <label
                                    :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                    {{ t('docs_scope') }}
                                </label>
                                <select v-model="form.scope"
                                    :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-teal-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                    <option value="private">{{ t('docs_scope_private') }}</option>
                                    <option value="global">{{ t('docs_scope_global') }}</option>
                                </select>
                            </div>

                            <!-- Error -->
                            <div v-if="error"
                                :class="['p-3 rounded-xl text-sm', isDark ? 'bg-red-900 bg-opacity-50 text-red-300' : 'bg-red-50 text-red-700']">
                                {{ error }}
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-3 pt-2">
                                <button type="button" @click="close"
                                    :class="['flex-1 px-4 py-3 rounded-xl font-medium transition-all', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('docs_cancel') }}
                                </button>
                                <button type="submit" :disabled="!selectedFile || uploading"
                                    :class="['flex-1 px-4 py-3 rounded-xl font-medium transition-all flex items-center justify-center gap-2', !selectedFile || uploading ? 'opacity-50 cursor-not-allowed' : '', isDark ? 'bg-teal-600 hover:bg-teal-700 text-white' : 'bg-teal-600 hover:bg-teal-700 text-white']">
                                    <svg v-if="uploading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                            stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    {{ uploading ? t('docs_uploading') : t('docs_upload_btn') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
});

const emit = defineEmits(['update:modelValue', 'success']);

const isDark = ref(false);
const selectedFile = ref(null);
const uploading = ref(false);
const error = ref(null);

const form = ref({
    driver: 'laravel',
    scope: 'private',
});

const drivers = computed(() => [
    { value: 'laravel', label: t('docs_driver_laravel'), desc: t('docs_driver_laravel_desc') },
    { value: 'sandbox', label: t('docs_driver_sandbox'), desc: t('docs_driver_sandbox_desc') },
]);

const fileEmoji = computed(() => {
    if (!selectedFile.value) return '📁';
    const name = selectedFile.value.name.toLowerCase();
    if (name.endsWith('.pdf')) return '📄';
    if (name.match(/\.(xls|xlsx|ods|csv)$/)) return '📊';
    if (name.match(/\.(doc|docx|odt)$/)) return '📝';
    if (name.match(/\.(jpg|jpeg|png|gif|webp)$/)) return '🖼️';
    if (name.match(/\.(php|js|ts|py|sh)$/)) return '💻';
    return '📁';
});

const humanSize = (bytes) => {
    if (!bytes) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1_048_576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1_048_576).toFixed(1) + ' MB';
};

const onFileSelected = (e) => {
    selectedFile.value = e.target.files[0] || null;
    error.value = null;
};

const onDrop = (e) => {
    selectedFile.value = e.dataTransfer.files[0] || null;
    error.value = null;
};

const close = () => {
    if (uploading.value) return;
    selectedFile.value = null;
    error.value = null;
    emit('update:modelValue', false);
};

const submit = () => {
    if (!selectedFile.value || !props.preset) return;

    error.value = null;
    uploading.value = true;

    const data = new FormData();
    data.append('file', selectedFile.value);
    data.append('preset_id', props.preset.id);
    data.append('driver', form.value.driver);
    data.append('scope', form.value.scope);

    router.post(route('admin.documents.store'), data, {
        forceFormData: true,
        onSuccess: () => {
            uploading.value = false;
            close();
            emit('success');
        },
        onError: (errors) => {
            uploading.value = false;
            error.value = Object.values(errors)[0] || t('docs_upload_error');
        },
    });
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>