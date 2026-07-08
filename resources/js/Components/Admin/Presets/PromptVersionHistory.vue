<template>
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-[60] flex items-center justify-center p-4"
        @click.self="$emit('close')">
        <div :class="[
            'w-full max-w-4xl max-h-[85vh] flex flex-col rounded-2xl shadow-2xl',
            isDark ? 'bg-gray-800' : 'bg-white'
        ]" @click.stop>

            <!-- Header -->
            <div :class="['px-6 py-4 border-b flex items-center justify-between flex-shrink-0',
                isDark ? 'border-gray-700' : 'border-gray-200']">
                <div>
                    <h3 :class="['text-xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_versions_title') }}
                    </h3>
                    <p v-if="promptCode" :class="['text-xs mt-0.5', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ promptCode }}
                    </p>
                </div>
                <button @click="$emit('close')" :class="['p-2 rounded-xl transition-all hover:scale-105',
                    isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Body -->
            <div class="flex-1 overflow-y-auto p-6">

                <!-- Loading -->
                <div v-if="loading" class="flex items-center justify-center py-12">
                    <svg class="w-6 h-6 animate-spin" :class="isDark ? 'text-gray-400' : 'text-gray-500'" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </div>

                <!-- Error -->
                <div v-else-if="error" :class="['p-4 rounded-xl border-l-4 text-sm',
                    isDark ? 'bg-red-900 bg-opacity-40 border-red-400 text-red-200'
                        : 'bg-red-50 border-red-400 text-red-800']">
                    {{ error }}
                </div>

                <!-- Empty -->
                <div v-else-if="versions.length === 0" class="text-center py-12">
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_versions_empty') }}
                    </p>
                </div>

                <!-- Version list -->
                <div v-else class="space-y-3">
                    <div v-for="v in versions" :key="v.id" :class="[
                        'rounded-xl border p-4 transition-all',
                        v.is_current
                            ? (isDark ? 'border-indigo-500 bg-gray-700 bg-opacity-50' : 'border-indigo-400 bg-indigo-50')
                            : (isDark ? 'border-gray-600 bg-gray-800 bg-opacity-40' : 'border-gray-200 bg-white')
                    ]">
                        <!-- Row header -->
                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                            <span :class="['text-sm font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                v{{ v.version }}
                            </span>

                            <span v-if="v.is_current" :class="['text-xs px-2 py-0.5 rounded-full font-medium',
                                isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-100 text-indigo-700']">
                                {{ t('p_versions_current') }}
                            </span>

                            <span
                                :class="['text-xs px-2 py-0.5 rounded-full font-medium', actorBadgeClass(v.edited_by)]">
                                {{ actorLabel(v.edited_by) }}
                            </span>

                            <span :class="['text-xs ml-auto', isDark ? 'text-gray-400' : 'text-gray-500']"
                                :title="absoluteTime(v.created_at)">
                                {{ relativeTime(v.created_at) }}
                            </span>
                        </div>

                        <!-- Summary -->
                        <p v-if="v.edit_summary" :class="['text-sm mb-3', isDark ? 'text-gray-300' : 'text-gray-600']">
                            {{ v.edit_summary }}
                        </p>

                        <!-- Actions -->
                        <div class="flex items-center gap-2">
                            <button type="button" @click="toggleDiff(v)"
                                :class="['text-xs px-3 py-1.5 rounded-lg font-medium transition-colors',
                                    isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700']">
                                {{ expandedId === v.id ? t('p_versions_hide_diff') : t('p_versions_show_diff') }}
                            </button>

                            <button v-if="!v.is_current" type="button" @click="confirmRevert(v)" :disabled="reverting"
                                :class="['text-xs px-3 py-1.5 rounded-lg font-medium transition-colors disabled:opacity-50',
                                    isDark ? 'bg-amber-700 hover:bg-amber-600 text-white' : 'bg-amber-500 hover:bg-amber-600 text-white']">
                                {{ t('p_versions_revert') }}
                            </button>
                        </div>

                        <!-- Diff (expanded) -->
                        <div v-if="expandedId === v.id" class="mt-3">
                            <pre :class="['text-xs rounded-lg p-3 overflow-x-auto whitespace-pre-wrap font-mono leading-relaxed',
                                isDark ? 'bg-gray-900 text-gray-300' : 'bg-gray-50 text-gray-800']"><template v-for="(line, i) in diffLines(v)" :key="i"><span :class="diffLineClass(line)">{{ line.text }}</span>{{ '\n' }}</template>
</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revert confirmation -->
            <div v-if="pendingRevert" :class="['px-6 py-4 border-t flex-shrink-0',
                isDark ? 'border-gray-700 bg-gray-800' : 'border-gray-200 bg-gray-50']">
                <p :class="['text-sm mb-3', isDark ? 'text-amber-300' : 'text-amber-700']">
                    {{ t('p_versions_revert_confirm', { version: pendingRevert.version }) }}
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="pendingRevert = null"
                        :class="['px-4 py-2 rounded-lg text-sm font-medium transition-all',
                            isDark ? 'bg-gray-600 text-gray-200 hover:bg-gray-500' : 'bg-gray-200 text-gray-700 hover:bg-gray-300']">
                        {{ t('p_versions_cancel') }}
                    </button>
                    <button type="button" @click="doRevert" :disabled="reverting"
                        :class="['px-4 py-2 rounded-lg text-sm font-medium transition-all disabled:opacity-50',
                            isDark ? 'bg-amber-600 hover:bg-amber-500 text-white' : 'bg-amber-500 hover:bg-amber-600 text-white']">
                        {{ reverting ? t('p_versions_reverting') : t('p_versions_revert_confirm_btn') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    presetId: { type: [Number, String], required: true },
    promptId: { type: [Number, String], required: true },
    promptCode: { type: String, default: '' },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'reverted', 'error']);

// ── State ─────────────────────────────────────────────────────────────────────

const versions = ref([]);
const loading = ref(true);
const error = ref('');
const expandedId = ref(null);
const pendingRevert = ref(null);
const reverting = ref(false);

// current head content, used to build diffs client-side
const currentContent = ref('');

// ── Load ──────────────────────────────────────────────────────────────────────

async function loadVersions() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(
            `/admin/presets/${props.presetId}/prompts/${props.promptId}/versions`
        );
        versions.value = data?.data?.versions ?? [];
        // The version flagged is_current carries the live head content.
        const cur = versions.value.find(v => v.is_current);
        currentContent.value = cur ? cur.content : (versions.value[0]?.content ?? '');
    } catch (e) {
        error.value = e?.response?.data?.message ?? t('p_versions_load_error');
    } finally {
        loading.value = false;
    }
}

onMounted(loadVersions);

// ── Diff ──────────────────────────────────────────────────────────────────────

function toggleDiff(v) {
    expandedId.value = expandedId.value === v.id ? null : v.id;
}

/**
 * Line-based diff of this version's content against current head content.
 * Mirrors the backend's simple positional diff — good enough for prompts.
 */
function diffLines(v) {
    const oldLines = (v.content ?? '').split('\n');
    const newLines = (currentContent.value ?? '').split('\n');
    const max = Math.max(oldLines.length, newLines.length);
    const out = [];

    for (let i = 0; i < max; i++) {
        const o = i < oldLines.length ? oldLines[i] : null;
        const n = i < newLines.length ? newLines[i] : null;

        if (o === n) {
            if (o) out.push({ type: 'ctx', text: `  ${o}` });
            continue;
        }
        if (o !== null) out.push({ type: 'del', text: `- ${o}` });
        if (n !== null) out.push({ type: 'add', text: `+ ${n}` });
    }

    if (out.length === 0) {
        out.push({ type: 'ctx', text: t('p_versions_identical') });
    }
    return out;
}

function diffLineClass(line) {
    if (line.type === 'add') return props.isDark ? 'text-green-400' : 'text-green-600';
    if (line.type === 'del') return props.isDark ? 'text-red-400' : 'text-red-600';
    return props.isDark ? 'text-gray-500' : 'text-gray-400';
}

// ── Revert ────────────────────────────────────────────────────────────────────

function confirmRevert(v) {
    pendingRevert.value = v;
}

async function doRevert() {
    if (!pendingRevert.value) return;
    reverting.value = true;
    try {
        await axios.post(
            `/admin/presets/${props.presetId}/prompts/${props.promptId}/versions/${pendingRevert.value.version}/revert`
        );
        pendingRevert.value = null;
        emit('reverted');
        await loadVersions();
    } catch (e) {
        emit('error', e?.response?.data?.message ?? t('p_versions_revert_error'));
    } finally {
        reverting.value = false;
    }
}

// ── Actor labels ──────────────────────────────────────────────────────────────

function actorLabel(editedBy) {
    return {
        agent: t('p_versions_actor_agent'),
        human: t('p_versions_actor_human'),
        system: t('p_versions_actor_system'),
    }[editedBy] ?? editedBy;
}

function actorBadgeClass(editedBy) {
    const map = {
        agent: props.isDark ? 'bg-purple-900 text-purple-200' : 'bg-purple-100 text-purple-700',
        human: props.isDark ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-700',
        system: props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-600',
    };
    return map[editedBy] ?? (props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-600');
}

// ── Time formatting ───────────────────────────────────────────────────────────

function relativeTime(iso) {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    const diff = Math.floor((Date.now() - then) / 1000);

    if (diff < 60) return t('p_versions_just_now');
    if (diff < 3600) return t('p_versions_minutes_ago', { n: Math.floor(diff / 60) });
    if (diff < 86400) return t('p_versions_hours_ago', { n: Math.floor(diff / 3600) });
    if (diff < 7 * 86400) return t('p_versions_days_ago', { n: Math.floor(diff / 86400) });

    return new Date(iso).toLocaleDateString();
}

function absoluteTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString();
}
</script>