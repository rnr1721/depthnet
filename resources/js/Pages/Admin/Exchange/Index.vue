<template>
    <PageTitle :title="t('exchange_import_title')" />
    <div :class="['min-h-screen transition-colors duration-300', isDark ? 'bg-gray-900' : 'bg-gray-50']">
        <AdminHeader :title="t('exchange_import_title')" :isAdmin="true"
            :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div
                    :class="['absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-indigo-500' : 'bg-indigo-300']">
                </div>
                <div
                    :class="['absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl', isDark ? 'bg-violet-500' : 'bg-violet-300']">
                </div>
            </div>

            <div class="relative max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Header -->
                <div class="mb-8">
                    <h2 :class="['text-2xl font-bold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('exchange_import_heading') }}
                    </h2>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('exchange_import_intro') }}
                    </p>
                </div>

                <!-- Step 1: Upload -->
                <div
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex items-center gap-2 mb-4">
                        <span
                            :class="['flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold', isDark ? 'bg-indigo-900 text-indigo-300' : 'bg-indigo-100 text-indigo-700']">1</span>
                        <h3 :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                            t('exchange_step_choose') }}</h3>
                    </div>

                    <!-- Drop zone -->
                    <label :class="[
                        'flex flex-col items-center justify-center w-full h-40 rounded-xl border-2 border-dashed cursor-pointer transition-colors',
                        isDark ? 'border-gray-600 hover:border-indigo-500 bg-gray-900 bg-opacity-40' : 'border-gray-300 hover:border-indigo-400 bg-gray-50'
                    ]">
                        <svg class="w-8 h-8 mb-2" :class="isDark ? 'text-gray-500' : 'text-gray-400'" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12">
                            </path>
                        </svg>
                        <span :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">
                            {{ selectedFile ? selectedFile.name : t('exchange_drop_hint') }}
                        </span>
                        <span :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                            t('exchange_drop_sub') }}</span>
                        <input type="file" accept=".json,application/json" class="hidden" @change="onFileChange" />
                    </label>

                    <div class="mt-4 flex justify-end">
                        <button @click="runPreflight" :disabled="!selectedFile || loading" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all',
                            (!selectedFile || loading)
                                ? (isDark ? 'bg-gray-700 text-gray-500 cursor-not-allowed' : 'bg-gray-200 text-gray-400 cursor-not-allowed')
                                : 'bg-indigo-600 hover:bg-indigo-700 text-white transform hover:scale-105'
                        ]">
                            <svg v-if="loading" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ t('exchange_action_check') }}
                        </button>
                    </div>
                </div>

                <!-- Upload error (parse / network) -->
                <div v-if="uploadError"
                    :class="['mb-6 p-4 rounded-xl border-l-4', isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200' : 'bg-red-50 border-red-400 text-red-800']">
                    <span class="font-medium">{{ uploadError }}</span>
                </div>

                <!-- Step 2: Report -->
                <div v-if="report"
                    :class="['mb-6 backdrop-blur-sm border shadow-xl rounded-2xl p-6', isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200']">
                    <div class="flex items-center gap-2 mb-4">
                        <span
                            :class="['flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold', isDark ? 'bg-indigo-900 text-indigo-300' : 'bg-indigo-100 text-indigo-700']">2</span>
                        <h3 :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                            t('exchange_step_review') }}</h3>
                    </div>

                    <!-- Summary line -->
                    <div :class="['flex flex-wrap gap-4 mb-4 text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">
                        <span>{{ t('exchange_summary_presets', { n: report.preset_count }) }}</span>
                        <span>{{ t('exchange_summary_agents', { n: report.agent_count }) }}</span>
                        <span :class="isDark ? 'text-gray-500' : 'text-gray-400'">format v{{ report.format_version
                        }}</span>
                    </div>

                    <!-- Errors -->
                    <div v-if="report.errors.length" class="mb-4">
                        <h4 :class="['text-sm font-semibold mb-2', isDark ? 'text-red-300' : 'text-red-700']">
                            {{ t('exchange_errors_heading') }}
                        </h4>
                        <ul
                            :class="['rounded-xl p-3 space-y-1 text-sm font-mono', isDark ? 'bg-red-900 bg-opacity-30 text-red-200' : 'bg-red-50 text-red-800']">
                            <li v-for="(err, i) in report.errors" :key="i" class="flex gap-2">
                                <span class="flex-shrink-0">✗</span><span>{{ err }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Warnings -->
                    <div v-if="report.warnings.length" class="mb-4">
                        <h4 :class="['text-sm font-semibold mb-2', isDark ? 'text-amber-300' : 'text-amber-700']">
                            {{ t('exchange_warnings_heading') }}
                        </h4>
                        <ul
                            :class="['rounded-xl p-3 space-y-1 text-sm', isDark ? 'bg-amber-900 bg-opacity-30 text-amber-200' : 'bg-amber-50 text-amber-800']">
                            <li v-for="(w, i) in report.warnings" :key="i" class="flex gap-2">
                                <span class="flex-shrink-0">⚠</span><span>{{ w }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Clean -->
                    <div v-if="report.ok && !report.warnings.length"
                        :class="['mb-4 flex items-center gap-2 text-sm', isDark ? 'text-green-300' : 'text-green-700']">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                clip-rule="evenodd"></path>
                        </svg>
                        {{ t('exchange_ready') }}
                    </div>

                    <!-- Confirm -->
                    <div class="flex justify-end">
                        <button @click="runImport" :disabled="!report.ok || importing" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all',
                            (!report.ok || importing)
                                ? (isDark ? 'bg-gray-700 text-gray-500 cursor-not-allowed' : 'bg-gray-200 text-gray-400 cursor-not-allowed')
                                : 'bg-green-600 hover:bg-green-700 text-white transform hover:scale-105'
                        ]">
                            <svg v-if="importing" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ report.warnings.length ? t('exchange_action_import_anyway') : t('exchange_action_import')
                            }}
                        </button>
                    </div>
                </div>

                <!-- Step 3: Result -->
                <div v-if="result"
                    :class="['backdrop-blur-sm border shadow-xl rounded-2xl p-6', isDark ? 'bg-green-900 bg-opacity-30 border-green-700' : 'bg-green-50 border-green-300']">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-6 h-6" :class="isDark ? 'text-green-400' : 'text-green-600'" fill="currentColor"
                            viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                clip-rule="evenodd"></path>
                        </svg>
                        <h3 :class="['font-semibold', isDark ? 'text-green-200' : 'text-green-800']">
                            {{ t('exchange_done_heading') }}
                        </h3>
                    </div>
                    <p :class="['text-sm mb-4', isDark ? 'text-green-300' : 'text-green-700']">
                        {{ t('exchange_done_summary', { presets: result.preset_count, agents: result.agent_count }) }}
                    </p>
                    <p :class="['text-sm mb-4', isDark ? 'text-green-300' : 'text-green-700']">
                        {{ t('exchange_done_keys_reminder') }}
                    </p>
                    <Link :href="route('admin.presets.index')"
                        :class="['inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all', isDark ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-800 hover:bg-gray-900 text-white']">
                        {{ t('exchange_go_to_presets') }}
                    </Link>
                </div>

            </div>
        </main>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();
const isDark = ref(false);

const selectedFile = ref(null);
const loading = ref(false);       // preflight in flight
const importing = ref(false);     // import in flight
const uploadError = ref(null);
const report = ref(null);
const bundle = ref(null);         // parsed bundle echoed back from preflight
const result = ref(null);

const onFileChange = (e) => {
    const file = e.target.files?.[0] ?? null;
    selectedFile.value = file;
    // reset downstream state when a new file is picked
    report.value = null;
    result.value = null;
    uploadError.value = null;
    bundle.value = null;
};

const runPreflight = async () => {
    if (!selectedFile.value) return;
    loading.value = true;
    uploadError.value = null;
    report.value = null;
    result.value = null;

    try {
        const form = new FormData();
        form.append('bundle', selectedFile.value);

        const { data: json } = await axios.post(
            route('admin.exchange.import.preflight'),
            form,
            { headers: { 'Content-Type': 'multipart/form-data' } }
        );

        if (!json.success) {
            uploadError.value = json.message || t('exchange_error_generic');
            return;
        }

        report.value = json.data.report;
        bundle.value = json.data.bundle;
    } catch (err) {
        uploadError.value = err.response?.data?.message || t('exchange_error_network');
    } finally {
        loading.value = false;
    }
};

const runImport = async () => {
    if (!report.value?.ok || !bundle.value) return;
    importing.value = true;
    uploadError.value = null;

    try {
        const { data: json } = await axios.post(
            route('admin.exchange.import'),
            { bundle: bundle.value }
        );

        if (!json.success) {
            uploadError.value = (json.errors && json.errors.length)
                ? json.errors.join(' · ')
                : (json.message || t('exchange_error_generic'));
            report.value = null;
            return;
        }

        result.value = json.data;
        report.value = null;
    } catch (err) {
        // 422 с errors[] прилетит сюда — достаём из response
        const data = err.response?.data;
        uploadError.value = (data?.errors && data.errors.length)
            ? data.errors.join(' · ')
            : (data?.message || t('exchange_error_network'));
        report.value = null;
    } finally {
        importing.value = false;
    }
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