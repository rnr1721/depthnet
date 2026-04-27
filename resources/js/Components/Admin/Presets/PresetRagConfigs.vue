<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">RAG</h4>
            <button type="button" @click="showAddForm = !showAddForm" :class="[
                'flex items-center gap-2 text-sm px-3 py-1.5 rounded-xl transition-all font-medium',
                showAddForm
                    ? (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-200 text-gray-700')
                    : (isDark ? 'bg-emerald-700 hover:bg-emerald-600 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white')
            ]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        :d="showAddForm ? 'M6 18L18 6M6 6l12 12' : 'M12 4v16m8-8H4'" />
                </svg>
                {{ showAddForm ? t('rag_cancel') : t('rag_add') }}
            </button>
        </div>

        <p :class="['text-xs mb-4', isDark ? 'text-gray-400' : 'text-gray-500']">
            {{ t('rag_desc') }}
        </p>

        <!-- Add form -->
        <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
            enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-32"
            leave-active-class="transition-all duration-150 ease-in overflow-hidden"
            leave-from-class="opacity-100 max-h-32" leave-to-class="opacity-0 max-h-0">
            <div v-if="showAddForm"
                :class="['rounded-xl p-4 mb-4 border', isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200']">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('rag_select_preset') }}
                </label>
                <div class="flex gap-2">
                    <select v-model="newRagPresetId" :class="[selectClass, 'flex-1']">
                        <option value="">{{ t('rag_choose_preset') }}</option>
                        <option v-for="p in availableRagPresets" :key="p.id" :value="p.id">
                            {{ p.name }} ({{ p.engine_name }})
                        </option>
                    </select>
                    <button type="button" @click="addConfig" :disabled="!newRagPresetId || adding" :class="['px-4 py-2 rounded-xl text-sm font-medium transition-all',
                        (!newRagPresetId || adding)
                            ? 'opacity-50 cursor-not-allowed bg-gray-400 text-white'
                            : (isDark ? 'bg-emerald-700 hover:bg-emerald-600 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white')
                    ]">
                        {{ adding ? '...' : t('rag_add_btn') }}
                    </button>
                </div>
                <p v-if="addError" class="text-red-500 text-xs mt-2">{{ addError }}</p>
            </div>
        </Transition>

        <!-- Empty state -->
        <div v-if="configs.length === 0 && !loading"
            :class="['text-center py-8 rounded-xl border-2 border-dashed', isDark ? 'border-gray-600 text-gray-500' : 'border-gray-300 text-gray-400']">
            <svg class="w-8 h-8 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
            </svg>
            <p class="text-sm">{{ t('rag_empty') }}</p>
        </div>

        <!-- Config cards (sortable) -->
        <div v-if="configs.length > 0" ref="sortableList" class="space-y-3">
            <div v-for="config in configs" :key="config.id" :data-id="config.id"
                :class="['rounded-xl border transition-all', isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200']">

                <!-- Card header -->
                <div class="flex items-center gap-3 px-4 py-3">
                    <!-- Drag handle -->
                    <div
                        class="drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-300 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                        </svg>
                    </div>

                    <!-- Primary badge -->
                    <span v-if="config.is_primary" :class="[
                        'text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0',
                        isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-700'
                    ]">primary</span>

                    <!-- Preset name -->
                    <div class="flex-1 min-w-0">
                        <span :class="['text-sm font-medium truncate block', isDark ? 'text-white' : 'text-gray-900']">
                            {{ config.rag_preset?.name ?? '—' }}
                        </span>
                        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ config.rag_mode }} · {{ config.rag_engine }} · {{ formatSources(config.sources) }}
                        </span>
                    </div>

                    <!-- Inactive warning -->
                    <span v-if="config.rag_preset && !config.rag_preset.is_active"
                        class="text-xs text-amber-500 flex-shrink-0">inactive</span>

                    <!-- Expand / delete -->
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <button type="button" @click="toggleExpand(config.id)"
                            :class="['p-1.5 rounded-lg transition-all', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-500']">
                            <svg class="w-4 h-4 transition-transform"
                                :class="expanded === config.id ? 'rotate-180' : ''" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <button type="button" @click="removeConfig(config)"
                            :class="['p-1.5 rounded-lg transition-all', isDark ? 'hover:bg-red-900 text-gray-400 hover:text-red-300' : 'hover:bg-red-50 text-gray-400 hover:text-red-500']">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Expanded settings -->
                <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
                    enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-screen"
                    leave-active-class="transition-all duration-150 ease-in overflow-hidden"
                    leave-from-class="opacity-100 max-h-screen" leave-to-class="opacity-0 max-h-0">
                    <div v-if="expanded === config.id"
                        :class="['px-4 pb-4 border-t pt-4 space-y-4', isDark ? 'border-gray-700' : 'border-gray-200']">

                        <!-- Sources -->
                        <div>
                            <label
                                :class="['block text-xs font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('rag_sources') }}
                            </label>
                            <div class="flex flex-wrap gap-3">
                                <label v-for="src in allSources" :key="src.value"
                                    :class="['flex items-center gap-2 cursor-pointer text-sm', isDark ? 'text-white' : 'text-gray-900']">
                                    <input type="checkbox" class="w-4 h-4 rounded text-emerald-600"
                                        :checked="(config.sources ?? []).includes(src.value)"
                                        @change="toggleSource(config, src.value)" />
                                    {{ src.label }}
                                </label>
                            </div>
                        </div>

                        <!-- Mode + Engine -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="labelClass">{{ t('rag_mode') }}</label>
                                <select :value="config.rag_mode"
                                    @change="updateField(config, 'rag_mode', $event.target.value)" :class="selectClass">
                                    <option value="flat">{{ t('rag_mode_flat') }}</option>
                                    <option value="associative">{{ t('rag_mode_assoc') }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('rag_engine') }}</label>
                                <select :value="config.rag_engine"
                                    @change="updateField(config, 'rag_engine', $event.target.value)"
                                    :class="selectClass">
                                    <option value="tfidf">TF-IDF</option>
                                    <option value="embedding">Embedding</option>
                                </select>
                            </div>
                        </div>

                        <!-- Limits -->
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <div>
                                <label :class="labelClass">{{ t('rag_context_limit') }}</label>
                                <input type="number" min="1" max="50" :class="inputClass"
                                    :value="config.rag_context_limit"
                                    @change="updateField(config, 'rag_context_limit', parseInt($event.target.value))" />
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('rag_results') }}</label>
                                <input type="number" min="1" max="50" :class="inputClass" :value="config.rag_results"
                                    @change="updateField(config, 'rag_results', parseInt($event.target.value))" />
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('rag_content_limit') }}</label>
                                <input type="number" min="50" max="5000" step="50" :class="inputClass"
                                    :value="config.rag_content_limit"
                                    @change="updateField(config, 'rag_content_limit', parseInt($event.target.value))" />
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('rag_journal_limit') }}</label>
                                <input type="number" min="0" max="50" :class="inputClass"
                                    :value="config.rag_journal_limit"
                                    @change="updateField(config, 'rag_journal_limit', parseInt($event.target.value))" />
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('rag_skills_limit') }}</label>
                                <input type="number" min="0" max="50" :class="inputClass"
                                    :value="config.rag_skills_limit"
                                    @change="updateField(config, 'rag_skills_limit', parseInt($event.target.value))" />
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('rag_journal_window') }}</label>
                                <input type="number" min="0" max="10" :class="inputClass"
                                    :value="config.rag_journal_context_window"
                                    @change="updateField(config, 'rag_journal_context_window', parseInt($event.target.value))" />
                            </div>
                        </div>

                        <!-- Relative dates -->
                        <label
                            :class="['flex items-center gap-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                            <input type="checkbox" class="w-4 h-4 rounded text-emerald-600"
                                :checked="config.rag_relative_dates"
                                @change="updateField(config, 'rag_relative_dates', $event.target.checked)" />
                            <span class="text-sm">{{ t('rag_relative_dates') }}</span>
                        </label>

                    </div>
                </Transition>
            </div>
        </div>

        <!-- Hint: first is primary -->
        <p v-if="configs.length > 1" :class="['text-xs mt-3', isDark ? 'text-gray-500' : 'text-gray-400']">
            {{ t('rag_primary_hint') }}
        </p>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import Sortable from 'sortablejs';

const { t } = useI18n();

const props = defineProps({
    preset: { type: Object, default: null },
    isDark: { type: Boolean, default: false },
    availablePresets: { type: Array, default: () => [] },
});

const emit = defineEmits(['success', 'error']);

// ── State ──────────────────────────────────────────────────────────────────
const configs = ref([]);
const loading = ref(false);
const showAddForm = ref(false);
const newRagPresetId = ref('');
const adding = ref(false);
const addError = ref('');
const expanded = ref(null); // config.id of open card
let sortable = null;
const sortableList = ref(null);

// ── Computed ───────────────────────────────────────────────────────────────

const availableRagPresets = computed(() =>
    props.availablePresets.filter(p => p.id !== props.preset?.id)
);

const allSources = [
    { value: 'vector_memory', label: 'Vector Memory' },
    { value: 'journal', label: 'Journal' },
    { value: 'skills', label: 'Skills' },
    { value: 'persons', label: 'Persons' },
];

const baseUrl = computed(() => `/admin/presets/${props.preset?.id}/rag-configs`);

// ── Styles ─────────────────────────────────────────────────────────────────
const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all px-3 py-2 text-sm',
    props.isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300',
]);

const selectClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all px-3 py-2 text-sm',
    props.isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-white text-gray-900 ring-gray-300',
]);

const labelClass = computed(() => [
    'block text-xs font-medium mb-1',
    props.isDark ? 'text-gray-300' : 'text-gray-700',
]);

// ── API ────────────────────────────────────────────────────────────────────

async function loadConfigs() {
    if (!props.preset?.id) return;
    loading.value = true;
    try {
        const res = await axios.get(baseUrl.value);
        configs.value = res.data;
        await nextTick();
        initSortable();
    } catch {
        emit('error', 'Failed to load RAG configs');
    } finally {
        loading.value = false;
    }
}

async function addConfig() {
    if (!newRagPresetId.value) return;
    adding.value = true;
    addError.value = '';
    try {
        const res = await axios.post(baseUrl.value, {
            rag_preset_id: newRagPresetId.value,
            sources: ['vector_memory', 'journal', 'skills'],
        });
        configs.value.push(res.data);
        newRagPresetId.value = '';
        showAddForm.value = false;
        emit('success', 'RAG config added');
        await nextTick();
        initSortable();
    } catch (e) {
        addError.value = e.response?.data?.message ?? 'Failed to add config';
    } finally {
        adding.value = false;
    }
}

async function updateField(config, field, value) {
    try {
        const res = await axios.put(`${baseUrl.value}/${config.id}`, { [field]: value });
        Object.assign(config, res.data);
    } catch {
        emit('error', 'Failed to update RAG config');
    }
}

async function toggleSource(config, source) {
    const sources = [...(config.sources ?? [])];
    const idx = sources.indexOf(source);
    if (idx === -1) sources.push(source);
    else sources.splice(idx, 1);
    await updateField(config, 'sources', sources);
}

async function removeConfig(config) {
    try {
        await axios.delete(`${baseUrl.value}/${config.id}`);
        configs.value = configs.value.filter(c => c.id !== config.id);
        // If we deleted the expanded one, close
        if (expanded.value === config.id) expanded.value = null;
        // Re-sync is_primary after server promotes next
        if (config.is_primary && configs.value.length > 0) {
            configs.value[0].is_primary = true;
        }
        emit('success', 'RAG config removed');
    } catch {
        emit('error', 'Failed to remove RAG config');
    }
}

async function reorder(ids) {
    try {
        await axios.post(`${baseUrl.value}/reorder`, { ids });
        // Update is_primary locally — first in list is primary
        configs.value.forEach((c, i) => { c.is_primary = i === 0; });
    } catch {
        emit('error', 'Failed to reorder RAG configs');
    }
}

// ── Sortable ───────────────────────────────────────────────────────────────

function initSortable() {
    if (sortable) {
        sortable.destroy();
        sortable = null;
    }
    if (!sortableList.value || configs.value.length < 2) return;

    sortable = Sortable.create(sortableList.value, {
        handle: '.drag-handle',
        animation: 150,
        onEnd() {
            const ids = [...sortableList.value.querySelectorAll('[data-id]')]
                .map(el => parseInt(el.dataset.id));

            // Reorder local array to match DOM
            const byId = Object.fromEntries(configs.value.map(c => [c.id, c]));
            configs.value = ids.map(id => byId[id]).filter(Boolean);

            reorder(ids);
        },
    });
}

// ── Helpers ────────────────────────────────────────────────────────────────

function toggleExpand(id) {
    expanded.value = expanded.value === id ? null : id;
}

function formatSources(sources) {
    if (!sources?.length) return 'no sources';
    return sources.join(', ');
}

// ── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(loadConfigs);
onBeforeUnmount(() => { if (sortable) sortable.destroy(); });
</script>