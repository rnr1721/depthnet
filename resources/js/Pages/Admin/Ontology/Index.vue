<template>
    <PageTitle :title="t('ontology_manager')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('ontology_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

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

                <!-- Flash -->
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
                                    clip-rule="evenodd" />
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
                                    clip-rule="evenodd" />
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.error }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Header -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                    t('select_preset') }}</label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="p in presets" :key="p.id" :value="p.id">
                                    {{ p.name }} {{ p.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>

                        <div v-if="stats.total_nodes !== undefined"
                            class="flex flex-col lg:flex-row space-y-2 lg:space-y-0 lg:space-x-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    stats.total_nodes }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('ont_nodes')
                                }}</div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-violet-300' : 'text-violet-600']">{{
                                    stats.total_edges }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('ont_edges')
                                }}</div>
                            </div>
                            <div v-for="(count, cls) in stats.by_class" :key="cls" class="text-center">
                                <div :class="['text-lg font-bold', classBadgeColor(cls)]">{{ count }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ cls }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0 gap-4">
                        <div class="flex-1 max-w-md relative">
                            <input v-model="localSearch" @keyup.enter="applyFilters"
                                :placeholder="t('ont_search_placeholder')"
                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all pl-10 pr-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500']" />
                            <svg class="absolute left-3 top-3.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>

                        <div class="flex gap-3 flex-wrap">
                            <select v-model="localClass" @change="applyFilters"
                                :class="['rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option value="">{{ t('ont_all_classes') }}</option>
                                <option v-for="cls in availableClasses" :key="cls" :value="cls">{{ cls }}</option>
                            </select>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button @click="clearOntology"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-700 hover:bg-red-800 text-white' : 'bg-red-600 hover:bg-red-700 text-white']">
                                {{ t('ont_clear_all') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty -->
                <div v-if="!nodes.length"
                    :class="['text-center py-16 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-violet-400 to-indigo-500 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{ t('ont_empty')
                    }}</h3>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('ont_empty_desc') }}</p>
                </div>

                <!-- Nodes -->
                <div v-else class="space-y-3">
                    <div v-for="node in nodes" :key="node.id"
                        :class="['backdrop-blur-sm border shadow rounded-2xl overflow-hidden transition-all', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700 hover:border-gray-600' : 'bg-white bg-opacity-90 border-gray-200 hover:border-gray-300']">

                        <div class="flex items-center gap-3 px-4 py-3">
                            <span
                                :class="['text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0', classBadge(node.class)]">{{
                                    node.class }}</span>

                            <div class="flex-1 min-w-0">
                                <span :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">{{
                                    node.canonical_name }}</span>
                                <span v-if="node.aliases?.length"
                                    :class="['ml-2 text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">({{
                                        node.aliases.join(', ') }})</span>
                            </div>

                            <span
                                :class="['text-xs px-2 py-0.5 rounded-full flex-shrink-0', isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600']">w:{{
                                    node.weight.toFixed(1) }}</span>
                            <span :class="['text-xs flex-shrink-0', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                node.edges.length }} {{ t('ont_edges') }}</span>

                            <div class="flex items-center gap-1 flex-shrink-0">
                                <button @click="toggleExpand(node.id)"
                                    :class="['p-1.5 rounded-lg transition-all', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-500']">
                                    <svg class="w-4 h-4 transition-transform"
                                        :class="expanded === node.id ? 'rotate-180' : ''" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                <button @click="openEditNode(node)"
                                    :class="['p-1.5 rounded-lg transition-all', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-blue-300' : 'hover:bg-gray-100 text-gray-400 hover:text-blue-500']">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button @click="deleteNode(node)"
                                    :class="['p-1.5 rounded-lg transition-all', isDark ? 'hover:bg-red-900 text-gray-400 hover:text-red-300' : 'hover:bg-red-50 text-gray-400 hover:text-red-500']">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
                            enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-screen"
                            leave-active-class="transition-all duration-150 ease-in overflow-hidden"
                            leave-from-class="opacity-100 max-h-screen" leave-to-class="opacity-0 max-h-0">
                            <div v-if="expanded === node.id"
                                :class="['px-4 pb-4 border-t pt-4 space-y-4', isDark ? 'border-gray-700' : 'border-gray-200']">

                                <div v-if="node.properties.length">
                                    <p
                                        :class="['text-xs font-semibold uppercase tracking-wide mb-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ t('ont_properties') }}</p>
                                    <div class="space-y-1">
                                        <div v-for="prop in node.properties" :key="prop.id"
                                            class="flex items-center gap-2">
                                            <span
                                                :class="['text-xs font-mono', isDark ? 'text-violet-300' : 'text-violet-600']">.{{
                                                    prop.key }}</span>
                                            <span
                                                :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">=</span>
                                            <span
                                                :class="['text-xs font-mono flex-1', isDark ? 'text-gray-200' : 'text-gray-700']">{{
                                                    prop.value_scalar ?? `node:${prop.value_node_id}` }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="node.edges.length">
                                    <p
                                        :class="['text-xs font-semibold uppercase tracking-wide mb-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ t('ont_edges') }}</p>
                                    <div class="space-y-1">
                                        <div v-for="edge in node.edges" :key="edge.id"
                                            class="flex items-center gap-2 group">
                                            <span
                                                :class="['text-xs font-mono truncate flex-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                                                <span :class="isDark ? 'text-blue-300' : 'text-blue-600'">{{
                                                    edge.source_name }}</span>
                                                <span :class="isDark ? 'text-gray-400' : 'text-gray-400'"> —[{{
                                                    edge.relation_type }}]→ </span>
                                                <span :class="isDark ? 'text-green-300' : 'text-green-600'">{{
                                                    edge.target_name }}</span>
                                                <span
                                                    :class="['ml-1 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">w:{{
                                                        edge.weight.toFixed(1) }}</span>
                                            </span>
                                            <button @click="deleteEdge(edge)"
                                                :class="['opacity-0 group-hover:opacity-100 p-1 rounded transition-all flex-shrink-0', isDark ? 'hover:bg-red-900 text-gray-500 hover:text-red-300' : 'hover:bg-red-50 text-gray-400 hover:text-red-500']">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <p v-if="!node.properties.length && !node.edges.length"
                                    :class="['text-xs italic', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('ont_no_data') }}</p>
                            </div>
                        </Transition>
                    </div>
                </div>

                <!-- Pagination -->
                <PaginationComponent v-if="pagination" :pagination="pagination" :isDark="isDark" class="mt-6" />

            </div>
        </main>

        <!-- Edit Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0"
                enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="editingNode" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black bg-opacity-60 backdrop-blur-sm" @click="editingNode = null">
                    </div>
                    <div
                        :class="['relative w-full max-w-md rounded-2xl shadow-2xl p-6 space-y-4', isDark ? 'bg-gray-800 border border-gray-700' : 'bg-white border border-gray-200']">
                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('ont_edit') }}: {{ editingNode.canonical_name }}
                        </h3>

                        <div>
                            <label
                                :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                    t('ont_class') }}</label>
                            <input v-model="editForm.class"
                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300']"
                                placeholder="Person, Place, Concept..." />
                        </div>

                        <div>
                            <label
                                :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('ont_aliases') }} <span class="font-normal opacity-60">({{ t('ont_comma_separated')
                                }})</span>
                            </label>
                            <input v-model="editForm.aliasesRaw"
                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300']"
                                placeholder="Женя, Евгений, Eugeny Gazzaev" />
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button @click="editingNode = null"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                {{ t('ont_cancel') }}
                            </button>
                            <button @click="submitEditNode"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium', isDark ? 'bg-violet-700 hover:bg-violet-600 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                {{ t('ont_save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import PaginationComponent from '@/Components/Pagination.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    nodes: Array,
    pagination: Object,
    stats: Object,
    search: String,
    filterClass: String,
    perPage: Number,
    availableClasses: Array,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const localSearch = ref(props.search || '');
const localClass = ref(props.filterClass || '');
const expanded = ref(null);
const editingNode = ref(null);
const editForm = ref({ class: '', aliasesRaw: '' });

// ── Navigation ───────────────────────────────────────────────────────────────

const changePreset = () => {
    router.get(route('admin.ontology.index'), { preset_id: selectedPresetId.value });
};

const applyFilters = () => {
    router.get(route('admin.ontology.index'), {
        preset_id: selectedPresetId.value,
        search: localSearch.value,
        class: localClass.value,
    });
};

// ── Expand ────────────────────────────────────────────────────────────────────

const toggleExpand = (id) => {
    expanded.value = expanded.value === id ? null : id;
};

// ── Edit ──────────────────────────────────────────────────────────────────────

const openEditNode = (node) => {
    editingNode.value = node;
    editForm.value = {
        class: node.class,
        aliasesRaw: (node.aliases || []).join(', '),
    };
};

const submitEditNode = () => {
    const aliases = editForm.value.aliasesRaw
        .split(',')
        .map(s => s.trim())
        .filter(Boolean);

    router.put(route('admin.ontology.node.update', editingNode.value.id), {
        preset_id: selectedPresetId.value,
        class: editForm.value.class,
        aliases,
    }, {
        onSuccess: () => { editingNode.value = null; },
    });
};

// ── Delete ────────────────────────────────────────────────────────────────────

const deleteNode = (node) => {
    if (confirm(t('ont_confirm_delete_node', { name: node.canonical_name }))) {
        router.delete(route('admin.ontology.node.destroy', node.id), {
            data: { preset_id: selectedPresetId.value },
        });
    }
};

const deleteEdge = (edge) => {
    if (confirm(t('ont_confirm_delete_edge', { source: edge.source_name, rel: edge.relation_type, target: edge.target_name }))) {
        router.delete(route('admin.ontology.edge.destroy', edge.id), {
            data: { preset_id: selectedPresetId.value },
        });
    }
};

const clearOntology = () => {
    if (confirm(t('ont_confirm_clear'))) {
        router.post(route('admin.ontology.clear'), { preset_id: selectedPresetId.value });
    }
};

// ── Class colors ──────────────────────────────────────────────────────────────

const classBadge = (cls) => {
    const dark = {
        Person: 'bg-blue-900 text-blue-200',
        Place: 'bg-green-900 text-green-200',
        Concept: 'bg-violet-900 text-violet-200',
        Emotion: 'bg-pink-900 text-pink-200',
        Event: 'bg-amber-900 text-amber-200',
        Principle: 'bg-indigo-900 text-indigo-200',
        Value: 'bg-teal-900 text-teal-200',
        Goal: 'bg-orange-900 text-orange-200',
        Technology: 'bg-cyan-900 text-cyan-200',
        Task: 'bg-rose-900 text-rose-200',
        Decision: 'bg-yellow-900 text-yellow-200',
        Reflection: 'bg-purple-900 text-purple-200',
    };
    const light = {
        Person: 'bg-blue-100 text-blue-700',
        Place: 'bg-green-100 text-green-700',
        Concept: 'bg-violet-100 text-violet-700',
        Emotion: 'bg-pink-100 text-pink-700',
        Event: 'bg-amber-100 text-amber-700',
        Principle: 'bg-indigo-100 text-indigo-700',
        Value: 'bg-teal-100 text-teal-700',
        Goal: 'bg-orange-100 text-orange-700',
        Technology: 'bg-cyan-100 text-cyan-700',
        Task: 'bg-rose-100 text-rose-700',
        Decision: 'bg-yellow-100 text-yellow-700',
        Reflection: 'bg-purple-100 text-purple-700',
    };
    return (isDark.value ? dark[cls] : light[cls]) || (isDark.value ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600');
};

const classBadgeColor = (cls) => {
    const map = {
        Person: isDark.value ? 'text-blue-300' : 'text-blue-600',
        Place: isDark.value ? 'text-green-300' : 'text-green-600',
        Concept: isDark.value ? 'text-violet-300' : 'text-violet-600',
        Emotion: isDark.value ? 'text-pink-300' : 'text-pink-600',
        Event: isDark.value ? 'text-amber-300' : 'text-amber-600',
        Technology: isDark.value ? 'text-cyan-300' : 'text-cyan-600',
        Task: isDark.value ? 'text-rose-300' : 'text-rose-600',
        Decision: isDark.value ? 'text-yellow-300' : 'text-yellow-600',
    };
    return map[cls] || (isDark.value ? 'text-gray-300' : 'text-gray-600');
};

// ── Theme ─────────────────────────────────────────────────────────────────────

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>