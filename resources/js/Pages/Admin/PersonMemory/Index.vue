<template>
    <PageTitle :title="t('pm_title')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('pm_title')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-rose-500' : 'bg-rose-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-pink-500' : 'bg-pink-300']">
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
                                t('pm_select_preset') }}</label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-rose-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="flex gap-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    people.length }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('pm_stats_people') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-rose-500">{{ totalFacts }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('pm_stats_facts') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex items-center justify-between">
                        <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">{{ t('pm_description') }}
                        </p>
                        <div class="flex gap-3">
                            <button v-if="people.length > 0" @click="showClearConfirm = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-900 bg-opacity-60 hover:bg-red-800 text-red-300' : 'bg-red-50 hover:bg-red-100 text-red-700 border border-red-200']">
                                {{ t('pm_clear_all') }}
                            </button>
                            <button @click="openAddNew"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2', isDark ? 'bg-rose-600 hover:bg-rose-700 text-white focus:ring-offset-gray-800' : 'bg-rose-600 hover:bg-rose-700 text-white']">
                                {{ t('pm_add_fact') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- People list (accordion) -->
                <div v-if="people.length > 0"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="person in people" :key="person.name">
                            <!-- Person header row -->
                            <div :class="['flex items-center justify-between px-5 py-4 cursor-pointer select-none transition-colors', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']"
                                @click="togglePerson(person.name)">
                                <div class="flex items-center space-x-3">
                                    <!-- Avatar initial -->
                                    <div
                                        class="w-9 h-9 rounded-full bg-gradient-to-br from-rose-400 to-pink-600 flex items-center justify-center flex-shrink-0">
                                        <span class="text-white text-sm font-bold">{{
                                            person.primary.charAt(0).toUpperCase() }}</span>
                                    </div>
                                    <div>
                                        <!-- Primary name -->
                                        <span :class="['font-medium', isDark ? 'text-white' : 'text-gray-900']">{{
                                            person.primary }}</span>
                                        <!-- Aliases badge -->
                                        <span v-if="person.aliases?.length > 0"
                                            :class="['ml-2 text-xs px-1.5 py-0.5 rounded', isDark ? 'bg-violet-900 text-violet-300' : 'bg-violet-100 text-violet-700']">
                                            {{ person.aliases.join(' / ') }}
                                        </span>
                                        <span :class="['ml-2 text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            {{ t('pm_fact_count', { n: person.facts.length }) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <!-- Manage aliases -->
                                    <button @click.stop="openAliases(person)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-violet-900 text-violet-400 hover:text-violet-300' : 'hover:bg-violet-100 text-violet-600 hover:text-violet-800']"
                                        :title="t('pm_aliases')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 10V5a2 2 0 012-2z">
                                            </path>
                                        </svg>
                                    </button>
                                    <!-- Add fact for this person -->
                                    <button @click.stop="openAddFactForPerson(person)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-rose-900 text-rose-400 hover:text-rose-300' : 'hover:bg-rose-100 text-rose-600 hover:text-rose-800']"
                                        :title="t('pm_add_fact')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4v16m8-8H4"></path>
                                        </svg>
                                    </button>
                                    <!-- Forget person -->
                                    <button @click.stop="forgetPerson(person)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-500 hover:text-red-700']"
                                        :title="t('pm_forget_person')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                            </path>
                                        </svg>
                                    </button>
                                    <!-- Chevron -->
                                    <svg :class="['w-4 h-4 transition-transform duration-200', expandedPeople.has(person.name) ? 'rotate-180' : '', isDark ? 'text-gray-400' : 'text-gray-500']"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>

                            <!-- Facts list (collapsible) -->
                            <Transition enter-active-class="transition ease-out duration-200 overflow-hidden"
                                enter-from-class="max-h-0 opacity-0" enter-to-class="max-h-screen opacity-100"
                                leave-active-class="transition ease-in duration-150 overflow-hidden"
                                leave-from-class="max-h-screen opacity-100" leave-to-class="max-h-0 opacity-0">
                                <div v-if="expandedPeople.has(person.name)"
                                    :class="['divide-y', isDark ? 'divide-gray-700 bg-gray-850' : 'divide-gray-100 bg-gray-50']">
                                    <div v-for="fact in person.facts" :key="fact.id"
                                        :class="['group flex items-start justify-between px-5 py-3 transition-colors', isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-100']">
                                        <div class="flex items-start space-x-3 flex-1 min-w-0">
                                            <span
                                                :class="['font-mono text-xs font-bold mt-0.5 flex-shrink-0 w-8 text-right', isDark ? 'text-rose-400' : 'text-rose-600']">
                                                #{{ fact.id }}
                                            </span>
                                            <p
                                                :class="['text-sm leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-700']">
                                                {{ fact.content }}</p>
                                        </div>
                                        <button @click="deleteFact(fact.id, person.name)"
                                            :class="['ml-3 p-1 rounded-lg transition-colors focus:outline-none opacity-0 group-hover:opacity-100 flex-shrink-0', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-500 hover:text-red-700']"
                                            :title="t('pm_delete_fact')">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else
                    :class="['text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-rose-400 to-pink-600 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('pm_no_people') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                        t('pm_no_people_description') }}</p>
                    <button @click="openAddNew"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-rose-500', isDark ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-rose-600 hover:bg-rose-700 text-white']">
                        {{ t('pm_add_first_fact') }}
                    </button>
                </div>

            </div>
        </main>

        <!-- Modals -->
        <AddPersonFactModal v-model="showAddModal" :preset="currentPreset" :person="addModalPerson" :isDark="isDark"
            @success="refreshData" />
        <ManageAliasesModal v-model="showAliasesModal" :preset="currentPreset" :person="aliasesPerson" :isDark="isDark"
            @success="refreshData" />
        <ClearPersonMemoryModal v-model="showClearConfirm" :preset="currentPreset" :isDark="isDark"
            @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddPersonFactModal from '@/Components/Admin/PersonMemory/AddPersonFactModal.vue';
import ManageAliasesModal from '@/Components/Admin/PersonMemory/ManageAliasesModal.vue';
import ClearPersonMemoryModal from '@/Components/Admin/PersonMemory/ClearPersonMemoryModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    people: Array,  // [{ name, primary, aliases, facts: [{ id, content }] }]
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const expandedPeople = ref(new Set());

// Add fact modal
const showAddModal = ref(false);
const addModalPerson = ref(null); // null = new person mode, object = existing person mode

// Aliases modal
const showAliasesModal = ref(false);
const aliasesPerson = ref(null);

const showClearConfirm = ref(false);

const totalFacts = computed(() => props.people.reduce((sum, p) => sum + p.facts.length, 0));

const togglePerson = (name) => {
    if (expandedPeople.value.has(name)) {
        expandedPeople.value.delete(name);
    } else {
        expandedPeople.value.add(name);
    }
    expandedPeople.value = new Set(expandedPeople.value);
};

// Open modal for new person (no preset person)
const openAddNew = () => {
    addModalPerson.value = null;
    showAddModal.value = true;
};

// Open modal for adding fact to existing person
const openAddFactForPerson = (person) => {
    addModalPerson.value = person;
    showAddModal.value = true;
};

// Open aliases modal
const openAliases = (person) => {
    aliasesPerson.value = person;
    showAliasesModal.value = true;
};

const changePreset = () => {
    router.get(route('admin.person-memory.index'), { preset_id: selectedPresetId.value });
};

const refreshData = () => {
    router.get(route('admin.person-memory.index'), { preset_id: selectedPresetId.value }, {
        preserveScroll: true,
    });
};

// Delete a single fact by ID
const deleteFact = (factId, personName) => {
    if (!confirm(t('pm_confirm_delete_fact'))) return;
    router.delete(route('admin.person-memory.delete-fact'), {
        data: {
            preset_id: selectedPresetId.value,
            fact_id: factId,
        },
        onSuccess: () => {
            expandedPeople.value.add(personName);
            expandedPeople.value = new Set(expandedPeople.value);
        },
        preserveScroll: true,
    });
};

// Forget all facts about a person
const forgetPerson = (person) => {
    if (!confirm(t('pm_confirm_forget', { name: person.primary }))) return;
    router.post(route('admin.person-memory.forget'), {
        preset_id: selectedPresetId.value,
        name_or_id: person.name,
    });
};

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });

    if (props.people.length === 1) {
        expandedPeople.value.add(props.people[0].name);
    }
});
</script>