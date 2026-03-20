<template>
    <PageTitle title="Workspace Manager" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader title="Workspace Manager" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-violet-500' : 'bg-violet-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-indigo-500' : 'bg-indigo-300']">
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

                <!-- Header: preset selector + stats -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('wm_select_preset') }}
                            </label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="text-center">
                            <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                {{ entries.length }}
                            </div>
                            <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">Keys</div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex items-center justify-between">
                        <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ t('wm_description') }}
                        </p>
                        <div class="flex gap-3">
                            <button v-if="entries.length > 0" @click="showClearConfirm = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-900 bg-opacity-60 hover:bg-red-800 text-red-300' : 'bg-red-50 hover:bg-red-100 text-red-700 border border-red-200']">
                                {{ t('wm_clear_all') }}
                            </button>
                            <button @click="showAddModal = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white focus:ring-offset-gray-800' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                {{ t('wm_add_entry') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Entries List -->
                <div v-if="entries.length > 0"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="entry in entries" :key="entry.key"
                            :class="['group p-5 transition-colors', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']">

                            <!-- View mode -->
                            <div v-if="editingKey !== entry.key" class="flex items-start justify-between space-x-4">
                                <div class="flex items-start space-x-3 flex-1 min-w-0">
                                    <span
                                        :class="['font-mono text-xs font-bold px-2 py-0.5 rounded mt-0.5 flex-shrink-0 whitespace-nowrap', isDark ? 'bg-violet-900 text-violet-300' : 'bg-violet-100 text-violet-800']">
                                        {{ entry.key }}
                                    </span>
                                    <p
                                        :class="['text-sm leading-relaxed whitespace-pre-wrap break-words min-w-0', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ entry.value }}
                                    </p>
                                </div>
                                <div
                                    class="flex items-center space-x-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="startEdit(entry)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700']"
                                        title="Edit">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                    </button>
                                    <button @click="deleteEntry(entry.key)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-500 hover:text-red-700']"
                                        title="Delete">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                            </path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Edit mode -->
                            <div v-else class="space-y-3">
                                <div class="flex items-center space-x-2">
                                    <span
                                        :class="['font-mono text-xs font-bold px-2 py-0.5 rounded', isDark ? 'bg-violet-900 text-violet-300' : 'bg-violet-100 text-violet-800']">
                                        {{ entry.key }}
                                    </span>
                                    <span
                                        :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">Editing</span>
                                </div>
                                <textarea v-model="editValue" rows="5"
                                    :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all resize-none px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']"
                                    @keydown.escape="cancelEdit"></textarea>
                                <div class="flex space-x-2">
                                    <button @click="saveEdit(entry.key)" :disabled="!editValue.trim()"
                                        :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-50', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                        {{ t('wm_save') }}
                                    </button>
                                    <button @click="cancelEdit"
                                        :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                        {{ t('wm_cancel') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else
                    :class="['text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-violet-400 to-indigo-600 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('wm_no_entries') }}
                    </h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('wm_no_entries_description') }}
                    </p>
                    <button @click="showAddModal = true"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                        {{ t('wm_add_first_entry') }}
                    </button>
                </div>

            </div>
        </main>

        <!-- Add Modal -->
        <AddWorkspaceEntryModal v-model="showAddModal" :preset="currentPreset" :existing-keys="entries.map(e => e.key)"
            :isDark="isDark" @success="refreshData" />

        <!-- Clear Confirm Modal -->
        <ClearWorkspaceModal v-model="showClearConfirm" :preset="currentPreset" :isDark="isDark"
            @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, useForm } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddWorkspaceEntryModal from '@/Components/Admin/Workspace/AddWorkspaceEntryModal.vue';
import ClearWorkspaceModal from '@/Components/Admin/Workspace/ClearWorkspaceModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    entries: Array,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);

const showAddModal = ref(false);
const showClearConfirm = ref(false);

const editingKey = ref(null);
const editValue = ref('');

const changePreset = () => {
    router.get(route('admin.workspace.index'), { preset_id: selectedPresetId.value });
};

const refreshData = () => {
    router.get(route('admin.workspace.index'), { preset_id: selectedPresetId.value });
};

const startEdit = (entry) => {
    editingKey.value = entry.key;
    editValue.value = entry.value;
};

const cancelEdit = () => {
    editingKey.value = null;
    editValue.value = '';
};

const saveEdit = (key) => {
    if (!editValue.value.trim()) return;
    router.put(route('admin.workspace.update', key), {
        preset_id: selectedPresetId.value,
        value: editValue.value,
    }, {
        onSuccess: () => {
            cancelEdit();
        },
    });
};

const deleteEntry = (key) => {
    if (!confirm(`Delete workspace key "${key}"?`)) return;
    router.delete(route('admin.workspace.destroy', key), {
        data: { preset_id: selectedPresetId.value },
    });
};

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>