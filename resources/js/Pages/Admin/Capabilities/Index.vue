<template>
    <PageTitle :title="t('capabilities_page_title')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('capabilities_management')" :isAdmin="true"
            :sandbox-enabled="$page.props.sandboxEnabled" />

        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-indigo-500' : 'bg-indigo-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-blue-500' : 'bg-blue-300'
                ]"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

                <!-- Error message -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="errorMessage" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200'
                            : 'bg-red-50 border-red-400 text-red-800'
                    ]">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ errorMessage }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Preset Selector -->
                <div v-if="availablePresets.length > 1" :class="[
                    'mb-6 p-4 rounded-xl border backdrop-blur-sm',
                    isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <div class="flex items-center justify-between">
                        <h3 :class="['text-base font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('capabilities_current_preset') }}
                        </h3>
                        <select v-model="selectedPresetId" @change="changePreset" :class="[
                            'px-4 py-2 rounded-lg border text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500',
                            isDark ? 'bg-gray-700 border-gray-600 text-white'
                                : 'bg-white border-gray-300 text-gray-900'
                        ]">
                            <option v-for="p in availablePresets" :key="p.id" :value="p.id">
                                {{ p.name }} ({{ p.engine_name }})
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Current Preset Info -->
                <div v-if="currentPreset" :class="[
                    'mb-6 p-4 rounded-xl border backdrop-blur-sm',
                    isDark ? 'bg-indigo-900 bg-opacity-30 border-indigo-700'
                        : 'bg-indigo-50 bg-opacity-90 border-indigo-200'
                ]">
                    <div class="flex items-center">
                        <div
                            class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center mr-4">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                            </svg>
                        </div>
                        <div>
                            <h3 :class="['text-base font-semibold', isDark ? 'text-white' : 'text-indigo-900']">
                                {{ currentPreset.name }}
                            </h3>
                            <p :class="['text-xs mt-0.5', isDark ? 'text-indigo-300' : 'text-indigo-600']">
                                {{ currentPreset.description || t('capabilities_no_description') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="mb-8">
                    <h2 :class="['text-2xl font-bold mb-1', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('capabilities_title') }}
                    </h2>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('capabilities_description') }}
                    </p>
                </div>

                <!-- Capability Cards -->
                <div v-if="loading" class="flex justify-center py-16">
                    <svg class="animate-spin h-8 w-8 text-indigo-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                </div>

                <div v-else class="space-y-6">
                    <CapabilityCard v-for="cap in capabilitiesList" :key="cap.capability" :capability="cap"
                        :is-dark="isDark" @save="handleSave" @test="handleTest" />

                    <div v-if="capabilitiesList.length === 0" :class="[
                        'text-center py-16 rounded-xl border',
                        isDark ? 'bg-gray-800 border-gray-700 text-gray-400'
                            : 'bg-white border-gray-200 text-gray-500'
                    ]">
                        {{ t('capabilities_none_available') }}
                    </div>
                </div>

            </div>
        </main>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import CapabilityCard from '@/Components/Admin/Capabilities/CapabilityCard.vue';

const { t } = useI18n();

const props = defineProps({
    capabilities: { type: Object, default: () => ({}) },
    current_preset: { type: Object, default: null },
    available_presets: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const isDark = ref(false);
const loading = ref(false);
const errorMessage = ref(props.error || null);
const capabilities = ref(props.capabilities);
const currentPreset = ref(props.current_preset);
const availablePresets = ref(props.available_presets);
const selectedPresetId = ref(props.current_preset?.id ?? null);

const capabilitiesList = computed(() => Object.values(capabilities.value));

// ── Preset switch ─────────────────────────────────────────────────────────────
const changePreset = async () => {
    if (!selectedPresetId.value) return;

    loading.value = true;
    errorMessage.value = null;

    try {
        const response = await axios.get(route('admin.capabilities.show', { presetId: selectedPresetId.value }));

        if (response.data.success) {
            capabilities.value = response.data.capabilities;
            currentPreset.value = response.data.current_preset;
        }
    } catch (e) {
        errorMessage.value = 'Failed to load preset capabilities.';
    } finally {
        loading.value = false;
    }
};

// ── Save ──────────────────────────────────────────────────────────────────────
const handleSave = async ({ capability, driver, config, isActive }) => {
    try {
        const response = await axios.put(
            route('admin.capabilities.update', {
                presetId: currentPreset.value.id,
                capability,
            }),
            { driver, config, is_active: isActive }
        );

        if (response.data.success) {
            // Refresh the capability entry from server to get updated masked config
            const refreshed = await axios.get(
                route('admin.capabilities.show', { presetId: currentPreset.value.id })
            );
            if (refreshed.data.success) {
                capabilities.value = refreshed.data.capabilities;
            }
        }

        return response.data;

    } catch (e) {
        if (e.response?.status === 422) {
            return {
                success: false,
                errors: e.response.data.errors ?? {},
                message: e.response.data.message ?? 'Validation failed.',
            };
        }
        errorMessage.value = 'Failed to save configuration.';
        return { success: false, errors: {}, message: 'Server error.' };
    }
};

// ── Test ──────────────────────────────────────────────────────────────────────
const handleTest = async (capability) => {
    try {
        const response = await axios.post(
            route('admin.capabilities.test', {
                presetId: currentPreset.value.id,
                capability,
            })
        );
        return response.data;
    } catch (e) {
        return {
            success: false,
            message: e.response?.data?.message ?? 'Test request failed.',
            latency_ms: 0,
        };
    }
};

// ── Theme ─────────────────────────────────────────────────────────────────────
onMounted(() => {
    const saved = localStorage.getItem('chat-theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
    window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
});
</script>