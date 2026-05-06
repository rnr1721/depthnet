<template>
    <PageTitle :title="t('docs_title')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('docs_title')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-teal-500' : 'bg-teal-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-cyan-500' : 'bg-cyan-300']">
                </div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Flash Messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash?.success"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800']">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.success }}</span>
                        </div>
                    </div>
                </Transition>
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash?.error"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800']">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.error }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Header: Preset selector + Stats -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('docs_select_preset') }}
                            </label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-teal-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>

                        <!-- Stats -->
                        <div v-if="stats" class="flex flex-wrap gap-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    stats.total }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('docs_stat_files') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-teal-500">{{ stats.processed }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('docs_stat_processed') }}</div>
                            </div>
                            <div class="text-center" v-if="stats.failed > 0">
                                <div class="text-2xl font-bold text-red-500">{{ stats.failed }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('docs_stat_failed') }}</div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-blue-400' : 'text-blue-600']">{{
                                    humanSize(stats.total_size) }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('docs_stat_size') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                        <!-- Search -->
                        <div class="flex-1 max-w-md">
                            <div class="relative flex gap-2">
                                <div class="relative flex-1">
                                    <input v-model="searchQuery" @keyup.enter="performSearch"
                                        :placeholder="t('docs_search_placeholder')"
                                        :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-teal-500 transition-all pl-10 pr-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500']" />
                                    <svg class="absolute left-3 top-3.5 w-4 h-4 text-gray-400" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <button v-if="searchQuery" @click="clearSearch"
                                    :class="['px-3 py-2 rounded-xl font-medium transition-all text-sm', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                                    {{ t('docs_clear') }}
                                </button>
                            </div>
                        </div>

                        <!-- Upload button -->
                        <div class="flex flex-wrap gap-3">
                            <button @click="showUploadModal = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 flex items-center gap-2', isDark ? 'bg-teal-600 hover:bg-teal-700 text-white focus:ring-offset-gray-800' : 'bg-teal-600 hover:bg-teal-700 text-white']">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                {{ t('docs_upload') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search Results -->
                <div v-if="searchResults.length"
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        :class="['px-6 py-4 border-b flex items-center justify-between', isDark ? 'border-gray-700' : 'border-gray-200']">
                        <h3 :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('docs_search_results') }} ({{ searchResults.length }})
                        </h3>
                        <button @click="clearSearch"
                            :class="['text-sm', isDark ? 'text-gray-400 hover:text-gray-200' : 'text-gray-500 hover:text-gray-700']">
                            {{ t('docs_clear_results') }}
                        </button>
                    </div>
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-100'">
                        <div v-for="result in searchResults" :key="`${result.file_id}-${result.chunk_index}`"
                            class="px-6 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span
                                            :class="['text-sm font-medium truncate', isDark ? 'text-teal-400' : 'text-teal-700']">{{
                                                result.file_name }}</span>
                                        <span
                                            :class="['text-xs px-2 py-0.5 rounded-full', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500']">chunk
                                            #{{ result.chunk_index }}</span>
                                    </div>
                                    <p :class="['text-sm leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ result.content }}</p>
                                </div>
                                <div class="flex-shrink-0 text-right">
                                    <div
                                        :class="['text-lg font-bold', result.similarity >= 70 ? 'text-green-500' : result.similarity >= 40 ? 'text-yellow-500' : 'text-gray-400']">
                                        {{ result.similarity }}%
                                    </div>
                                    <div :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        result.source }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Files List -->
                <div v-if="files.length"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                        <h3 :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{ t('docs_files') }}
                        </h3>
                    </div>
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-100'">
                        <div v-for="file in files" :key="file.id" class="px-6 py-4 flex items-center gap-4">
                            <!-- Icon -->
                            <div
                                :class="['w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 text-lg', fileIconBg(file)]">
                                {{ fileIcon(file) }}
                            </div>

                            <!-- Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span :class="['font-medium truncate', isDark ? 'text-white' : 'text-gray-900']">{{
                                        file.original_name }}</span>
                                    <span :class="statusBadge(file.processing_status)">{{ t('docs_status_' +
                                        file.processing_status) }}</span>
                                    <span v-if="file.scope === 'global'"
                                        :class="['text-xs px-2 py-0.5 rounded-full', isDark ? 'bg-yellow-900 text-yellow-300' : 'bg-yellow-100 text-yellow-700']">global</span>
                                    <span
                                        :class="['text-xs px-2 py-0.5 rounded-full', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500']">{{
                                            file.storage_driver }}</span>
                                </div>
                                <div
                                    :class="['text-xs mt-1 flex items-center gap-3', isDark ? 'text-gray-400' : 'text-gray-500']">
                                    <span>{{ file.human_size }}</span>
                                    <span v-if="file.chunk_count">{{ file.chunk_count }} {{ t('docs_chunks') }}</span>
                                    <span>{{ formatDate(file.created_at) }}</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 flex-shrink-0">

                                <!-- Download -->
                                <a :href="route('admin.documents.download', { fileId: file.id, preset_id: selectedPresetId })"
                                    :title="t('docs_download')"
                                    :class="['p-2 rounded-lg transition-all', isDark ? 'text-gray-400 hover:text-teal-400 hover:bg-gray-700' : 'text-gray-500 hover:text-teal-600 hover:bg-teal-50']"
                                    download>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                </a>

                                <button
                                    v-if="file.processing_status === 'failed' || file.processing_status === 'pending'"
                                    @click="reprocessFile(file.id)" :title="t('docs_reprocess')"
                                    :class="['p-2 rounded-lg transition-all', isDark ? 'text-gray-400 hover:text-blue-400 hover:bg-gray-700' : 'text-gray-500 hover:text-blue-600 hover:bg-blue-50']">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                        </path>
                                    </svg>
                                </button>
                                <button @click="deleteFile(file.id, file.original_name)" :title="t('docs_delete')"
                                    :class="['p-2 rounded-lg transition-all', isDark ? 'text-gray-400 hover:text-red-400 hover:bg-gray-700' : 'text-gray-500 hover:text-red-600 hover:bg-red-50']">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                        </path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else-if="!searchResults.length"
                    :class="['text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-teal-400 to-cyan-600 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('docs_empty') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('docs_empty_desc') }}
                    </p>
                    <button @click="showUploadModal = true"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all', isDark ? 'bg-teal-600 hover:bg-teal-700 text-white' : 'bg-teal-600 hover:bg-teal-700 text-white']">
                        {{ t('docs_upload_first') }}
                    </button>
                </div>

                <!-- Pagination -->
                <PaginationComponent v-if="pagination && !searchResults.length" :pagination="pagination"
                    :isDark="isDark" class="mt-6" />
            </div>
        </main>

        <!-- Upload Modal -->
        <UploadFileModal v-model="showUploadModal" :preset="currentPreset" @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import PaginationComponent from '@/Components/Pagination.vue';
import UploadFileModal from '@/Components/Admin/Documents/UploadFileModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    files: Array,
    pagination: Object,
    stats: Object,
    searchResults: Array,
    searchQuery: String,
    perPage: Number,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const searchQuery = ref(props.searchQuery || '');
const showUploadModal = ref(false);

const changePreset = () => {
    router.get(route('admin.documents.index'), { preset_id: selectedPresetId.value });
};

const performSearch = () => {
    if (searchQuery.value.trim()) {
        router.get(route('admin.documents.index'), {
            preset_id: selectedPresetId.value,
            search: searchQuery.value.trim(),
        });
    }
};

const clearSearch = () => {
    searchQuery.value = '';
    router.get(route('admin.documents.index'), { preset_id: selectedPresetId.value });
};

const deleteFile = (fileId, name) => {
    if (confirm(t('docs_confirm_delete', { name }))) {
        router.delete(route('admin.documents.destroy', fileId), {
            data: { preset_id: selectedPresetId.value },
        });
    }
};

const reprocessFile = (fileId) => {
    router.post(route('admin.documents.reprocess', fileId), {
        preset_id: selectedPresetId.value,
    });
};

const refreshData = () => {
    router.get(route('admin.documents.index'), { preset_id: selectedPresetId.value });
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const humanSize = (bytes) => {
    if (!bytes) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1_048_576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1_048_576).toFixed(1) + ' MB';
};

const fileIcon = (file) => {
    const ext = file.extension;
    if (['pdf'].includes(ext)) return '📄';
    if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return '📊';
    if (['doc', 'docx', 'odt'].includes(ext)) return '📝';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return '🖼️';
    if (['json', 'xml', 'yaml', 'yml'].includes(ext)) return '⚙️';
    if (['php', 'js', 'ts', 'py', 'sh'].includes(ext)) return '💻';
    return '📁';
};

const fileIconBg = (file) => {
    const ext = file.extension;
    if (['pdf'].includes(ext)) return isDark.value ? 'bg-red-900 text-red-300' : 'bg-red-100';
    if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return isDark.value ? 'bg-green-900 text-green-300' : 'bg-green-100';
    if (['doc', 'docx', 'odt'].includes(ext)) return isDark.value ? 'bg-blue-900 text-blue-300' : 'bg-blue-100';
    return isDark.value ? 'bg-gray-700' : 'bg-gray-100';
};

const statusBadge = (status) => {
    const base = 'text-xs px-2 py-0.5 rounded-full';
    const map = {
        processed: isDark.value ? `${base} bg-teal-900 text-teal-300` : `${base} bg-teal-100 text-teal-700`,
        processing: isDark.value ? `${base} bg-yellow-900 text-yellow-300` : `${base} bg-yellow-100 text-yellow-700`,
        pending: isDark.value ? `${base} bg-gray-700 text-gray-300` : `${base} bg-gray-100 text-gray-600`,
        failed: isDark.value ? `${base} bg-red-900 text-red-300` : `${base} bg-red-100 text-red-700`,
    };
    return map[status] || base;
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
    }
    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>