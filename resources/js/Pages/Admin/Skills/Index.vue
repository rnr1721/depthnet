<template>
    <PageTitle :title="t('skills_manager')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('skills_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-emerald-500' : 'bg-emerald-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-teal-500' : 'bg-teal-300']">
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
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                    t('sk_select_preset') }}</label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="text-center">
                            <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                skills.length }}</div>
                            <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('sk_skills') }}
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
                            <div class="relative">
                                <input v-model="searchQuery" @keyup.enter="performSearch"
                                    :placeholder="t('sk_search_placeholder')"
                                    :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all pl-10 pr-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500']" />
                                <svg class="absolute left-3 top-3.5 w-4 h-4 text-gray-400" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button @click="showAddModal = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2', isDark ? 'bg-emerald-600 hover:bg-emerald-700 text-white focus:ring-offset-gray-800' : 'bg-emerald-600 hover:bg-emerald-700 text-white']">
                                {{ t('sk_add_skill') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search Results -->
                <div v-if="searchResults.length > 0"
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        :class="['px-6 py-4 border-b flex items-center justify-between', isDark ? 'border-gray-700' : 'border-gray-200']">
                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                            t('sk_search_results') }} ({{ searchResults.length }})</h3>
                        <button @click="clearSearch"
                            :class="['text-sm px-3 py-1 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-gray-200 hover:bg-gray-700' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-100']">{{
                                t('sk_clear_search') }}</button>
                    </div>
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="result in searchResults" :key="`${result.skill_number}.${result.item_number}`"
                            :class="['p-5 hover:bg-opacity-50 transition-colors', isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-50']">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <span
                                            :class="['font-mono text-xs font-bold px-2 py-0.5 rounded', isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-800']">#{{
                                                result.skill_number }}.{{ result.item_number }}</span>
                                        <span
                                            :class="['text-xs font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                                result.skill_title }}</span>
                                        <span
                                            :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', result.similarity >= 0.7 ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800') : result.similarity >= 0.4 ? (isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800') : (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')]">
                                            {{ result.similarity_percent }}% {{ t('sk_match') }}
                                        </span>
                                    </div>
                                    <p :class="['text-sm leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-700']">
                                        {{ result.content }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Skills List -->
                <div v-if="skills.length > 0" class="space-y-4">
                    <div v-for="skill in skills" :key="skill.number"
                        :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                        <!-- Skill Header -->
                        <div
                            :class="['px-6 py-4 flex items-center justify-between', isDark ? 'border-gray-700' : 'border-gray-200']">
                            <div class="flex items-center space-x-4 flex-1 min-w-0">
                                <span
                                    :class="['font-mono text-sm font-bold px-2.5 py-1 rounded-lg flex-shrink-0', isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-800']">#{{
                                        skill.number }}</span>
                                <div class="min-w-0">
                                    <h3 :class="['font-semibold truncate', isDark ? 'text-white' : 'text-gray-900']">{{
                                        skill.title }}</h3>
                                    <p v-if="skill.description"
                                        :class="['text-xs mt-0.5 truncate', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ skill.description }}</p>
                                </div>
                                <span
                                    :class="['text-xs px-2 py-0.5 rounded flex-shrink-0', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-600']">
                                    {{ skill.items_count }} {{ skill.items_count === 1 ? t('sk_item') : t('sk_items') }}
                                </span>
                            </div>
                            <div class="flex items-center space-x-2 ml-4">
                                <button @click="openSkill(skill)"
                                    :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-600 hover:text-gray-800']"
                                    :title="t('sk_show')">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                        </path>
                                    </svg>
                                </button>
                                <button @click="openAddItem(skill)"
                                    :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500', isDark ? 'hover:bg-gray-700 text-emerald-400 hover:text-emerald-300' : 'hover:bg-gray-100 text-emerald-600 hover:text-emerald-800']"
                                    :title="t('sk_add_item')">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 4v16m8-8H4"></path>
                                    </svg>
                                </button>
                                <button @click="deleteSkill(skill.number)"
                                    :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-600 hover:text-red-800']"
                                    :title="t('sk_delete')">
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
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-emerald-400 to-teal-600 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('sk_no_skills') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                        t('sk_no_skills_description') }}</p>
                    <button @click="showAddModal = true"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-emerald-500', isDark ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white']">
                        {{ t('sk_add_first_skill') }}
                    </button>
                </div>

            </div>
        </main>

        <!-- Modals -->
        <AddSkillModal v-model="showAddModal" :preset="currentPreset" :isDark="isDark" @success="refreshData" />
        <AddItemModal v-model="showAddItemModal" :preset="currentPreset" :skill="activeSkill" :isDark="isDark"
            @success="refreshData" />
        <ShowSkillModal v-model="showSkillModal" :preset="currentPreset" :skill="activeSkill" :isDark="isDark"
            @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddSkillModal from '@/Components/Admin/Skills/AddSkillModal.vue';
import AddItemModal from '@/Components/Admin/Skills/AddItemModal.vue';
import ShowSkillModal from '@/Components/Admin/Skills/ShowSkillModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    skills: Array,
    searchResults: Array,
    searchQuery: String,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const searchQuery = ref(props.searchQuery || '');

const showAddModal = ref(false);
const showAddItemModal = ref(false);
const showSkillModal = ref(false);
const activeSkill = ref(null);

const changePreset = () => {
    router.get(route('admin.skills.index'), { preset_id: selectedPresetId.value });
};

const performSearch = () => {
    if (searchQuery.value.trim()) {
        router.get(route('admin.skills.index'), {
            preset_id: selectedPresetId.value,
            search: searchQuery.value.trim(),
        });
    }
};

const clearSearch = () => {
    searchQuery.value = '';
    router.get(route('admin.skills.index'), { preset_id: selectedPresetId.value });
};

const openSkill = (skill) => {
    activeSkill.value = skill;
    showSkillModal.value = true;
};

const openAddItem = (skill) => {
    activeSkill.value = skill;
    showAddItemModal.value = true;
};

const deleteSkill = (skillNumber) => {
    if (confirm(t('sk_confirm_delete'))) {
        router.delete(route('admin.skills.destroy', skillNumber), {
            data: { preset_id: selectedPresetId.value },
        });
    }
};

const refreshData = () => {
    router.get(route('admin.skills.index'), { preset_id: selectedPresetId.value });
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