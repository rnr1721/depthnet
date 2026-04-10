<template>
    <PageTitle :title="t('at_title')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('at_title')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

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

                <!-- Header: agent selector + back -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex items-center gap-4">
                            <button @click="goBack"
                                :class="['p-2 rounded-xl transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </button>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h2 :class="['text-lg font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                        agent.name }}</h2>
                                </div>
                                <div :class="['flex gap-2 mt-1 flex-wrap', isDark ? 'text-gray-400' : 'text-gray-500']">
                                    <span v-for="role in agent.roles" :key="role.id"
                                        :class="['font-mono text-xs px-2 py-0.5 rounded', isDark ? 'bg-violet-900 text-violet-300' : 'bg-violet-100 text-violet-700']">
                                        {{ role.code }}
                                    </span>
                                    <span v-if="!agent.roles.length" class="text-xs italic">{{ t('ag_no_roles')
                                        }}</span>
                                </div>
                            </div>
                        </div>
                        <!-- Stats -->
                        <div class="flex gap-4 flex-wrap">
                            <div v-for="stat in taskStats" :key="stat.status" class="text-center">
                                <div :class="['text-xl font-bold', stat.color]">{{ stat.count }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ t('at_status_'
                                    + stat.status) }}</div>
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
                        <div class="flex gap-3">
                            <button v-if="tasks.length > 0" @click="confirmClear = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'bg-red-900 bg-opacity-60 hover:bg-red-800 text-red-300' : 'bg-red-50 hover:bg-red-100 text-red-700 border border-red-200']">
                                {{ t('at_clear_all') }}
                            </button>
                            <button @click="showAddModal = true"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2', isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800' : 'bg-indigo-600 hover:bg-indigo-700 text-white']">
                                {{ t('at_add_task') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tasks List -->
                <div v-if="filteredTasks.length > 0"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                        <div v-for="task in filteredTasks" :key="task.id"
                            :class="['group p-5 transition-colors', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']">
                            <div class="flex items-start justify-between space-x-4">
                                <div class="flex items-start space-x-3 flex-1 min-w-0">
                                    <span
                                        :class="['font-mono text-xs font-bold px-2 py-0.5 rounded mt-0.5 flex-shrink-0', isDark ? 'bg-indigo-900 text-indigo-300' : 'bg-indigo-100 text-indigo-800']">
                                        #{{ task.id }}
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap mb-1">
                                            <span
                                                :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">{{
                                                task.title }}</span>
                                            <span :class="statusBadgeClass(task.status)">{{ t('at_status_' +
                                                task.status) }}</span>
                                            <span v-if="task.assigned_role"
                                                :class="['font-mono text-xs px-2 py-0.5 rounded', isDark ? 'bg-violet-900 text-violet-300' : 'bg-violet-100 text-violet-700']">
                                                {{ task.assigned_role }}
                                            </span>
                                        </div>
                                        <p v-if="task.description"
                                            :class="['text-xs mb-1', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                            task.description }}</p>
                                        <div v-if="task.result"
                                            :class="['text-xs p-2 rounded-lg mt-2', isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600']">
                                            <span
                                                :class="['font-medium mr-1', isDark ? 'text-gray-200' : 'text-gray-700']">{{
                                                t('at_result') }}:</span>{{ task.result }}
                                        </div>
                                        <div v-if="task.validator_notes"
                                            :class="['text-xs p-2 rounded-lg mt-1', isDark ? 'bg-yellow-900 bg-opacity-30 text-yellow-300' : 'bg-yellow-50 text-yellow-700']">
                                            <span class="font-medium mr-1">{{ t('at_validator_notes') }}:</span>{{
                                            task.validator_notes }}
                                        </div>
                                        <div :class="['text-xs mt-2', isDark ? 'text-gray-500' : 'text-gray-400']">
                                            {{ t('at_attempts') }}: {{ task.attempts }}
                                            · {{ task.updated_at }}
                                        </div>
                                    </div>
                                </div>
                                <!-- Actions -->
                                <div
                                    class="flex items-center gap-2 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button @click="deleteTask(task)"
                                        :class="['p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500', isDark ? 'hover:bg-red-900 hover:bg-opacity-40 text-gray-500 hover:text-red-400' : 'hover:bg-red-50 text-gray-400 hover:text-red-600']"
                                        :title="t('at_delete')">
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
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">{{ t('at_empty') }}</p>
                </div>

                <!-- Clear confirm -->
                <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0"
                    enter-to-class="opacity-100">
                    <div v-if="confirmClear" class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
                        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm"
                            @click="confirmClear = false"></div>
                        <div
                            :class="['relative rounded-2xl shadow-2xl p-6 max-w-sm w-full', isDark ? 'bg-gray-800' : 'bg-white']">
                            <h3 :class="['text-lg font-semibold mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                                t('at_clear_confirm_title') }}</h3>
                            <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                t('at_clear_confirm_text') }}</p>
                            <div class="flex gap-3 justify-end">
                                <button @click="confirmClear = false"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('at_cancel') }}
                                </button>
                                <button @click="clearTasks"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all', isDark ? 'bg-red-700 hover:bg-red-600 text-white' : 'bg-red-600 hover:bg-red-700 text-white']">
                                    {{ t('at_clear_confirm_btn') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>

            </div>
        </main>

        <AddTaskModal v-model="showAddModal" :agent="agent" :isDark="isDark" @success="refreshData" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import AddTaskModal from '@/Components/Admin/Agents/AddTaskModal.vue';

const { t } = useI18n();

const props = defineProps({
    agent: Object,
    agents: Array,
    tasks: Array,
    statusFilter: String,
});

const isDark = ref(false);
const activeFilter = ref(props.statusFilter || null);
const showAddModal = ref(false);
const confirmClear = ref(false);

const allStatuses = ['pending', 'in_progress', 'validating', 'done', 'failed', 'escalated'];

const statusTabs = computed(() => [
    { value: null, labelKey: 'at_filter_active', activeClass: 'bg-indigo-500 text-white', count: props.tasks.filter(t => !['done', 'failed', 'escalated'].includes(t.status)).length },
    { value: 'all', labelKey: 'at_filter_all', activeClass: isDark.value ? 'bg-gray-600 text-white' : 'bg-gray-800 text-white', count: props.tasks.length },
    { value: 'done', labelKey: 'at_filter_done', activeClass: 'bg-green-500 text-white', count: props.tasks.filter(t => t.status === 'done').length },
    { value: 'failed', labelKey: 'at_filter_failed', activeClass: 'bg-red-500 text-white', count: props.tasks.filter(t => t.status === 'failed').length },
]);

const filteredTasks = computed(() => {
    if (activeFilter.value === 'all') return props.tasks;
    if (!activeFilter.value) return props.tasks.filter(t => !['done', 'failed', 'escalated'].includes(t.status));
    return props.tasks.filter(t => t.status === activeFilter.value);
});

const taskStats = computed(() => [
    { status: 'pending', count: props.tasks.filter(t => t.status === 'pending').length, color: 'text-gray-500' },
    { status: 'in_progress', count: props.tasks.filter(t => t.status === 'in_progress').length, color: 'text-indigo-500' },
    { status: 'validating', count: props.tasks.filter(t => t.status === 'validating').length, color: 'text-yellow-500' },
    { status: 'done', count: props.tasks.filter(t => t.status === 'done').length, color: 'text-green-500' },
    { status: 'failed', count: props.tasks.filter(t => t.status === 'failed').length, color: 'text-red-500' },
].filter(s => s.count > 0 || ['pending', 'done'].includes(s.status)));

const statusBadgeClass = (status) => {
    const base = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium';
    const map = {
        pending: isDark.value ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600',
        in_progress: isDark.value ? 'bg-indigo-900 text-indigo-300' : 'bg-indigo-100 text-indigo-700',
        validating: isDark.value ? 'bg-yellow-900 text-yellow-300' : 'bg-yellow-100 text-yellow-700',
        done: isDark.value ? 'bg-green-900 text-green-300' : 'bg-green-100 text-green-800',
        failed: isDark.value ? 'bg-red-900 text-red-300' : 'bg-red-100 text-red-700',
        escalated: isDark.value ? 'bg-orange-900 text-orange-300' : 'bg-orange-100 text-orange-700',
    };
    return `${base} ${map[status] ?? ''}`;
};

const refreshData = () => {
    router.get(route('admin.agent-tasks.index'), { agent_id: props.agent.id });
};

const goBack = () => router.get(route('admin.agents.index'));

const deleteTask = (task) => {
    if (!confirm(t('at_confirm_delete'))) return;
    router.delete(route('admin.agent-tasks.destroy', task.id));
};

const clearTasks = () => {
    confirmClear.value = false;
    router.post(route('admin.agent-tasks.clear'), { agent_id: props.agent.id });
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