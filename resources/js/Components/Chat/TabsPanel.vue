<template>
    <div class="flex flex-col h-full">
        <!-- Tabs Header -->
        <div :class="[
            'p-4 border-b flex-shrink-0',
            isDark ? 'border-gray-600 bg-gray-700' : 'border-gray-200 bg-gray-50'
        ]">
            <div class="flex space-x-1">
                <button v-if="isAdmin" @click="activeTab = 'presets'" :class="[
                    'flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all',
                    activeTab === 'presets'
                        ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-600 text-white')
                        : (isDark ? 'text-gray-300 hover:text-white hover:bg-gray-600' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100')
                ]">
                    {{ t('chat_presets') || 'Presets' }}
                </button>
                <button @click="activeTab = 'users'" :class="[
                    'px-3 py-2 rounded-lg text-sm font-medium transition-all',
                    isAdmin ? 'flex-1' : 'w-full',
                    activeTab === 'users'
                        ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-600 text-white')
                        : (isDark ? 'text-gray-300 hover:text-white hover:bg-gray-600' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100')
                ]">
                    {{ t('chat_users') || 'Users' }}
                    <span :class="[
                        'ml-2 text-xs px-1.5 py-0.5 rounded-full',
                        activeTab === 'users'
                            ? 'bg-gray bg-opacity-20'
                            : (isDark ? 'bg-gray-600 text-green-300' : 'bg-gray-200 text-gray-600')
                    ]">
                        {{ users.length }}
                    </span>
                </button>
            </div>

            <!-- Agent filter — shown only on presets tab when agents exist -->
            <div v-if="isAdmin && activeTab === 'presets' && agents.length > 0" class="mt-3">
                <select v-model="selectedAgentFilter" :class="[
                    'w-full rounded-lg border-0 ring-1 ring-inset text-xs px-3 py-1.5 transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500',
                    isDark
                        ? 'bg-gray-600 text-gray-200 ring-gray-500'
                        : 'bg-white text-gray-700 ring-gray-300'
                ]">
                    <option :value="null">{{ t('chat_all_presets') || 'All presets' }}</option>
                    <optgroup :label="t('chat_filter_by_agent') || 'Filter by agent'">
                        <option v-for="agent in agents" :key="agent.id" :value="agent.id">
                            {{ agent.name }}
                        </option>
                    </optgroup>
                </select>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="flex-1 overflow-hidden flex flex-col min-h-0">
            <!-- Presets Tab -->
            <div v-if="activeTab === 'presets' && isAdmin" ref="presetsContainer"
                class="flex-1 overflow-y-auto p-3 space-y-2 min-h-0">

                <!-- Empty state when agent filter yields nothing -->
                <div v-if="filteredPresets.length === 0"
                    :class="['text-center py-6 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
                    {{ t('chat_no_presets_for_agent') || 'No presets for this agent.' }}
                </div>

                <div v-for="preset in filteredPresets" :key="preset.id" :class="preset.is_spawned ? 'pl-3' : ''">
                    <PresetItem :ref="el => setPresetRef(el, preset.id)" :preset="preset"
                        :isActive="selectedPresetId === preset.id" :loopActive="getPresetActive(preset.id)"
                        :isDark="isDark" :isAdmin="isAdmin" @select="$emit('selectPreset', preset.id)"
                        @edit="handleEditPreset"
                        @toggleActive="(presetId, value) => $emit('togglePresetActive', presetId, value)" />
                </div>

            </div>

            <!-- Users Tab -->
            <div v-if="activeTab === 'users'" class="flex-1 overflow-y-auto p-3 space-y-2 min-h-0">
                <button v-for="user in users" :key="user.id" @click="$emit('mentionUser', user.name)" :class="[
                    'w-full text-left p-3 rounded-lg transition-all duration-200 group',
                    'hover:scale-105 transform',
                    isDark
                        ? 'hover:bg-gray-600 active:bg-gray-500'
                        : 'hover:bg-gray-100 active:bg-gray-200'
                ]">
                    <div class="flex items-center space-x-3">
                        <div class="relative">
                            <div :class="[
                                'w-8 h-8 rounded-full flex items-center justify-center font-medium text-sm',
                                user.is_admin
                                    ? 'bg-gradient-to-br from-purple-500 to-indigo-600 text-white'
                                    : 'bg-gradient-to-br from-blue-500 to-cyan-600 text-white'
                            ]">
                                {{ user.name.charAt(0).toUpperCase() }}
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2">
                                <span :class="[
                                    'font-medium text-sm truncate',
                                    isDark ? 'text-gray-200' : 'text-gray-900'
                                ]">{{ user.name }}</span>
                                <span v-if="user.is_admin" :class="[
                                    'text-xs px-2 py-0.5 rounded-full font-medium',
                                    'bg-gradient-to-r from-purple-500 to-indigo-600 text-white'
                                ]">
                                    {{ t('chat_admin') }}
                                </span>
                            </div>
                        </div>
                        <div :class="[
                            'opacity-0 group-hover:opacity-100 transition-opacity',
                            'w-5 h-5 rounded-full flex items-center justify-center',
                            isDark ? 'bg-gray-500' : 'bg-gray-300'
                        ]">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <!-- Preset Metadata (only for admins) -->
        <div v-if="isAdmin" class="flex-shrink-0 p-3 border-t" :class="isDark ? 'border-gray-600' : 'border-gray-200'">
            <PresetMetadata :metadata="presetMetadata" :isDark="isDark" />
        </div>

        <!-- Footer (always visible) -->
        <div :class="[
            'p-7 text-center flex-shrink-0 border-t bg-gray-800',
            isDark ? 'text-gray-400 border-gray-600' : 'text-gray-500 border-gray-200'
        ]">
            <button @click="$emit('showAbout')" :class="[
                'transition-colors hover:underline focus:outline-none cursor-pointer',
                isDark ? 'hover:text-gray-300' : 'hover:text-gray-700'
            ]">
                {{ t('project_home') }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import PresetMetadata from './PresetMetadata.vue';
import PresetItem from './PresetItem.vue';

const { t } = useI18n();

const props = defineProps({
    users: { type: Array, required: true },
    availablePresets: { type: Array, default: () => [] },
    selectedPresetId: { type: Number, default: null },
    isDark: { type: Boolean, required: true },
    presetMetadata: { type: Object, default: () => ({}) },
    user: { type: Object, default: null },
    presetActiveMap: { type: Object, default: () => ({}) },
    /**
     * List of agents for filter select.
     * Each item: { id, name, roles: [{ preset_id }] }
     * If empty, filter is not shown.
     */
    agents: { type: Array, default: () => [] },
});

const emit = defineEmits([
    'mentionUser',
    'showAbout',
    'selectPreset',
    'editPreset',
    'togglePresetActive',
    'agentFilterChanged',  // emits agent id (number) or null
]);

const isAdmin = computed(() => props.user && props.user.is_admin);
const activeTab = ref(isAdmin.value ? 'presets' : 'users');
const presetsContainer = ref(null);
const presetRefs = ref(new Map());
const selectedAgentFilter = ref(null);

/**
 * Collect all preset IDs that belong to the selected agent
 * (planner + all role presets + all validator presets).
 */
const agentPresetIds = computed(() => {
    if (!selectedAgentFilter.value) return null;

    const agent = props.agents.find(a => a.id === selectedAgentFilter.value);
    if (!agent) return null;

    const ids = new Set();

    // Planner
    if (agent.planner?.id) ids.add(agent.planner.id);

    // Roles — preset + optional validator
    for (const role of agent.roles ?? []) {
        if (role.preset?.id) ids.add(role.preset.id);
        if (role.validator?.id) ids.add(role.validator.id);
    }

    return ids;
});

/**
 * Filtered preset list — respects agent filter.
 * Falls back to full list when no agent selected.
 */
const filteredPresets = computed(() => {
    const presets = agentPresetIds.value
        ? props.availablePresets.filter(p => agentPresetIds.value.has(p.id))
        : props.availablePresets;

    // Группируем: обычные пресеты + их спавны сразу после них
    const regular = presets.filter(p => !p.is_spawned);
    const spawned = presets.filter(p => p.is_spawned);

    const result = [];
    for (const preset of regular) {
        result.push(preset);
        // Спавны этого родителя — сразу после него
        const children = spawned.filter(s => s.parent_preset_id === preset.id);
        result.push(...children);
    }
    // Спавны у которых родитель не попал в список (edge case)
    const orphans = spawned.filter(s => !regular.find(p => p.id === s.parent_preset_id));
    result.push(...orphans);

    return result;
});


function getPresetActive(presetId) {
    return !!props.presetActiveMap[presetId];
}

function setPresetRef(el, presetId) {
    if (el) {
        presetRefs.value.set(presetId, el);
    } else {
        presetRefs.value.delete(presetId);
    }
}

function scrollToActivePreset() {
    if (!isAdmin.value || !props.selectedPresetId || !presetsContainer.value || activeTab.value !== 'presets') return;

    nextTick(() => {
        const activePresetRef = presetRefs.value.get(props.selectedPresetId);
        if (activePresetRef && activePresetRef.$el) {
            const container = presetsContainer.value;
            const element = activePresetRef.$el;
            const containerRect = container.getBoundingClientRect();
            const elementRect = element.getBoundingClientRect();
            const isVisible = elementRect.top >= containerRect.top && elementRect.bottom <= containerRect.bottom;

            if (!isVisible) {
                const scrollTo = element.offsetTop - (container.clientHeight / 2) + (element.offsetHeight / 2);
                container.scrollTo({ top: Math.max(0, scrollTo), behavior: 'smooth' });
            }
        }
    });
}

function handleEditPreset(presetId) {
    if (isAdmin.value) emit('editPreset', presetId);
}

// Emit agent filter changes to parent so chat page can pass agent_id to sidebar links
watch(selectedAgentFilter, (agentId) => {
    emit('agentFilterChanged', agentId);
});

// Reset filter when switching away from presets tab
watch(activeTab, (newTab) => {
    if (newTab !== 'presets') selectedAgentFilter.value = null;
    if (isAdmin.value && newTab === 'presets') setTimeout(scrollToActivePreset, 100);
});

watch(() => props.selectedPresetId, () => {
    if (isAdmin.value && activeTab.value === 'presets') scrollToActivePreset();
}, { flush: 'post' });

onMounted(() => {
    if (isAdmin.value) setTimeout(scrollToActivePreset, 200);
});
</script>

<style scoped>
.overflow-y-auto::-webkit-scrollbar {
    width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background-color: rgba(156, 163, 175, .5);
    border-radius: 2px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background-color: rgba(156, 163, 175, .8);
}
</style>