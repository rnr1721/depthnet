<template>
    <PageTitle :title="t('ag_title')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('ag_title')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-indigo-500' : 'bg-indigo-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-violet-500' : 'bg-violet-300']">
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

                <!-- Header: stats -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div>
                            <h2 :class="['text-xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                t('ag_subtitle') }}</h2>
                            <p :class="['text-sm mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                t('ag_subtitle_hint') }}</p>
                        </div>
                        <div class="flex gap-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    stats.total }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('ag_stats_total') }}</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-indigo-500">{{ stats.active }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('ag_stats_active') }}</div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                    stats.inactive }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('ag_stats_inactive') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-3 md:space-y-0">
                        <div class="flex gap-1 flex-wrap">
                            <button v-for="tab in statusTabs" :key="tab.value" @click="activeFilter = tab.value"
                                :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all focus:outline-none', activeFilter === tab.value ? tab.activeClass : (isDark ? 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800')]">
                                {{ t(tab.labelKey) }}
                                <span
                                    :class="['ml-1.5 text-xs px-1.5 py-0.5 rounded-full', activeFilter === tab.value ? 'bg-white bg-opacity-30' : (isDark ? 'bg-gray-700' : 'bg-gray-200')]">
                                    {{ tab.count }}
                                </span>
                            </button>
                        </div>
                        <button @click="showAddModal = true"
                            :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2', isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800' : 'bg-indigo-600 hover:bg-indigo-700 text-white']">
                            {{ t('ag_add_agent') }}
                        </button>
                    </div>
                </div>

                <!-- Agents List -->
                <div v-if="filteredAgents.length > 0"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="agent in filteredAgents" :key="agent.id"
                            :class="['group p-5 transition-colors', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']">
                            <div class="flex items-start justify-between space-x-4">
                                <div class="flex items-start space-x-3 flex-1 min-w-0">
                                    <!-- Status indicator -->
                                    <div class="mt-1 flex-shrink-0">
                                        <span
                                            :class="['w-2.5 h-2.5 rounded-full inline-block', agent.is_active ? 'bg-indigo-500' : 'bg-gray-400']"></span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <!-- Name + code badge -->
                                        <div class="flex items-center gap-2 flex-wrap mb-1">
                                            <span
                                                :class="['text-sm font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ agent.name }}
                                            </span>
                                            <span v-if="agent.code"
                                                :class="['font-mono text-xs px-2 py-0.5 rounded', isDark ? 'bg-indigo-900 text-indigo-300' : 'bg-indigo-100 text-indigo-800']">
                                                {{ agent.code }}
                                            </span>
                                            <span v-if="!agent.is_active"
                                                :class="['text-xs px-2 py-0.5 rounded-full', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-100 text-gray-500']">
                                                {{ t('ag_inactive') }}
                                            </span>
                                        </div>
                                        <!-- Description -->
                                        <p v-if="agent.description"
                                            :class="['text-xs mb-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            {{ agent.description }}
                                        </p>
                                        <!-- Planner + roles -->
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <span
                                                :class="['text-xs flex items-center gap-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2">
                                                    </path>
                                                </svg>
                                                {{ t('ag_planner') }}: <strong>{{ agent.planner.name }}</strong>
                                            </span>
                                            <span v-if="agent.roles.length > 0"
                                                :class="['text-xs flex items-center gap-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                                                    </path>
                                                </svg>
                                                <span v-for="(role, i) in agent.roles" :key="role.id">
                                                    <span
                                                        :class="['font-mono', isDark ? 'text-violet-400' : 'text-violet-600']">{{
                                                        role.code }}</span><span v-if="i < agent.roles.length - 1">,
                                                    </span>
                                                </span>
                                            </span>
                                            <span v-else
                                                :class="['text-xs italic', isDark ? 'text-gray-600' : 'text-gray-400']">{{
                                                t('ag_no_roles') }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div
                                    class="flex items-center gap-2 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="openTasks(agent)"
                                        :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-indigo-400' : 'hover:bg-indigo-50 text-gray-500 hover:text-indigo-600']"
                                        :title="t('ag_view_tasks')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01">
                                            </path>
                                        </svg>
                                    </button>
                                    <button @click="openEditAgent(agent)"
                                        :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-white' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700']"
                                        :title="t('ag_edit')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                    </button>
                                    <button @click="deleteAgent(agent)"
                                        :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'hover:bg-red-900 hover:bg-opacity-40 text-gray-500 hover:text-red-400' : 'hover:bg-red-50 text-gray-400 hover:text-red-600']"
                                        :title="t('ag_delete')">
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

                <!-- Empty state -->
                <div v-else
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl p-12 text-center', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 bg-gradient-to-br from-indigo-500 to-violet-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-semibold mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('ag_empty_title') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-500']">{{ t('ag_empty_hint') }}
                    </p>
                    <button @click="showAddModal = true"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500', isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white']">
                        {{ t('ag_add_first') }}
                    </button>
                </div>

            </div>
        </main>

        <AddAgentModal v-model="showAddModal" :presets="presets" :isDark="isDark" @success="refreshData" />
        <EditAgentModal v-model="showEditModal" :agent="activeAgent" :agents="agents" :presets="presets" :isDark="isDark"
            @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddAgentModal from '@/Components/Admin/Agents/AddAgentModal.vue';
import EditAgentModal from '@/Components/Admin/Agents/EditAgentModal.vue';

const { t } = useI18n();

const props = defineProps({
    agents: Array,
    presets: Array,
});

const isDark = ref(false);
const activeFilter = ref('all');
const showAddModal = ref(false);
const showEditModal = ref(false);
const activeAgent = ref(null);

const statusTabs = computed(() => [
    { value: 'all', labelKey: 'ag_filter_all', activeClass: isDark.value ? 'bg-gray-600 text-white' : 'bg-gray-800 text-white', count: props.agents.length },
    { value: 'active', labelKey: 'ag_filter_active', activeClass: 'bg-indigo-500 text-white', count: props.agents.filter(a => a.is_active).length },
    { value: 'inactive', labelKey: 'ag_filter_inactive', activeClass: isDark.value ? 'bg-gray-500 text-white' : 'bg-gray-400 text-white', count: props.agents.filter(a => !a.is_active).length },
]);

const filteredAgents = computed(() => {
    if (activeFilter.value === 'all') return props.agents;
    if (activeFilter.value === 'active') return props.agents.filter(a => a.is_active);
    if (activeFilter.value === 'inactive') return props.agents.filter(a => !a.is_active);
    return props.agents;
});

const stats = computed(() => ({
    total: props.agents.length,
    active: props.agents.filter(a => a.is_active).length,
    inactive: props.agents.filter(a => !a.is_active).length,
}));

const refreshData = () => router.get(route('admin.agents.index'));

const openEditAgent = (agent) => { activeAgent.value = agent; showEditModal.value = true; };

const openTasks = (agent) => {
    router.get(route('admin.agent-tasks.index'), { agent_id: agent.id });
};

const deleteAgent = (agent) => {
    if (!confirm(t('ag_confirm_delete', { name: agent.name }))) return;
    router.delete(route('admin.agents.destroy', agent.id));
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