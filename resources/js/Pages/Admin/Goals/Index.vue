<template>
    <PageTitle :title="t('gm_title')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('gm_title')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-amber-500' : 'bg-amber-300']"></div>
                <div :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-orange-500' : 'bg-orange-300']"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Flash Messages -->
                <Transition enter-active-class="transition ease-out duration-300" enter-from-class="transform opacity-0 scale-95"
                    enter-to-class="transform opacity-100 scale-100" leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash?.success"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800']">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.success }}</span>
                        </div>
                    </div>
                </Transition>
                <Transition enter-active-class="transition ease-out duration-300" enter-from-class="transform opacity-0 scale-95"
                    enter-to-class="transform opacity-100 scale-100" leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash?.error"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800']">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.error }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Header: preset selector + stats -->
                <div :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex-1">
                            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('gm_select_preset') }}
                            </label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>
                        <!-- Stats -->
                        <div class="flex gap-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{ stats.total }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('gm_stats_total') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-amber-500">{{ stats.active }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('gm_stats_active') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-green-500">{{ stats.done }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('gm_stats_done') }}</div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-gray-400' : 'text-gray-500']">{{ stats.paused }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('gm_stats_paused') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-3 md:space-y-0">
                        <!-- Status filter tabs -->
                        <div class="flex gap-1 flex-wrap">
                            <button v-for="tab in statusTabs" :key="tab.value"
                                @click="activeFilter = tab.value"
                                :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all focus:outline-none', activeFilter === tab.value ? tab.activeClass : (isDark ? 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800')]">
                                {{ t(tab.labelKey) }}
                                <span :class="['ml-1.5 text-xs px-1.5 py-0.5 rounded-full', activeFilter === tab.value ? 'bg-white bg-opacity-30' : (isDark ? 'bg-gray-700' : 'bg-gray-200')]">
                                    {{ tab.count }}
                                </span>
                            </button>
                        </div>
                        <div class="flex gap-3">
                            <button v-if="goals.length > 0" @click="showClearConfirm = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-900 bg-opacity-60 hover:bg-red-800 text-red-300' : 'bg-red-50 hover:bg-red-100 text-red-700 border border-red-200']">
                                {{ t('gm_clear_all') }}
                            </button>
                            <button @click="showAddModal = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2', isDark ? 'bg-amber-600 hover:bg-amber-700 text-white focus:ring-offset-gray-800' : 'bg-amber-600 hover:bg-amber-700 text-white']">
                                {{ t('gm_add_goal') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Goals List -->
                <div v-if="filteredGoals.length > 0"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="goal in filteredGoals" :key="goal.id"
                            :class="['group p-5 transition-colors', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']">
                            <div class="flex items-start justify-between space-x-4">
                                <div class="flex items-start space-x-3 flex-1 min-w-0">
                                    <!-- Number badge -->
                                    <span :class="['font-mono text-xs font-bold px-2 py-0.5 rounded mt-0.5 flex-shrink-0', isDark ? 'bg-amber-900 text-amber-300' : 'bg-amber-100 text-amber-800']">
                                        #{{ goal.number }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <!-- Title + status -->
                                        <div class="flex items-center gap-2 flex-wrap mb-1">
                                            <span :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ goal.title }}
                                            </span>
                                            <span :class="statusBadgeClass(goal.status)">
                                                {{ t('gm_status_' + goal.status) }}
                                            </span>
                                        </div>
                                        <!-- Motivation -->
                                        <p v-if="goal.motivation" :class="['text-xs mb-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            💡 {{ goal.motivation }}
                                        </p>
                                        <!-- Last progress note -->
                                        <p v-if="goal.progress.length > 0" :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            → {{ goal.progress[goal.progress.length - 1].content }}
                                            <span :class="['ml-1 opacity-60', isDark ? 'text-gray-500' : 'text-gray-400']">
                                                {{ goal.progress[goal.progress.length - 1].created_at }}
                                            </span>
                                        </p>
                                        <!-- Progress count -->
                                        <button v-if="goal.progress.length > 0"
                                            @click="openGoalDetail(goal)"
                                            :class="['mt-1.5 text-xs transition-colors focus:outline-none', isDark ? 'text-amber-400 hover:text-amber-300' : 'text-amber-600 hover:text-amber-800']">
                                            {{ t('gm_progress_count', { n: goal.progress.length }) }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center space-x-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <!-- View detail -->
                                    <button @click="openGoalDetail(goal)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700']"
                                        :title="t('gm_view_detail')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                    </button>
                                    <!-- Add progress -->
                                    <button @click="openAddProgress(goal)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-amber-900 text-amber-400 hover:text-amber-300' : 'hover:bg-amber-100 text-amber-600 hover:text-amber-800']"
                                        :title="t('gm_add_progress')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                        </svg>
                                    </button>
                                    <!-- Status cycle -->
                                    <button v-if="goal.status !== 'done'" @click="openSetStatus(goal)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700']"
                                        :title="t('gm_change_status')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                    </button>
                                    <!-- Delete -->
                                    <button @click="deleteGoal(goal.number)"
                                        :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-500 hover:text-red-700']"
                                        :title="t('gm_delete')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty state for filter -->
                <div v-else-if="goals.length > 0 && filteredGoals.length === 0"
                    :class="['text-center py-10 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('gm_no_goals_filter') }}
                    </p>
                </div>

                <!-- Empty state -->
                <div v-else
                    :class="['text-center py-12 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-amber-400 to-orange-600 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{ t('gm_no_goals') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('gm_no_goals_description') }}</p>
                    <button @click="showAddModal = true"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-amber-500', isDark ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white']">
                        {{ t('gm_add_first_goal') }}
                    </button>
                </div>

            </div>
        </main>

        <AddGoalModal
            v-model="showAddModal"
            :preset="currentPreset"
            :isDark="isDark"
            @success="refreshData"
        />
        <GoalDetailModal
            v-model="showDetailModal"
            :preset="currentPreset"
            :goal="activeGoal"
            :isDark="isDark"
            @success="refreshData"
        />
        <AddProgressModal
            v-model="showProgressModal"
            :preset="currentPreset"
            :goal="activeGoal"
            :isDark="isDark"
            @success="refreshData"
        />
        <SetGoalStatusModal
            v-model="showStatusModal"
            :preset="currentPreset"
            :goal="activeGoal"
            :isDark="isDark"
            @success="refreshData"
        />
        <ClearGoalsModal
            v-model="showClearConfirm"
            :preset="currentPreset"
            :isDark="isDark"
            @success="refreshData"
        />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddGoalModal from '@/Components/Admin/Goals/AddGoalModal.vue';
import GoalDetailModal from '@/Components/Admin/Goals/GoalDetailModal.vue';
import AddProgressModal from '@/Components/Admin/Goals/AddProgressModal.vue';
import SetGoalStatusModal from '@/Components/Admin/Goals/SetGoalStatusModal.vue';
import ClearGoalsModal from '@/Components/Admin/Goals/ClearGoalsModal.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    goals: Array,
    statusFilter: String,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const activeFilter = ref(props.statusFilter || 'all');

const showAddModal      = ref(false);
const showDetailModal   = ref(false);
const showProgressModal = ref(false);
const showStatusModal   = ref(false);
const showClearConfirm  = ref(false);
const activeGoal        = ref(null);

const statusTabs = computed(() => [
    { value: 'all',    labelKey: 'gm_filter_all',    activeClass: isDark.value ? 'bg-gray-600 text-white' : 'bg-gray-800 text-white', count: props.goals.length },
    { value: 'active', labelKey: 'gm_filter_active', activeClass: 'bg-amber-500 text-white',  count: props.goals.filter(g => g.status === 'active').length },
    { value: 'paused', labelKey: 'gm_filter_paused', activeClass: isDark.value ? 'bg-gray-500 text-white' : 'bg-gray-400 text-white', count: props.goals.filter(g => g.status === 'paused').length },
    { value: 'done',   labelKey: 'gm_filter_done',   activeClass: 'bg-green-500 text-white',  count: props.goals.filter(g => g.status === 'done').length },
]);

const filteredGoals = computed(() => {
    if (activeFilter.value === 'all') return props.goals;
    return props.goals.filter(g => g.status === activeFilter.value);
});

const stats = computed(() => ({
    total:  props.goals.length,
    active: props.goals.filter(g => g.status === 'active').length,
    done:   props.goals.filter(g => g.status === 'done').length,
    paused: props.goals.filter(g => g.status === 'paused').length,
}));

const statusBadgeClass = (status) => {
    const base = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium';
    if (status === 'active') return `${base} ${isDark.value ? 'bg-amber-900 text-amber-300' : 'bg-amber-100 text-amber-800'}`;
    if (status === 'done')   return `${base} ${isDark.value ? 'bg-green-900 text-green-300' : 'bg-green-100 text-green-800'}`;
    if (status === 'paused') return `${base} ${isDark.value ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600'}`;
    return base;
};

const changePreset = () => {
    router.get(route('admin.goals.index'), { preset_id: selectedPresetId.value });
};

const refreshData = () => {
    router.get(route('admin.goals.index'), { preset_id: selectedPresetId.value });
};

const openGoalDetail  = (goal) => { activeGoal.value = goal; showDetailModal.value = true; };
const openAddProgress = (goal) => { activeGoal.value = goal; showProgressModal.value = true; };
const openSetStatus   = (goal) => { activeGoal.value = goal; showStatusModal.value = true; };

const deleteGoal = (goalNumber) => {
    if (!confirm(t('gm_confirm_delete'))) return;
    router.delete(route('admin.goals.destroy', goalNumber), {
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
