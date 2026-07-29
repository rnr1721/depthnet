<template>
    <PageTitle :title="t('behavior_manager')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('behavior_manager')" :isAdmin="true" :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-violet-500' : 'bg-violet-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-fuchsia-500' : 'bg-fuchsia-300']">
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
                                :class="['w-full lg:w-64 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-4 py-3', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
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
                                    t('behavior_total') }}</div>
                            </div>
                            <div v-for="(count, st) in stats.by_status" :key="st" class="text-center">
                                <div :class="['text-2xl font-bold', statusColor(st)]">{{ count }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ st }}</div>
                            </div>
                            <div class="text-center">
                                <div :class="['text-2xl font-bold', isDark ? 'text-violet-300' : 'text-violet-600']">{{
                                    cycleSeq }}</div>
                                <div :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                                    t('behavior_cycle') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all p-4', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div
                        class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0 gap-4">
                        <div class="flex gap-3 flex-wrap">
                            <select v-model="localStatus" @change="applyFilters"
                                :class="['rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']">
                                <option value="">{{ t('behavior_all_statuses') }}</option>
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button @click="decayNow"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700']"
                                :title="t('behavior_decay_hint')">
                                {{ t('behavior_decay_now') }}
                            </button>
                            <button @click="openAdd"
                                :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-violet-500 bg-violet-600 hover:bg-violet-700 text-white']">
                                {{ t('behavior_add') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Patterns List -->
                <div v-if="patterns.length" class="space-y-3">
                    <div v-for="p in patterns" :key="p.name"
                        :class="['backdrop-blur-sm border shadow rounded-2xl overflow-hidden transition-all', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700 hover:border-gray-600' : 'bg-white bg-opacity-90 border-gray-200 hover:border-gray-300']">
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3 flex-1 min-w-0">
                                    <span
                                        :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-medium flex-shrink-0 mt-0.5', kindBadge(p.trigger && p.trigger.kind)]">{{
                                            p.trigger && p.trigger.kind }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p
                                                :class="['text-sm font-semibold break-words', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ p.name }}</p>
                                            <span v-if="p.immune"
                                                :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide', isDark ? 'bg-violet-900 text-violet-200' : 'bg-violet-100 text-violet-700']">immune</span>
                                        </div>
                                        <p
                                            :class="['mt-1 text-xs break-words font-mono', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            {{ describeTrigger(p.trigger) }}</p>
                                        <p v-if="p.lever"
                                            :class="['mt-1 text-xs break-words font-mono flex items-center gap-1', isDark ? 'text-fuchsia-300' : 'text-fuchsia-600']">
                                            <span
                                                :class="['inline-block px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide', isDark ? 'bg-fuchsia-900 text-fuchsia-200' : 'bg-fuchsia-100 text-fuchsia-700']">lever</span>
                                            {{ describeLever(p.lever) }}
                                        </p>
                                        <p v-if="p.intent"
                                            :class="['mt-1 text-xs break-words italic', isDark ? 'text-gray-500' : 'text-gray-400']">
                                            {{ p.intent }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <!-- Fitness pill — the state of selection, right in the card -->
                                    <div class="text-right">
                                        <div :class="['text-sm font-bold tabular-nums', fitnessColor(p.fitness)]">{{
                                            p.fitness.toFixed(2) }}</div>
                                        <div :class="['text-[10px]', isDark ? 'text-gray-500' : 'text-gray-400']">
                                            fitness</div>
                                    </div>
                                    <span
                                        :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', statusBadge(p.status)]">{{
                                            p.status }}</span>
                                    <button @click="toggleExpand(p.name)"
                                        :class="['p-1.5 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-violet-400 hover:bg-gray-700' : 'text-gray-400 hover:text-violet-500 hover:bg-gray-100']"
                                        :title="t('behavior_details')">
                                        <svg class="w-4 h-4 transition-transform"
                                            :class="{ 'rotate-180': expanded === p.name }" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Mini stats row -->
                            <div
                                :class="['mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-mono', isDark ? 'text-gray-500' : 'text-gray-400']">
                                <span>priority {{ p.priority }}</span>
                                <span>activations {{ p.activation_count }}</span>
                                <span v-if="p.idle_for !== null">idle {{ p.idle_for }} cyc</span>
                                <span v-if="p.immune && p.forced_activation_interval">quota /{{
                                    p.forced_activation_interval }}</span>
                                <span>{{ p.provenance }}</span>
                            </div>

                            <!-- Lifecycle buttons -->
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button v-if="p.status === 'hypothesis'" @click="lifecycle(p, 'promote')"
                                    :class="lifecycleBtn('promote')">{{ t('behavior_promote') }}</button>
                                <button v-if="p.status === 'active'" @click="lifecycle(p, 'retire')"
                                    :class="lifecycleBtn('retire')">{{ t('behavior_retire') }}</button>
                                <button v-if="p.status === 'retired'" @click="lifecycle(p, 'promote')"
                                    :class="lifecycleBtn('promote')">{{ t('behavior_reactivate') }}</button>
                                <button v-if="p.status !== 'hypothesis'" @click="lifecycle(p, 'revoke')"
                                    :class="lifecycleBtn('revoke')">{{ t('behavior_revoke') }}</button>
                                <button @click="deletePattern(p)" :class="lifecycleBtn('delete')">{{
                                    t('behavior_delete') }}</button>
                            </div>

                            <!-- Expanded details -->
                            <Transition enter-active-class="transition ease-out duration-200"
                                enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0">
                                <div v-if="expanded === p.name"
                                    :class="['mt-4 pt-4 border-t text-xs space-y-2', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <div><span :class="labelCls">trigger:</span> <code
                                            :class="codeCls">{{ JSON.stringify(p.trigger) }}</code></div>
                                    <div v-if="p.behavior"><span :class="labelCls">behavior:</span> <code
                                            :class="codeCls">{{ JSON.stringify(p.behavior) }}</code></div>
                                    <div v-if="p.constraints"><span :class="labelCls">constraints:</span> <code
                                            :class="codeCls">{{ JSON.stringify(p.constraints) }}</code></div>
                                    <div v-if="p.lever"><span :class="labelCls">lever:</span> <code
                                            :class="codeCls">{{ JSON.stringify(p.lever) }}</code></div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div><span :class="labelCls">confidence:</span> <span class="font-mono">{{
                                            p.confidence }}</span></div>
                                        <div><span :class="labelCls">plasticity:</span> <span class="font-mono">{{
                                            p.plasticity }}</span></div>
                                        <div><span :class="labelCls">last activation seq:</span> <span
                                                class="font-mono">{{ p.last_activation_seq ?? '—' }}</span></div>
                                        <div v-if="p.immune"><span :class="labelCls">quota interval:</span> <span
                                                class="font-mono">{{ p.forced_activation_interval ?? '—' }}</span></div>
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
                        class="w-16 h-16 mx-auto mb-4 bg-gradient-to-br from-violet-400 to-fuchsia-500 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10v10H7z" />
                        </svg>
                    </div>
                    <h3 :class="['text-lg font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">{{
                        t('behavior_empty') }}</h3>
                    <p :class="['text-sm mb-6', isDark ? 'text-gray-400' : 'text-gray-600']">{{
                        t('behavior_empty_description') }}</p>
                    <button @click="openAdd"
                        :class="['px-6 py-3 rounded-xl font-medium transition-all bg-violet-600 hover:bg-violet-700 text-white']">{{
                            t('behavior_add_first') }}</button>
                </div>
            </div>
        </main>

        <!-- Add Pattern Modal -->
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
                            t('behavior_add') }}</h3>
                        <p :class="['text-xs mb-4', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                            t('behavior_add_hint') }}</p>

                        <!-- Mode toggle: guided / raw JSON -->
                        <div class="flex gap-1 mb-4 p-1 rounded-xl" :class="isDark ? 'bg-gray-900' : 'bg-gray-100'">
                            <button @click="rawMode = false"
                                :class="['flex-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', !rawMode ? (isDark ? 'bg-gray-700 text-white' : 'bg-white text-gray-900 shadow') : (isDark ? 'text-gray-400' : 'text-gray-500')]">{{
                                    t('behavior_guided') }}</button>
                            <button @click="rawMode = true"
                                :class="['flex-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-all', rawMode ? (isDark ? 'bg-gray-700 text-white' : 'bg-white text-gray-900 shadow') : (isDark ? 'text-gray-400' : 'text-gray-500')]">{{
                                    t('behavior_raw_json') }}</button>
                        </div>

                        <!-- Guided form -->
                        <div v-if="!rawMode" class="space-y-3">
                            <div>
                                <label :class="fieldLabel">{{ t('behavior_name') }}</label>
                                <input v-model="form.name" :class="fieldInput" placeholder="deepen_focus" />
                            </div>

                            <div>
                                <label :class="fieldLabel">{{ t('behavior_intent') }}</label>
                                <input v-model="form.intent" :class="fieldInput"
                                    placeholder="Stay with the current thread; go deeper, not wider." />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="fieldLabel">{{ t('behavior_kind') }}</label>
                                    <select v-model="form.kind" :class="fieldInput">
                                        <option v-for="k in kinds" :key="k" :value="k">{{ k }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="fieldLabel">priority</label>
                                    <input v-model.number="form.priority" type="number" step="0.1" :class="fieldInput"
                                        placeholder="1.0" />
                                </div>
                            </div>

                            <!-- mood trigger -->
                            <template v-if="form.kind === 'mood'">
                                <div class="grid grid-cols-3 gap-3">
                                    <div><label :class="fieldLabel">target</label><input v-model="form.mood_target"
                                            :class="fieldInput" placeholder="focus" /></div>
                                    <div>
                                        <label :class="fieldLabel">op</label>
                                        <select v-model="form.mood_op" :class="fieldInput">
                                            <option v-for="o in moodOps" :key="o" :value="o">{{ o }}</option>
                                        </select>
                                    </div>
                                    <div><label :class="fieldLabel">value</label><input v-model.number="form.mood_value"
                                            type="number" step="0.01" :class="fieldInput" placeholder="0.5" /></div>
                                </div>
                            </template>

                            <!-- pulse trigger -->
                            <template v-if="form.kind === 'pulse'">
                                <div class="grid grid-cols-2 gap-3">
                                    <div><label :class="fieldLabel">from</label><input v-model.number="form.pulse_from"
                                            type="number" :class="fieldInput" placeholder="0" /></div>
                                    <div><label :class="fieldLabel">to</label><input v-model.number="form.pulse_to"
                                            type="number" :class="fieldInput" placeholder="200" /></div>
                                </div>
                                <p :class="['text-[11px]', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                    t('behavior_pulse_hint') }}</p>
                            </template>

                            <!-- behavior hint -->
                            <div>
                                <label :class="fieldLabel">{{ t('behavior_hint_label') }}</label>
                                <input v-model="form.behavior_hint" :class="fieldInput"
                                    placeholder="Stay with the current thread. Resist switching." />
                            </div>

                            <!-- lever (phase 2a) -->
                            <div :class="['pt-3 mt-1 border-t', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <div class="flex items-center justify-between mb-1">
                                    <label :class="fieldLabel">{{ t('behavior_lever_label') }}</label>
                                    <button v-if="form.lever_dimension" type="button" @click="clearLever"
                                        :class="['text-[11px] underline', isDark ? 'text-gray-500 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600']">
                                        {{ t('behavior_lever_clear') }}
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label :class="fieldLabel">dimension</label>
                                        <select v-model="form.lever_dimension" :class="fieldInput">
                                            <option value="">{{ t('behavior_lever_none') }}</option>
                                            <option v-for="d in dimensions" :key="d" :value="d">{{ d }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="fieldLabel">delta</label>
                                        <input v-model.number="form.lever_delta" type="number" step="0.05" min="-0.5"
                                            max="0.5" :class="fieldInput" placeholder="0.15"
                                            :disabled="!form.lever_dimension" />
                                    </div>
                                </div>
                                <p :class="['mt-1 text-[11px]', isDark ? 'text-gray-500' : 'text-gray-400']">
                                    {{ t('behavior_lever_hint') }}
                                </p>
                            </div>

                            <!-- immune + quota -->
                            <div class="grid grid-cols-2 gap-3">
                                <div class="flex items-end pb-2">
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.immune"
                                            class="rounded text-violet-600 focus:ring-violet-500" />
                                        <span
                                            :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">immune</span>
                                    </label>
                                </div>
                                <div v-if="form.immune">
                                    <label :class="fieldLabel">{{ t('behavior_quota') }}</label>
                                    <input v-model.number="form.forced_activation_interval" type="number"
                                        :class="fieldInput" placeholder="5" />
                                </div>
                            </div>

                            <div class="flex items-end pb-2">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" v-model="form.promote"
                                        class="rounded text-violet-600 focus:ring-violet-500" />
                                    <span :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">{{
                                        t('behavior_create_active') }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Raw JSON -->
                        <div v-else>
                            <label :class="fieldLabel">{{ t('behavior_raw_json') }}</label>
                            <textarea v-model="rawJson" rows="12" :class="[fieldInput, 'font-mono text-xs resize-none']"
                                :placeholder="rawPlaceholder"></textarea>
                            <p v-if="rawError" :class="['mt-1 text-xs', isDark ? 'text-red-400' : 'text-red-600']">{{
                                rawError }}</p>
                        </div>

                        <div class="flex justify-end gap-3 mt-5">
                            <button @click="showAddModal = false"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                    t('cancel') }}</button>
                            <button @click="submitPattern"
                                :class="['px-4 py-2 rounded-xl text-sm font-medium text-white bg-violet-600 hover:bg-violet-700']">{{
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
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();

const props = defineProps({
    presets: Array,
    currentPreset: Object,
    patterns: Array,
    stats: Object,
    cycleSeq: Number,
    filterStatus: String,
    kinds: Array,
    statuses: Array,
    dimensions: { type: Array, default: () => [] },   // phase 2a: lever select source
});

const isDark = ref(false);
const selectedPresetId = ref(props.currentPreset?.id);
const localStatus = ref(props.filterStatus || '');
const showAddModal = ref(false);
const expanded = ref(null);
const rawMode = ref(false);
const rawJson = ref('');
const rawError = ref('');

const moodOps = ['>', '>=', '<', '<=', '==', '!='];

const blankForm = () => ({
    name: '', intent: '', kind: 'mood', priority: 1.0,
    mood_target: '', mood_op: '>', mood_value: null,
    pulse_from: null, pulse_to: null,
    behavior_hint: '',
    // phase 2a — lever (optional). Empty dimension = no lever (phase-1 pattern).
    lever_dimension: '', lever_delta: null,
    immune: false, forced_activation_interval: null,
    promote: false,
});
const form = ref(blankForm());

const rawPlaceholder = `{
  "name": "deepen_focus",
  "trigger": { "kind": "mood", "target": "focus", "op": ">", "value": 0.5 },
  "intent": "Stay with the current thread; go deeper, not wider.",
  "behavior": { "hint": "Resist switching." },
  "priority": 1.0
}`;

const changePreset = () => router.get(route('admin.behavior.index'), { preset_id: selectedPresetId.value });
const applyFilters = () => router.get(route('admin.behavior.index'), { preset_id: selectedPresetId.value, status: localStatus.value });

const openAdd = () => { form.value = blankForm(); rawJson.value = ''; rawError.value = ''; rawMode.value = false; showAddModal.value = true; };
const toggleExpand = (name) => { expanded.value = expanded.value === name ? null : name; };

const buildDefinition = () => {
    const f = form.value;
    const trigger = { kind: f.kind };
    if (f.kind === 'mood') {
        trigger.target = f.mood_target;
        trigger.op = f.mood_op;
        trigger.value = f.mood_value;
    } else if (f.kind === 'pulse') {
        trigger.from = f.pulse_from;
        trigger.to = f.pulse_to;
    }

    const def = { name: f.name, trigger, priority: f.priority };
    if (f.intent) def.intent = f.intent;
    if (f.behavior_hint) def.behavior = { hint: f.behavior_hint };

    // phase 2a — lever, only when a dimension is chosen AND a delta is given.
    // A dimension without a delta is an incomplete lever → omit (no half-lever).
    if (f.lever_dimension && f.lever_delta !== null && f.lever_delta !== '' && Number(f.lever_delta) !== 0) {
        def.lever = { dimension: f.lever_dimension, delta: Number(f.lever_delta) };
    }

    if (f.immune) {
        def.immune = true;
        if (f.forced_activation_interval) def.forced_activation_interval = f.forced_activation_interval;
    }
    if (f.promote) def.status = 'active';
    return def;
};

const clearLever = () => {
    form.value.lever_dimension = '';
    form.value.lever_delta = null;
};

const describeLever = (lv) => {
    if (!lv || !lv.dimension) return '';
    const sign = lv.delta > 0 ? '+' : '';
    return `${lv.dimension} ${sign}${lv.delta}`;
};

const submitPattern = () => {
    let payload;
    if (rawMode.value) {
        try {
            payload = JSON.parse(rawJson.value);
            rawError.value = '';
        } catch (e) {
            rawError.value = t('behavior_invalid_json') + ' ' + e.message;
            return;
        }
    } else {
        payload = buildDefinition();
    }

    router.post(route('admin.behavior.store'), { preset_id: selectedPresetId.value, ...payload }, {
        onSuccess: () => { showAddModal.value = false; },
    });
};

const lifecycle = (p, action) => {
    router.post(route(`admin.behavior.${action}`, p.name), { preset_id: selectedPresetId.value });
};

const deletePattern = (p) => {
    if (confirm(t('behavior_confirm_delete', { name: p.name }))) {
        router.delete(route('admin.behavior.destroy', p.name), { data: { preset_id: selectedPresetId.value } });
    }
};

const decayNow = () => {
    router.post(route('admin.behavior.decay'), { preset_id: selectedPresetId.value });
};

// ── Display helpers ──────────────────────────────────────────────────────────

const describeTrigger = (tr) => {
    if (!tr) return '';
    if (tr.kind === 'mood') return `mood.${tr.target} ${tr.op} ${tr.value}`;
    if (tr.kind === 'pulse') return `pulse in [${tr.from}..${tr.to}]`;
    return JSON.stringify(tr);
};

const statusColor = (s) => ({ hypothesis: 'text-gray-400', active: 'text-green-400', retired: 'text-yellow-400' }[s] || 'text-gray-400');

const kindBadge = (k) => {
    const dark = { mood: 'bg-fuchsia-900 text-fuchsia-200', pulse: 'bg-cyan-900 text-cyan-200' };
    const light = { mood: 'bg-fuchsia-100 text-fuchsia-700', pulse: 'bg-cyan-100 text-cyan-700' };
    return isDark.value ? (dark[k] || 'bg-gray-700 text-gray-300') : (light[k] || 'bg-gray-100 text-gray-600');
};

const statusBadge = (s) => {
    const dark = { hypothesis: 'bg-gray-700 text-gray-300', active: 'bg-green-900 text-green-200', retired: 'bg-yellow-900 text-yellow-200' };
    const light = { hypothesis: 'bg-gray-100 text-gray-600', active: 'bg-green-100 text-green-700', retired: 'bg-yellow-100 text-yellow-700' };
    return isDark.value ? (dark[s] || '') : (light[s] || '');
};

const fitnessColor = (v) => {
    if (v > 0.01) return isDark.value ? 'text-emerald-300' : 'text-emerald-600';
    if (v < -0.01) return isDark.value ? 'text-rose-300' : 'text-rose-600';
    return isDark.value ? 'text-gray-400' : 'text-gray-500';
};

const lifecycleBtn = (kind) => {
    const base = 'px-3 py-1 rounded-lg text-xs font-medium transition-colors';
    const map = {
        promote: isDark.value ? 'bg-green-800 hover:bg-green-700 text-green-100' : 'bg-green-100 hover:bg-green-200 text-green-700',
        retire: isDark.value ? 'bg-yellow-800 hover:bg-yellow-700 text-yellow-100' : 'bg-yellow-100 hover:bg-yellow-200 text-yellow-700',
        revoke: isDark.value ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700',
        delete: isDark.value ? 'bg-red-900 hover:bg-red-800 text-red-200' : 'bg-red-50 hover:bg-red-100 text-red-600',
    };
    return [base, map[kind] || ''];
};

const labelCls = computed(() => ['font-semibold', isDark.value ? 'text-gray-300' : 'text-gray-600']);
const codeCls = computed(() => ['px-1.5 py-0.5 rounded font-mono break-all', isDark.value ? 'bg-gray-900 text-violet-300' : 'bg-gray-100 text-violet-700']);
const fieldLabel = computed(() => ['block text-xs font-medium mb-1', isDark.value ? 'text-gray-400' : 'text-gray-600']);
const fieldInput = computed(() => ['w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 px-3 py-2 text-sm', isDark.value ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-500' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-400']);

onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) isDark.value = true;
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>
