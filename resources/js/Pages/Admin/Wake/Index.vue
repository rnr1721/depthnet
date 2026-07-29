<template>
    <PageTitle :title="t('wake_manager')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('wake_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-sky-500' : 'bg-sky-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-teal-500' : 'bg-teal-300'
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

                <!-- Header Section: preset selector + at-a-glance counts -->
                <div :class="[
                    'mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('wake_select_preset') }}
                            </label>
                            <select v-model="selectedPresetId" @change="changePreset" :class="[
                                'w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-sky-500 transition-all px-4 py-3',
                                isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300'
                            ]">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>

                        <div v-if="currentPreset" class="flex flex-col lg:flex-row space-y-2 lg:space-y-0 lg:space-x-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ activeCount }}
                                </div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('wake_active') }}
                                </div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-sky-400' : 'text-sky-600']">
                                    {{ mineCount }}
                                </div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('wake_by_agent') }}
                                </div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-teal-400' : 'text-teal-600']">
                                    {{ userCount }}
                                </div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('wake_by_user') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Explainer: what a wake is, in the interface's own voice -->
                    <p :class="['mt-4 text-sm leading-relaxed', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('wake_intro') }}
                        <span v-if="pulsesEnabled" :class="isDark ? 'text-sky-300' : 'text-sky-700'">
                            {{ t('wake_pulses_on') }}
                        </span>
                    </p>
                </div>

                <!-- Action Bar -->
                <div :class="[
                    'mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div class="flex items-center justify-between">
                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('wake_schedule_title') }}
                        </h3>
                        <button @click="openCreate" :disabled="!currentPreset" :class="[
                            'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed',
                            isDark ? 'bg-sky-600 hover:bg-sky-700 text-white focus:ring-offset-gray-800' : 'bg-sky-600 hover:bg-sky-700 text-white'
                        ]">
                            {{ t('wake_schedule_new') }}
                        </button>
                    </div>
                </div>

                <!-- Schedule List — the agent's clock on the wall -->
                <div v-if="schedules.length > 0" :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="s in schedules" :key="s.id" :class="[
                            'p-6 transition-colors',
                            isDark ? 'hover:bg-gray-700 hover:bg-opacity-50' : 'hover:bg-gray-50',
                            !s.enabled ? 'opacity-50' : ''
                        ]">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center flex-wrap gap-2 mb-2">
                                        <!-- type badge -->
                                        <span :class="[
                                            'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium',
                                            typeBadgeClass(s.schedule_type)
                                        ]">
                                            <span aria-hidden="true">{{ typeIcon(s.schedule_type) }}</span>
                                            {{ t('wake_type_' + s.schedule_type) }}
                                        </span>

                                        <!-- who scheduled it -->
                                        <span :class="[
                                            'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                            s.created_by_kind === 'user'
                                                ? (isDark ? 'bg-teal-900 bg-opacity-50 text-teal-200' : 'bg-teal-100 text-teal-800')
                                                : (isDark ? 'bg-sky-900 bg-opacity-50 text-sky-200' : 'bg-sky-100 text-sky-800')
                                        ]">
                                            {{ s.created_by_kind === 'user' ? t('wake_who_user') : t('wake_who_agent')
                                            }}
                                        </span>

                                        <!-- the human-readable "when" -->
                                        <span :class="[
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-mono',
                                            isDark ? 'bg-gray-700 text-gray-200' : 'bg-gray-100 text-gray-700'
                                        ]">
                                            {{ describeWhen(s) }}
                                        </span>

                                        <span v-if="!s.enabled" :class="[
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs',
                                            isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-200 text-gray-500'
                                        ]">
                                            {{ t('wake_disabled') }}
                                        </span>
                                    </div>

                                    <p :class="[
                                        'text-sm leading-relaxed whitespace-pre-wrap mb-2',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">{{ s.wake_message }}</p>

                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs"
                                        :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                        <span v-if="s.next_run_at">
                                            {{ t('wake_next') }}: {{ formatDateTime(s.next_run_at) }}
                                        </span>
                                        <span v-if="s.last_fired_at">
                                            {{ t('wake_last') }}: {{ formatDateTime(s.last_fired_at) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 ml-4">
                                    <button @click="openEdit(s)" :class="[
                                        'p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500',
                                        isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-600 hover:text-gray-800'
                                    ]" :title="t('wake_edit')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                    </button>
                                    <button @click="cancelSchedule(s)" :class="[
                                        'p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500',
                                        isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-600 hover:text-red-800'
                                    ]" :title="t('wake_cancel')">
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

                <!-- Empty State — an invitation to act, in the interface's voice -->
                <div v-else :class="[
                    'text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-sky-400 to-teal-600 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('wake_empty_title') }}
                    </h3>
                    <p :class="['text-sm mb-6 max-w-md mx-auto', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('wake_empty_description') }}
                    </p>
                    <button @click="openCreate" :disabled="!currentPreset" :class="[
                        'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50',
                        isDark ? 'bg-sky-600 hover:bg-sky-700 text-white focus:ring-offset-gray-800' : 'bg-sky-600 hover:bg-sky-700 text-white'
                    ]">
                        {{ t('wake_schedule_first') }}
                    </button>
                </div>
            </div>
        </main>

        <!-- Create / Edit Modal -->
        <WakeFormModal v-model="showModal" :preset="currentPreset" :schedule="editingSchedule"
            :pulses-enabled="pulsesEnabled" @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import WakeFormModal from '@/Components/Admin/Wake/WakeFormModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: { type: Array, default: () => [] },
    currentPreset: { type: Object, default: null },
    schedules: { type: Array, default: () => [] },
    pulsesEnabled: { type: Boolean, default: false },
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);

const showModal = ref(false);
const editingSchedule = ref(null);

// ── Counts for the header ────────────────────────────────────────────────────
const activeCount = computed(() => props.schedules.filter(s => s.enabled).length);
const mineCount = computed(() => props.schedules.filter(s => s.created_by_kind === 'agent').length);
const userCount = computed(() => props.schedules.filter(s => s.created_by_kind === 'user').length);

// ── Type presentation ────────────────────────────────────────────────────────
const typeIcon = (type) => ({
    once: '→',
    interval: '↻',
    daily: '☀',
    cron: '⋮',
}[type] || '•');

const typeBadgeClass = (type) => {
    const map = {
        once: isDark.value ? 'bg-indigo-900 bg-opacity-50 text-indigo-200' : 'bg-indigo-100 text-indigo-800',
        interval: isDark.value ? 'bg-amber-900 bg-opacity-50 text-amber-200' : 'bg-amber-100 text-amber-800',
        daily: isDark.value ? 'bg-sky-900 bg-opacity-50 text-sky-200' : 'bg-sky-100 text-sky-800',
        cron: isDark.value ? 'bg-purple-900 bg-opacity-50 text-purple-200' : 'bg-purple-100 text-purple-800',
    };
    return map[type] || (isDark.value ? 'bg-gray-700 text-gray-200' : 'bg-gray-100 text-gray-700');
};

// ── Human-readable "when" (clock dialect; pulse echo is server-side in [[wake_schedule]]) ──
const secondsToClock = (sec) => {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
};

const humanizeSeconds = (sec) => {
    if (sec % 3600 === 0) return (sec / 3600) + 'h';
    if (sec % 60 === 0) return (sec / 60) + 'm';
    return sec + 's';
};

const describeWhen = (s) => {
    switch (s.schedule_type) {
        case 'once':
            return t('wake_type_once') + ' ' + (s.next_run_at ? formatDateTime(s.next_run_at) : '—');
        case 'interval':
            return t('wake_every') + ' ' + humanizeSeconds(s.interval_seconds || 0);
        case 'daily':
            return t('wake_daily') + ' ' + secondsToClock(s.daily_seconds || 0);
        case 'cron':
            return 'cron ' + s.cron_expression;
        default:
            return '?';
    }
};

// ── Actions ──────────────────────────────────────────────────────────────────
const changePreset = () => {
    router.get(route('admin.wakes.index'), { preset_id: selectedPresetId.value });
};

const openCreate = () => {
    editingSchedule.value = null;
    showModal.value = true;
};

const openEdit = (schedule) => {
    editingSchedule.value = schedule;
    showModal.value = true;
};

const cancelSchedule = (schedule) => {
    if (confirm(t('wake_confirm_cancel'))) {
        router.delete(route('admin.wakes.destroy', schedule.id), {
            data: { preset_id: selectedPresetId.value },
            preserveScroll: true,
        });
    }
};

const refreshData = () => {
    router.get(route('admin.wakes.index'), { preset_id: selectedPresetId.value }, {
        preserveScroll: true,
    });
};

const formatDateTime = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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