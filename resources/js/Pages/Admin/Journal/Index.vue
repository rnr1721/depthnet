<template>
    <PageTitle :title="t('journal_manager')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('journal_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-amber-500' : 'bg-amber-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-orange-500' : 'bg-orange-300']">
                </div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Flash Messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success"
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
                    <div v-if="$page.props.flash.error"
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

                <!-- Header Section -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <!-- Preset Selector -->
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                    t('select_preset') }}</label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>

                        <!-- Stats -->
                        <div v-if="stats.total !== undefined"
                            class="flex flex-col lg:flex-row space-y-2 lg:space-y-0 lg:space-x-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    stats.total || 0 }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('journal_entries') }}</div>
                            </div>
                            <div v-for="(count, type) in stats.by_type" :key="type" class="text-center">
                                <div :class="['text-2xl font-bold', typeColor(type)]">{{ count }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ type }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0 gap-4">
                        <!-- Search -->
                        <div class="flex-1 max-w-md">
                            <div class="relative">
                                <input v-model="localSearch" @keyup.enter="performSearch"
                                    :placeholder="t('journal_search_placeholder')"
                                    :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 transition-all pl-10 pr-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500']" />
                                <svg class="absolute left-3 top-3.5 w-4 h-4 text-gray-400" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="flex gap-3 flex-wrap">
                            <select v-model="localType" @change="applyFilters"
                                :class="['rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option value="">{{ t('journal_all_types') }}</option>
                                <option v-for="type in types" :key="type" :value="type">{{ type }}</option>
                            </select>
                            <select v-model="localOutcome" @change="applyFilters"
                                :class="['rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option value="">{{ t('journal_all_outcomes') }}</option>
                                <option v-for="outcome in outcomes" :key="outcome" :value="outcome">{{ outcome }}
                                </option>
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-3">
                            <button @click="showAddModal = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-amber-500', isDark ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white']">
                                {{ t('journal_add_entry') }}
                            </button>
                            <button @click="clearJournal"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-700 hover:bg-red-800 text-white' : 'bg-red-600 hover:bg-red-700 text-white']">
                                {{ t('journal_clear') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search results banner -->
                <div v-if="searchQuery && searchResults.length"
                    :class="['mb-4 px-4 py-3 rounded-xl text-sm flex items-center justify-between', isDark ? 'bg-amber-900 bg-opacity-40 text-amber-200' : 'bg-amber-50 text-amber-800']">
                    <span>{{ t('journal_search_results', { query: searchQuery, count: searchResults.length }) }}</span>
                    <button @click="clearSearch" class="underline hover:no-underline">{{ t('clear') }}</button>
                </div>

                <!-- Entries List -->
                <div v-if="displayEntries.length" class="space-y-3">
                    <div v-for="entry in displayEntries" :key="entry.id"
                        :class="['backdrop-blur-sm border shadow rounded-2xl overflow-hidden transition-all', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700 hover:border-gray-600' : 'bg-white bg-opacity-90 border-gray-200 hover:border-gray-300']">
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-4">
                                <!-- Left: type badge + summary -->
                                <div class="flex items-start gap-3 flex-1 min-w-0">
                                    <span
                                        :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium flex-shrink-0 mt-0.5', typeBadge(entry.type)]">
                                        {{ entry.type }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <p
                                            :class="['text-sm font-medium break-words', isDark ? 'text-white' : 'text-gray-900']">
                                            {{ entry.summary }}</p>
                                        <p v-if="entry.details"
                                            :class="['mt-1 text-xs break-words whitespace-pre-wrap', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            {{ entry.details }}</p>
                                    </div>
                                </div>

                                <!-- Right: outcome + date + delete -->
                                <div class="flex items-center gap-3 flex-shrink-0">
                                    <span v-if="entry.outcome"
                                        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', outcomeBadge(entry.outcome)]">
                                        {{ entry.outcome }}
                                    </span>
                                    <span
                                        :class="['text-xs whitespace-nowrap', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ formatDate(entry.recorded_at) }}
                                    </span>
                                    <button @click="deleteEntry(entry.id)"
                                        :class="['p-1.5 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-red-400 hover:bg-gray-700' : 'text-gray-400 hover:text-red-500 hover:bg-gray-100']"
                                        :title="t('delete')">
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
                <div v-else
                    :class="['text-center py-16 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('journal_empty') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                        t('journal_empty_description') }}</p>
                    <button @click="showAddModal = true"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all', isDark ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white']">
                        {{ t('journal_add_first') }}
                    </button>
                </div>

                <!-- Pagination -->
                <PaginationComponent v-if="pagination && !searchResults.length" :pagination="pagination"
                    :isDark="isDark" class="mt-6" />
            </div>
        </main>

        <!-- Add Entry Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0"
                enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black bg-opacity-60 backdrop-blur-sm" @click="showAddModal = false">
                    </div>
                    <div
                        :class="['relative w-full max-w-lg rounded-2xl shadow-2xl p-6', isDark ? 'bg-gray-800 border border-gray-700' : 'bg-white border border-gray-200']">
                        <h3 :class="['text-lg font-semibold mb-4', isDark ? 'text-white' : 'text-gray-900']">{{
                            t('journal_add_entry') }}</h3>
                        <p :class="['text-xs mb-3', isDark ? 'text-gray-400' : 'text-gray-500']">
                            Format: <code>type | summary</code> or
                            <code>type | summary | details | outcome:success</code><br>
                            Types: {{ types.join(', ') }}
                        </p>
                        <form @submit.prevent="submitEntry">
                            <textarea v-model="newEntryContent" rows="4" :placeholder="t('journal_entry_placeholder')"
                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 px-4 py-3 text-sm mb-4 resize-none', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500']"></textarea>
                            <div class="flex justify-end gap-3">
                                <button type="button" @click="showAddModal = false"
                                    :class="['px-4 py-2 rounded-xl text-sm font-medium', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                        t('cancel') }}</button>
                                <button type="submit"
                                    :class="['px-4 py-2 rounded-xl text-sm font-medium text-white', isDark ? 'bg-amber-600 hover:bg-amber-700' : 'bg-amber-600 hover:bg-amber-700']">{{
                                        t('save') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router, useForm } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import PaginationComponent from '@/Components/Pagination.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    entries: Array,
    searchResults: Array,
    stats: Object,
    pagination: Object,
    searchQuery: String,
    filterType: String,
    filterOutcome: String,
    perPage: Number,
    types: Array,
    outcomes: Array,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const localSearch = ref(props.searchQuery || '');
const localType = ref(props.filterType || '');
const localOutcome = ref(props.filterOutcome || '');
const showAddModal = ref(false);
const newEntryContent = ref('');

const displayEntries = computed(() =>
    props.searchResults.length ? props.searchResults : props.entries
);

const changePreset = () => {
    router.get(route('admin.journal.index'), { preset_id: selectedPresetId.value });
};

const performSearch = () => {
    if (localSearch.value.trim()) {
        router.post(route('admin.journal.search'), {
            preset_id: selectedPresetId.value,
            query: localSearch.value.trim(),
        });
    }
};

const clearSearch = () => {
    localSearch.value = '';
    router.get(route('admin.journal.index'), { preset_id: selectedPresetId.value });
};

const applyFilters = () => {
    router.get(route('admin.journal.index'), {
        preset_id: selectedPresetId.value,
        type: localType.value,
        outcome: localOutcome.value,
    });
};

const submitEntry = () => {
    if (!newEntryContent.value.trim()) return;
    router.post(route('admin.journal.store'), {
        preset_id: selectedPresetId.value,
        content: newEntryContent.value.trim(),
    }, {
        onSuccess: () => {
            showAddModal.value = false;
            newEntryContent.value = '';
        },
    });
};

const deleteEntry = (id) => {
    if (confirm(t('journal_confirm_delete'))) {
        router.delete(route('admin.journal.destroy', id), {
            data: { preset_id: selectedPresetId.value },
        });
    }
};

const clearJournal = () => {
    if (confirm(t('journal_confirm_clear'))) {
        router.post(route('admin.journal.clear'), { preset_id: selectedPresetId.value });
    }
};

const formatDate = (dateString) => {
    const d = new Date(dateString);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const typeColor = (type) => {
    const map = {
        action: 'text-blue-400',
        reflection: 'text-purple-400',
        decision: 'text-amber-400',
        error: 'text-red-400',
        observation: 'text-green-400',
        interaction: 'text-cyan-400',
    };
    return map[type] || 'text-gray-400';
};

const typeBadge = (type) => {
    const dark = {
        action: 'bg-blue-900 text-blue-200',
        reflection: 'bg-purple-900 text-purple-200',
        decision: 'bg-amber-900 text-amber-200',
        error: 'bg-red-900 text-red-200',
        observation: 'bg-green-900 text-green-200',
        interaction: 'bg-cyan-900 text-cyan-200',
    };
    const light = {
        action: 'bg-blue-100 text-blue-700',
        reflection: 'bg-purple-100 text-purple-700',
        decision: 'bg-amber-100 text-amber-700',
        error: 'bg-red-100 text-red-700',
        observation: 'bg-green-100 text-green-700',
        interaction: 'bg-cyan-100 text-cyan-700',
    };
    return isDark.value ? (dark[type] || 'bg-gray-700 text-gray-300') : (light[type] || 'bg-gray-100 text-gray-600');
};

const outcomeBadge = (outcome) => {
    const map = {
        success: isDark.value ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-700',
        failure: isDark.value ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-700',
        pending: isDark.value ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-700',
    };
    return map[outcome] || '';
};

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>