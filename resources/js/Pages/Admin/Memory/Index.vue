<template>
    <PageTitle :title="t('memory_manager')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('memory_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-purple-500' : 'bg-purple-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-indigo-500' : 'bg-indigo-300'
                ]"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Flash Messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800'
                    ]">
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
                    <div v-if="$page.props.flash.error" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800'
                    ]">
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

                <!-- Header Section -->
                <div :class="[
                    'mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <!-- Preset Selector -->
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('mm_select_preset') }}
                            </label>
                            <select v-model="selectedPresetId" @change="changePreset" :class="[
                                'w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
                                isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300'
                            ]">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>

                        <!-- Memory Stats -->
                        <div v-if="currentPreset" class="flex flex-col lg:flex-row space-y-2 lg:space-y-0 lg:space-x-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ memoryStats.total_items }}
                                </div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('mm_items') }}
                                </div>
                            </div>
                            <div class="text-center">
                                <div :class="[
                                    'text-2xl font-bold',
                                    memoryStats.is_over_limit ? 'text-red-500' :
                                        memoryStats.is_near_limit ? 'text-yellow-500' :
                                            isDark ? 'text-green-400' : 'text-green-600'
                                ]">
                                    {{ memoryStats.usage_percentage }}%
                                </div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ memoryStats.total_length }} / {{ memoryStats.limit }} {{ t('chars') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div :class="[
                    'mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                        <!-- Search -->
                        <div class="flex-1 max-w-md">
                            <div class="flex space-x-2">
                                <div class="relative flex-1">
                                    <input v-model="searchQuery" @keyup.enter="performSearch"
                                        :placeholder="t('mm_search_memory')" :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all pl-10 pr-4 py-3',
                                            isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500'
                                        ]" />
                                    <svg class="absolute left-3 top-3.5 w-4 h-4 text-gray-400" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>

                                <!-- Search and reset search button -->
                                <button @click="performSearch" :class="[
                                    'px-4 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                                    isDark ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-offset-gray-800' : 'bg-blue-600 hover:bg-blue-700 text-white'
                                ]">
                                    {{ t('search') }}
                                </button>

                                <!-- Search reset buttons -->
                                <button v-if="searchQuery" @click="clearSearch" :class="[
                                    'px-4 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                    isDark ? 'bg-gray-600 hover:bg-gray-700 text-white focus:ring-offset-gray-800' : 'bg-gray-600 hover:bg-gray-700 text-white'
                                ]">
                                    {{ t('clear') }}
                                </button>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-3">
                            <button @click="showAddModal = true" :class="[
                                'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800' : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                            ]">
                                {{ t('mm_add_item') }}
                            </button>
                            <button @click="exportMemory" :class="[
                                'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2',
                                isDark ? 'bg-green-600 hover:bg-green-700 text-white focus:ring-offset-gray-800' : 'bg-green-600 hover:bg-green-700 text-white'
                            ]">
                                {{ t('mm_export') }}
                            </button>
                            <button @click="showImportModal = true" :class="[
                                'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                                isDark ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-offset-gray-800' : 'bg-blue-600 hover:bg-blue-700 text-white'
                            ]">
                                {{ t('mm_import') }}
                            </button>
                            <button @click="clearMemory" :class="[
                                'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2',
                                isDark ? 'bg-red-600 hover:bg-red-700 text-white focus:ring-offset-gray-800' : 'bg-red-600 hover:bg-red-700 text-white'
                            ]">
                                {{ t('mm_clear_all') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Memory Items List -->
                <div v-if="memoryItems.length > 0" :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                        <div class="flex items-center justify-between">
                            <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                <span v-if="props.searchQuery">
                                    {{ t('mm_search_results') }} ({{ memoryItems.length }})
                                </span>
                                <span v-else>
                                    {{ t('mm_memory_items') }} ({{ memoryItems.length }})
                                </span>
                            </h3>

                            <!-- Search info и кнопка очистки справа -->
                            <div v-if="props.searchQuery" class="flex items-center space-x-3">
                                <span :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('mm_searching_for') }}: "<span class="font-medium">{{ props.searchQuery
                                        }}</span>"
                                </span>
                                <button @click="clearSearch" :class="[
                                    'text-sm px-3 py-1 rounded-lg transition-colors hover:bg-opacity-80',
                                    isDark ? 'text-blue-400 hover:bg-gray-700' : 'text-blue-600 hover:bg-gray-100'
                                ]">
                                    {{ t('mm_clear_search') }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="(item, index) in memoryItems" :key="item.id" :class="[
                            'p-6 hover:bg-opacity-50 transition-colors',
                            isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-50'
                        ]">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <span :class="[
                                            'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                            isDark ? 'bg-indigo-900 text-indigo-200' : 'bg-indigo-100 text-indigo-800'
                                        ]">
                                            #{{ item.position }}
                                        </span>
                                        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            {{ item.content.length }} {{ t('mm_chars') }}
                                        </span>
                                    </div>
                                    <p :class="[
                                        'text-sm leading-relaxed whitespace-pre-wrap',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ item.content }}</p>
                                </div>
                                <div class="flex items-center space-x-2 ml-4">
                                    <button @click="editItem(item)" :class="[
                                        'p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500',
                                        isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-600 hover:text-gray-800'
                                    ]">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                    </button>
                                    <button @click="deleteItem(item.position)" :class="[
                                        'p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500',
                                        isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-600 hover:text-red-800'
                                    ]">
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
                </div>

                <!-- Empty State -->
                <div v-else :class="[
                    'text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- If search active and no results -->
                    <div v-if="props.searchQuery" class="space-y-4">
                        <div
                            class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('mm_no_search_results') }}
                        </h3>
                        <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('mm_no_search_results_description', { query: props.searchQuery }) }}
                        </p>
                        <div class="flex flex-col sm:flex-row gap-3 justify-center">
                            <button @click="clearSearch" :class="[
                                'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
                                isDark ? 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-offset-gray-800' : 'bg-blue-600 hover:bg-blue-700 text-white'
                            ]">
                                {{ t('mm_show_all_items') }}
                            </button>
                            <button @click="showAddModal = true" :class="[
                                'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800' : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                            ]">
                                {{ t('mm_add_item') }}
                            </button>
                        </div>
                    </div>

                    <!-- If we have no records -->
                    <div v-else class="space-y-4">
                        <div
                            class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-gray-400 to-gray-600 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                                </path>
                            </svg>
                        </div>
                        <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('mm_no_memory_items') }}
                        </h3>
                        <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('mm_no_memory_items_description') }}
                        </p>
                        <button @click="showAddModal = true" :class="[
                            'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                            isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800' : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                        ]">
                            {{ t('mm_add_first_item') }}
                        </button>
                    </div>
                </div>
            </div>
        </main>

        <!-- Modals -->
        <AddItemModal v-model="showAddModal" :preset="currentPreset" @success="refreshData" />
        <EditItemModal v-model="showEditModal" :item="editingItem" :preset="currentPreset" @success="refreshData" />
        <ImportModal v-model="showImportModal" :preset="currentPreset" @success="refreshData" />
        <!-- 
        <ReplaceMemoryModal v-model="showReplaceModal" :preset="currentPreset" @success="refreshData" />
        -->
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddItemModal from '@/Components/Admin/Memory/AddItemModal.vue';
import EditItemModal from '@/Components/Admin/Memory/EditItemModal.vue';
import ImportModal from '@/Components/Admin/Memory/ImportModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    memoryItems: Array,
    memoryStats: Object,
    config: Object,
    searchQuery: String,
    isSearching: Boolean
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const searchQuery = ref(props.searchQuery || '');

// Modal states
const showAddModal = ref(false);
const showEditModal = ref(false);
const showReplaceModal = ref(false);
const showImportModal = ref(false);
const editingItem = ref(null);

const changePreset = () => {
    router.get(route('admin.memory.index'), { preset_id: selectedPresetId.value });
};

const performSearch = () => {
    if (searchQuery.value.trim()) {
        router.post(route('admin.memory.search'), {
            preset_id: selectedPresetId.value,
            search_term: searchQuery.value.trim()
        });
    }
};

const clearSearch = () => {
    searchQuery.value = '';
    router.get(route('admin.memory.index'), {
        preset_id: selectedPresetId.value
    });
};

const editItem = (item) => {
    editingItem.value = item;
    showEditModal.value = true;
};

const deleteItem = (position) => {
    if (confirm(t('mm_confirm_delete_item'))) {
        router.delete(route('admin.memory.destroy', position), {
            data: { preset_id: selectedPresetId.value }
        });
    }
};

const clearMemory = () => {
    if (confirm(t('mm_confirm_clear_memory'))) {
        router.post(route('admin.memory.clear'), {
            preset_id: selectedPresetId.value
        });
    }
};

const exportMemory = () => {
    window.location.href = route('admin.memory.export', { preset_id: selectedPresetId.value });
};

const refreshData = () => {
    router.get(route('admin.memory.index'), { preset_id: selectedPresetId.value });
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>