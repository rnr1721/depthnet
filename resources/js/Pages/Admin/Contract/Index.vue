<template>
    <PageTitle :title="t('contract_manager')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('contract_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-amber-500' : 'bg-amber-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-orange-500' : 'bg-orange-300']">
                </div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Flash Messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200' : 'bg-green-50 border-green-400 text-green-800']">
                        <span class="font-medium">{{ $page.props.flash.success }}</span>
                    </div>
                </Transition>
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.error"
                        :class="['mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm', isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800']">
                        <span class="font-medium">{{ $page.props.flash.error }}</span>
                    </div>
                </Transition>

                <!-- Header Section -->
                <div
                    :class="['mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                        <div class="flex-1">
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                    t('select_preset') }}</label>
                            <select v-model="selectedPresetId" @change="changePreset"
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option v-for="preset in presets" :key="preset.id" :value="preset.id">
                                    {{ preset.name }} {{ preset.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                        </div>

                        <!-- Stats -->
                        <div v-if="stats.total !== undefined"
                            class="flex flex-col lg:flex-row space-y-2 lg:space-y-0 lg:space-x-6">
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    stats.total || 0 }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('contract_total') }}</div>
                            </div>
                            <div v-for="(count, st) in stats.by_status" :key="st" class="text-center">
                                <div :class="['text-2xl font-bold', statusColor(st)]">{{ count }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ st }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Raised flags strip — the live result of the metabolism -->
                <div v-if="flags.length"
                    :class="['mb-6 px-5 py-4 rounded-2xl border backdrop-blur-sm', isDark ? 'bg-amber-900 bg-opacity-30 border-amber-700 text-amber-100' : 'bg-amber-50 border-amber-200 text-amber-900']">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="relative flex h-2.5 w-2.5">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                        </span>
                        <span class="text-sm font-semibold">{{ t('contract_raised_flags') }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="f in flags" :key="f.flag"
                            :class="['inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium', isDark ? 'bg-gray-800 bg-opacity-60' : 'bg-white']">
                            <span class="font-semibold">{{ f.flag }}</span>
                            <span v-if="f.kind === 'goal_candidate'"
                                :class="['text-[10px] uppercase tracking-wide', isDark ? 'text-orange-300' : 'text-orange-600']">goal</span>
                            <span :class="['opacity-60', isDark ? 'text-gray-300' : 'text-gray-500']">· {{ f.by
                            }}</span>
                        </span>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0 gap-4">
                        <!-- Status filter -->
                        <div class="flex gap-3 flex-wrap">
                            <select v-model="localStatus" @change="applyFilters"
                                :class="['rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option value="">{{ t('contract_all_statuses') }}</option>
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button @click="tickNow"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700']"
                                :title="t('contract_tick_hint')">
                                {{ t('contract_tick_now') }}
                            </button>
                            <button @click="openAdd"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-amber-500 bg-amber-600 hover:bg-amber-700 text-white']">
                                {{ t('contract_add') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Contracts List -->
                <div v-if="contracts.length" class="space-y-3">
                    <div v-for="c in contracts" :key="c.name"
                        :class="['backdrop-blur-sm border shadow rounded-2xl overflow-hidden transition-all', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700 hover:border-gray-600' : 'bg-white bg-opacity-90 border-gray-200 hover:border-gray-300']">
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3 flex-1 min-w-0">
                                    <span
                                        :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-medium flex-shrink-0 mt-0.5', formBadge(c.form)]">{{
                                            c.form }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p
                                                :class="['text-sm font-semibold break-words', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ c.name }}</p>
                                            <span v-if="c.vital"
                                                :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide', isDark ? 'bg-rose-900 text-rose-200' : 'bg-rose-100 text-rose-700']">vital</span>
                                        </div>
                                        <p
                                            :class="['mt-1 text-xs break-words font-mono', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            {{ describeTrigger(c) }} → {{ describeAction(c.action) }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span
                                        :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', statusBadge(c.status)]">{{
                                            c.status }}</span>
                                    <button @click="toggleExpand(c.name)"
                                        :class="['p-1.5 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-amber-400 hover:bg-gray-700' : 'text-gray-400 hover:text-amber-500 hover:bg-gray-100']"
                                        :title="t('contract_details')">
                                        <svg class="w-4 h-4 transition-transform"
                                            :class="{ 'rotate-180': expanded === c.name }" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Lifecycle buttons -->
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button v-if="c.status === 'hypothesis'" @click="lifecycle(c, 'promote')"
                                    :class="lifecycleBtn('promote')">{{ t('contract_promote') }}</button>
                                <button v-if="c.status === 'active'" @click="lifecycle(c, 'suspend')"
                                    :class="lifecycleBtn('suspend')">{{ t('contract_suspend') }}</button>
                                <button v-if="c.status === 'suspended'" @click="lifecycle(c, 'resume')"
                                    :class="lifecycleBtn('resume')">{{ t('contract_resume') }}</button>
                                <button v-if="c.status !== 'hypothesis'" @click="lifecycle(c, 'revoke')"
                                    :class="lifecycleBtn('revoke')">{{ t('contract_revoke') }}</button>
                                <button @click="deleteContract(c)" :class="lifecycleBtn('delete')">{{ t('contract_delete')
                                }}</button>
                            </div>

                            <!-- Expanded details -->
                            <Transition enter-active-class="transition ease-out duration-200"
                                enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="expanded === c.name"
                                    :class="['mt-4 pt-4 border-t text-xs space-y-2', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <div v-if="c.source"><span :class="labelCls">source:</span> <span
                                            class="font-mono">{{ c.source }}</span></div>
                                    <div v-if="c.suspend_when"><span :class="labelCls">suspend when:</span> <span
                                            class="font-mono">{{ c.suspend_when }}</span></div>
                                    <div><span :class="labelCls">trigger:</span> <code
                                            :class="codeCls">{{ JSON.stringify(c.trigger) }}</code></div>
                                    <div><span :class="labelCls">action:</span> <code
                                            :class="codeCls">{{ JSON.stringify(c.action) }}</code></div>
                                    <div v-if="c.history && c.history.length">
                                        <span :class="labelCls">history:</span>
                                        <div class="mt-1 space-y-0.5 font-mono">
                                            <div v-for="(h, i) in c.history" :key="i"
                                                :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                                                {{ h.from }} → {{ h.to }} <span class="opacity-60">@ {{ formatDate(h.at)
                                                }}</span><span v-if="h.reason" class="opacity-60"> ({{ h.reason
                                                    }})</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else
                    :class="['text-center py-16 backdrop-blur-sm border shadow-xl rounded-2xl', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('contract_empty') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                        t('contract_empty_description') }}</p>
                    <button @click="openAdd"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all bg-amber-600 hover:bg-amber-700 text-white']">{{
                            t('contract_add_first') }}</button>
                </div>
            </div>
        </main>

        <!-- Add Contract Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0"
                enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black bg-opacity-60 backdrop-blur-sm" @click="showAddModal = false">
                    </div>
                    <div
                        :class="['relative w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl p-6', isDark ? 'bg-gray-800 border border-gray-700' : 'bg-white border border-gray-200']">
                        <h3 :class="['text-lg font-semibold mb-1', isDark ? 'text-white' : 'text-gray-900']">{{
                            t('contract_add') }}</h3>
                        <p :class="['text-xs mb-4', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                            t('contract_add_hint') }}</p>

                        <!-- Mode toggle: guided / raw JSON -->
                        <div class="flex gap-1 mb-4 p-1 rounded-xl" :class="isDark ? 'bg-gray-900' : 'bg-gray-100'">
                            <button @click="rawMode = false"
                                :class="['flex-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', !rawMode ? (isDark ? 'bg-gray-700 text-white' : 'bg-white text-gray-900 shadow') : (isDark ? 'text-gray-400' : 'text-gray-500')]">{{
                                    t('contract_guided') }}</button>
                            <button @click="rawMode = true"
                                :class="['flex-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', rawMode ? (isDark ? 'bg-gray-700 text-white' : 'bg-white text-gray-900 shadow') : (isDark ? 'text-gray-400' : 'text-gray-500')]">{{
                                    t('contract_raw_json') }}</button>
                        </div>

                        <!-- Guided form -->
                        <div v-if="!rawMode" class="space-y-3">
                            <div>
                                <label :class="fieldLabel">{{ t('contract_name') }}</label>
                                <input v-model="form.name" :class="fieldInput" placeholder="quiet_watch" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="fieldLabel">{{ t('contract_form_label') }}</label>
                                    <select v-model="form.form" :class="fieldInput">
                                        <option v-for="f in forms" :key="f" :value="f">{{ f }}</option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-2">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.vital"
                                            class="rounded text-amber-600 focus:ring-amber-500"
                                            :disabled="!vitalAllowed" />
                                        <span
                                            :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">vital</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Threshold forms: match + threshold -->
                            <template v-if="usesMatch">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label :class="fieldLabel">match source</label>
                                        <input v-model="form.match_source" :class="fieldInput" placeholder="journal" />
                                    </div>
                                    <div>
                                        <label :class="fieldLabel">match type</label>
                                        <input v-model="form.match_type" :class="fieldInput"
                                            placeholder="interaction" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label :class="fieldLabel">contains (optional)</label>
                                        <input v-model="form.match_contains" :class="fieldInput" placeholder="retry" />
                                    </div>
                                    <div>
                                        <label :class="fieldLabel">match mode</label>
                                        <select v-model="form.match_mode" :class="fieldInput">
                                            <option value="strict">strict</option>
                                            <option value="semantic">semantic</option>
                                            <option value="strict_then_semantic">strict_then_semantic</option>
                                        </select>
                                    </div>
                                </div>
                                <div v-if="form.form === 'THR_T'">
                                    <label :class="fieldLabel">threshold seconds</label>
                                    <input v-model.number="form.threshold_seconds" type="number" :class="fieldInput"
                                        placeholder="300" />
                                </div>
                                <div v-if="form.form === 'THR_C'" class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label :class="fieldLabel">threshold count</label>
                                        <input v-model.number="form.threshold_count" type="number" :class="fieldInput"
                                            placeholder="3" />
                                    </div>
                                    <div>
                                        <label :class="fieldLabel">window seconds (opt)</label>
                                        <input v-model.number="form.window_seconds" type="number" :class="fieldInput"
                                            placeholder="3600" />
                                    </div>
                                </div>
                            </template>

                            <!-- State-vector forms: target + coefficients -->
                            <template v-if="usesStateVector">
                                <div>
                                    <label :class="fieldLabel">target (state dimension)</label>
                                    <input v-model="form.target" :class="fieldInput" placeholder="load" />
                                </div>
                                <div v-if="form.form === 'ACC'" class="grid grid-cols-2 gap-3">
                                    <div><label :class="fieldLabel">weight</label><input v-model.number="form.weight"
                                            type="number" step="0.01" :class="fieldInput" placeholder="0.1" /></div>
                                    <div><label :class="fieldLabel">cap</label><input v-model.number="form.cap"
                                            type="number" step="0.01" :class="fieldInput" placeholder="1.0" /></div>
                                </div>
                                <div v-if="form.form === 'DEC'" class="grid grid-cols-2 gap-3">
                                    <div><label :class="fieldLabel">rate</label><input v-model.number="form.rate"
                                            type="number" step="0.01" :class="fieldInput" placeholder="0.05" /></div>
                                    <div><label :class="fieldLabel">floor</label><input v-model.number="form.floor"
                                            type="number" step="0.01" :class="fieldInput" placeholder="0.0" /></div>
                                </div>
                            </template>

                            <!-- Action -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="fieldLabel">action</label>
                                    <select v-model="form.action_type" :class="fieldInput">
                                        <option value="set_flag">set_flag</option>
                                        <option value="create_goal">create_goal</option>
                                        <option value="nudge_state">nudge_state</option>
                                        <option value="inject_memo">inject_memo</option>
                                    </select>
                                </div>
                                <div v-if="['set_flag', 'create_goal'].includes(form.action_type)">
                                    <label :class="fieldLabel">flag</label>
                                    <input v-model="form.action_flag" :class="fieldInput" placeholder="been_quiet" />
                                </div>
                                <div v-if="form.action_type === 'inject_memo'" class="col-span-2">
                                    <label :class="fieldLabel">memo text</label>
                                    <input v-model="form.action_text" :class="fieldInput"
                                        placeholder="It's been quiet for a while." />
                                </div>
                                <template v-if="form.action_type === 'nudge_state'">
                                    <div><label :class="fieldLabel">target</label><input v-model="form.action_target"
                                            :class="fieldInput" placeholder="longing" /></div>
                                    <div><label :class="fieldLabel">delta</label><input
                                            v-model.number="form.action_delta" type="number" step="0.01"
                                            :class="fieldInput" placeholder="0.1" /></div>
                                </template>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="fieldLabel">suspend when (opt)</label>
                                    <input v-model="form.suspend_when" :class="fieldInput" placeholder="flag_name" />
                                </div>
                                <div class="flex items-end pb-2">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.promote"
                                            class="rounded text-amber-600 focus:ring-amber-500" />
                                        <span :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                            t('contract_create_active')
                                        }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Raw JSON -->
                        <div v-else>
                            <label :class="fieldLabel">{{ t('contract_raw_json') }}</label>
                            <textarea v-model="rawJson" rows="12" :class="[fieldInput, 'font-mono text-xs resize-none']"
                                :placeholder="rawPlaceholder"></textarea>
                            <p v-if="rawError" :class="['mt-1 text-xs', isDark ? 'text-red-400' : 'text-red-600']">{{
                                rawError }}</p>
                        </div>

                        <div class="flex justify-end gap-3 mt-5">
                            <button @click="showAddModal = false"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                    t('cancel') }}</button>
                            <button @click="submitContract"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium text-white bg-amber-600 hover:bg-amber-700']">{{
                                    t('save')
                                }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    contracts: Array,
    stats: Object,
    flags: Array,
    filterStatus: String,
    forms: Array,
    statuses: Array,
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const localStatus = ref(props.filterStatus || '');
const showAddModal = ref(false);
const expanded = ref(null);
const rawMode = ref(false);
const rawJson = ref('');
const rawError = ref('');

const blankForm = () => ({
    name: '', form: 'THR_T', vital: false,
    match_source: 'journal', match_type: '', match_contains: '', match_mode: 'strict',
    threshold_seconds: null, threshold_count: null, window_seconds: null,
    target: '', weight: null, cap: null, rate: null, floor: null,
    action_type: 'set_flag', action_flag: '', action_text: '', action_target: '', action_delta: null,
    suspend_when: '', promote: false,
});
const form = ref(blankForm());

const usesMatch = computed(() => ['THR_T', 'THR_C'].includes(form.value.form));
const usesStateVector = computed(() => ['ACC', 'DEC'].includes(form.value.form));
const vitalAllowed = computed(() => form.value.match_mode === 'strict' || usesStateVector.value);

// A semantic match can't be vital — mirror the engine's rule in the UI.
watch(() => form.value.match_mode, () => {
    if (!vitalAllowed.value) form.value.vital = false;
});

const rawPlaceholder = `{
  "name": "quiet_watch",
  "form": "THR_T",
  "trigger": {
    "match": { "source": "journal", "type": "interaction" },
    "threshold_seconds": 300
  },
  "action": { "type": "set_flag", "flag": "been_quiet" }
}`;

const changePreset = () => router.get(route('admin.contracts.index'), { preset_id: selectedPresetId.value });
const applyFilters = () => router.get(route('admin.contracts.index'), { preset_id: selectedPresetId.value, status: localStatus.value });

const openAdd = () => { form.value = blankForm(); rawJson.value = ''; rawError.value = ''; rawMode.value = false; showAddModal.value = true; };
const toggleExpand = (name) => { expanded.value = expanded.value === name ? null : name; };

const buildDefinition = () => {
    const f = form.value;
    const trigger = {};
    if (usesMatch.value) {
        const match = { source: f.match_source || 'journal', match_mode: f.match_mode };
        if (f.match_type) match.type = f.match_type;
        if (f.match_contains) match.contains = f.match_contains;
        trigger.match = match;
        if (f.form === 'THR_T') trigger.threshold_seconds = f.threshold_seconds;
        if (f.form === 'THR_C') {
            trigger.threshold_count = f.threshold_count;
            if (f.window_seconds) trigger.window_seconds = f.window_seconds;
        }
    } else {
        trigger.target = f.target;
        if (f.form === 'ACC') { trigger.weight = f.weight; trigger.cap = f.cap; }
        if (f.form === 'DEC') { trigger.rate = f.rate; trigger.floor = f.floor; }
    }

    const action = { type: f.action_type };
    if (['set_flag', 'create_goal'].includes(f.action_type)) action.flag = f.action_flag;
    if (f.action_type === 'inject_memo') action.text = f.action_text;
    if (f.action_type === 'nudge_state') { action.target = f.action_target; action.delta = f.action_delta; }

    const def = { name: f.name, form: f.form, vital: f.vital, trigger, action };
    if (f.suspend_when) def.suspend_when = f.suspend_when;
    if (f.promote) def.status = 'active';
    return def;
};

const submitContract = () => {
    let payload;
    if (rawMode.value) {
        try {
            payload = JSON.parse(rawJson.value);
            rawError.value = '';
        } catch (e) {
            rawError.value = t('contract_invalid_json') + ' ' + e.message;
            return;
        }
    } else {
        payload = buildDefinition();
    }

    router.post(route('admin.contracts.store'), { preset_id: selectedPresetId.value, ...payload }, {
        onSuccess: () => { showAddModal.value = false; },
    });
};

const lifecycle = (c, action) => {
    router.post(route(`admin.contracts.${action}`, c.name), { preset_id: selectedPresetId.value });
};

const deleteContract = (c) => {
    if (confirm(t('contract_confirm_delete', { name: c.name }))) {
        router.delete(route('admin.contracts.destroy', c.name), { data: { preset_id: selectedPresetId.value } });
    }
};

const tickNow = () => {
    router.post(route('admin.contracts.tick'), { preset_id: selectedPresetId.value });
};

// ── Display helpers ──────────────────────────────────────────────────────────

const describeTrigger = (c) => {
    const tr = c.trigger || {};
    if (c.form === 'THR_T') return `≥ ${tr.threshold_seconds}s since ${matchLabel(c.match)}`;
    if (c.form === 'THR_C') return `≥ ${tr.threshold_count}× ${matchLabel(c.match)}${tr.window_seconds ? ` / ${tr.window_seconds}s` : ''}`;
    if (c.form === 'ACC') return `${tr.target} += ${tr.weight}·dt → ${tr.cap}`;
    if (c.form === 'DEC') return `${tr.target} -= ${tr.rate} → ${tr.floor}`;
    return '';
};
const matchLabel = (m) => m ? [m.source, m.type, m.contains].filter(Boolean).join('/') : '?';
const describeAction = (a) => {
    if (!a) return '';
    if (a.type === 'set_flag') return `flag:${a.flag}`;
    if (a.type === 'create_goal') return `goal:${a.flag}`;
    if (a.type === 'nudge_state') return `${a.target}${a.delta >= 0 ? '+' : ''}${a.delta}`;
    if (a.type === 'inject_memo') return 'memo';
    return a.type;
};

const formatDate = (s) => { const d = new Date(s); return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };

const statusColor = (s) => ({ hypothesis: 'text-gray-400', active: 'text-green-400', suspended: 'text-yellow-400' }[s] || 'text-gray-400');

const formBadge = (f) => {
    const dark = { THR_T: 'bg-blue-900 text-blue-200', THR_C: 'bg-indigo-900 text-indigo-200', ACC: 'bg-emerald-900 text-emerald-200', DEC: 'bg-purple-900 text-purple-200' };
    const light = { THR_T: 'bg-blue-100 text-blue-700', THR_C: 'bg-indigo-100 text-indigo-700', ACC: 'bg-emerald-100 text-emerald-700', DEC: 'bg-purple-100 text-purple-700' };
    return isDark.value ? (dark[f] || 'bg-gray-700 text-gray-300') : (light[f] || 'bg-gray-100 text-gray-600');
};
const statusBadge = (s) => {
    const dark = { hypothesis: 'bg-gray-700 text-gray-300', active: 'bg-green-900 text-green-200', suspended: 'bg-yellow-900 text-yellow-200' };
    const light = { hypothesis: 'bg-gray-100 text-gray-600', active: 'bg-green-100 text-green-700', suspended: 'bg-yellow-100 text-yellow-700' };
    return isDark.value ? (dark[s] || '') : (light[s] || '');
};

const lifecycleBtn = (kind) => {
    const base = 'px-3 py-1 rounded-lg text-xs font-medium transition-colors';
    const map = {
        promote: isDark.value ? 'bg-green-800 hover:bg-green-700 text-green-100' : 'bg-green-100 hover:bg-green-200 text-green-700',
        resume: isDark.value ? 'bg-green-800 hover:bg-green-700 text-green-100' : 'bg-green-100 hover:bg-green-200 text-green-700',
        suspend: isDark.value ? 'bg-yellow-800 hover:bg-yellow-700 text-yellow-100' : 'bg-yellow-100 hover:bg-yellow-200 text-yellow-700',
        revoke: isDark.value ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700',
        delete: isDark.value ? 'bg-red-900 hover:bg-red-800 text-red-200' : 'bg-red-50 hover:bg-red-100 text-red-600',
    };
    return [base, map[kind] || ''];
};

const labelCls = computed(() => ['font-semibold', isDark.value ? 'text-gray-300' : 'text-gray-600']);
const codeCls = computed(() => ['px-1.5 py-0.5 rounded font-mono break-all', isDark.value ? 'bg-gray-900 text-amber-300' : 'bg-gray-100 text-amber-700']);
const fieldLabel = computed(() => ['block text-xs font-medium mb-1', isDark.value ? 'text-gray-400' : 'text-gray-600']);
const fieldInput = computed(() => ['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-amber-500 px-3 py-2 text-sm', isDark.value ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-500' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-400']);

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) isDark.value = true;
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>