<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{ t('iv_title') }}</h4>
            <button type="button" @click="showAddForm = !showAddForm" :class="[
                'flex items-center gap-2 text-sm px-3 py-1.5 rounded-xl transition-all font-medium',
                showAddForm
                    ? (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-200 text-gray-700')
                    : (isDark ? 'bg-violet-700 hover:bg-violet-600 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white')
            ]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        :d="showAddForm ? 'M6 18L18 6M6 6l12 12' : 'M12 4v16m8-8H4'" />
                </svg>
                {{ showAddForm ? t('iv_cancel') : t('iv_add') }}
            </button>
        </div>

        <p :class="['text-xs mb-4', isDark ? 'text-gray-400' : 'text-gray-500']">
            {{ t('iv_desc') }}
        </p>

        <!-- Add form -->
        <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
            enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-40"
            leave-active-class="transition-all duration-150 ease-in overflow-hidden"
            leave-from-class="opacity-100 max-h-40" leave-to-class="opacity-0 max-h-0">
            <div v-if="showAddForm"
                :class="['rounded-xl p-4 mb-4 border', isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200']">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('iv_select_preset') }}
                </label>
                <div class="flex gap-2 mb-3">
                    <select v-model="newVoicePresetId" :class="[selectClass, 'flex-1']">
                        <option value="">{{ t('iv_choose_preset') }}</option>
                        <option v-for="p in availableVoicePresets" :key="p.id" :value="p.id">
                            {{ p.name }} ({{ p.engine_name }})
                        </option>
                    </select>
                    <button type="button" @click="addConfig" :disabled="!newVoicePresetId || adding" :class="['px-4 py-2 rounded-xl text-sm font-medium transition-all',
                        (!newVoicePresetId || adding)
                            ? 'opacity-50 cursor-not-allowed bg-gray-400 text-white'
                            : (isDark ? 'bg-violet-700 hover:bg-violet-600 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white')
                    ]">
                        {{ adding ? '...' : t('iv_add_btn') }}
                    </button>
                </div>
                <!-- Optional label field -->
                <div>
                    <label :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                        {{ t('iv_label_hint') }}
                    </label>
                    <input v-model="newLabel" type="text" :placeholder="t('iv_label_placeholder')"
                        :class="inputClass" />
                </div>
                <p v-if="addError" class="text-red-500 text-xs mt-2">{{ addError }}</p>
            </div>
        </Transition>

        <!-- Empty state -->
        <div v-if="configs.length === 0 && !loading"
            :class="['text-center py-8 rounded-xl border-2 border-dashed', isDark ? 'border-gray-600 text-gray-500' : 'border-gray-300 text-gray-400']">
            <svg class="w-8 h-8 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z" />
            </svg>
            <p class="text-sm">{{ t('iv_empty') }}</p>
        </div>

        <!-- Config cards (sortable) -->
        <div v-if="configs.length > 0" ref="sortableList" class="space-y-3">
            <div v-for="config in configs" :key="config.id" :data-id="config.id"
                :class="['rounded-xl border transition-all', isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200']">

                <!-- Card header -->
                <div class="flex items-center gap-3 px-4 py-3">
                    <!-- Drag handle -->
                    <div class="drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-300 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                        </svg>
                    </div>

                    <!-- Enabled toggle -->
                    <button type="button" @click="updateField(config, 'is_enabled', !config.is_enabled)"
                        :class="['flex-shrink-0 w-8 h-4 rounded-full transition-all relative',
                            config.is_enabled
                                ? (isDark ? 'bg-violet-600' : 'bg-violet-500')
                                : (isDark ? 'bg-gray-600' : 'bg-gray-300')
                        ]">
                        <span :class="['absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-all',
                            config.is_enabled ? 'left-4' : 'left-0.5'
                        ]" />
                    </button>

                    <!-- Preset name + label -->
                    <div class="flex-1 min-w-0">
                        <span :class="['text-sm font-medium truncate block', isDark ? 'text-white' : 'text-gray-900']">
                            {{ config.label || (config.voice_preset?.name ?? '—') }}
                        </span>
                        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ config.voice_preset?.name ?? '—' }} · ctx {{ config.context_limit }}
                        </span>
                    </div>

                    <!-- Inactive warning -->
                    <span v-if="config.voice_preset && !config.voice_preset.is_active"
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

                        <!-- Label -->
                        <div>
                            <label :class="labelClass">{{ t('iv_label') }}</label>
                            <input type="text" :class="inputClass" :value="config.label ?? ''"
                                :placeholder="config.voice_preset?.name ?? ''"
                                @change="updateField(config, 'label', $event.target.value || null)" />
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                                {{ t('iv_label_desc') }}
                            </p>
                        </div>

                        <!-- Context limit -->
                        <div>
                            <label :class="labelClass">{{ t('iv_context_limit') }}</label>
                            <input type="number" min="1" max="100" :class="inputClass"
                                :value="config.context_limit"
                                @change="updateField(config, 'context_limit', parseInt($event.target.value))" />
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                                {{ t('iv_context_limit_desc') }}
                            </p>
                        </div>

                    </div>
                </Transition>
            </div>
        </div>

        <!-- Hint: order matters -->
        <p v-if="configs.length > 1" :class="['text-xs mt-3', isDark ? 'text-gray-500' : 'text-gray-400']">
            {{ t('iv_order_hint') }}
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
const newVoicePresetId = ref('');
const newLabel = ref('');
const adding = ref(false);
const addError = ref('');
const expanded = ref(null);
let sortable = null;
const sortableList = ref(null);

// ── Computed ───────────────────────────────────────────────────────────────

const availableVoicePresets = computed(() =>
    props.availablePresets.filter(p => p.id !== props.preset?.id)
);

const baseUrl = computed(() => `/admin/presets/${props.preset?.id}/inner-voice-configs`);

// ── Styles ─────────────────────────────────────────────────────────────────
const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-3 py-2 text-sm',
    props.isDark ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300',
]);

const selectClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-3 py-2 text-sm',
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
        emit('error', 'Failed to load inner voice configs');
    } finally {
        loading.value = false;
    }
}

async function addConfig() {
    if (!newVoicePresetId.value) return;
    adding.value = true;
    addError.value = '';
    try {
        const payload = { voice_preset_id: newVoicePresetId.value };
        if (newLabel.value.trim()) payload.label = newLabel.value.trim();

        const res = await axios.post(baseUrl.value, payload);
        configs.value.push(res.data);
        newVoicePresetId.value = '';
        newLabel.value = '';
        showAddForm.value = false;
        emit('success', 'Inner voice added');
        await nextTick();
        initSortable();
    } catch (e) {
        addError.value = e.response?.data?.message ?? 'Failed to add inner voice';
    } finally {
        adding.value = false;
    }
}

async function updateField(config, field, value) {
    try {
        const res = await axios.put(`${baseUrl.value}/${config.id}`, { [field]: value });
        Object.assign(config, res.data);
    } catch {
        emit('error', 'Failed to update inner voice config');
    }
}

async function removeConfig(config) {
    try {
        await axios.delete(`${baseUrl.value}/${config.id}`);
        configs.value = configs.value.filter(c => c.id !== config.id);
        if (expanded.value === config.id) expanded.value = null;
        emit('success', 'Inner voice removed');
    } catch {
        emit('error', 'Failed to remove inner voice config');
    }
}

async function reorder(ids) {
    try {
        await axios.post(`${baseUrl.value}/reorder`, { ids });
    } catch {
        emit('error', 'Failed to reorder inner voice configs');
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

// ── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(loadConfigs);
onBeforeUnmount(() => { if (sortable) sortable.destroy(); });
</script>