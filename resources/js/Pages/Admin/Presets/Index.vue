<template>
    <PageTitle :title="t('presets_management')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('presets_ai_presets')" :isAdmin="true" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-purple-500' : 'bg-purple-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-indigo-500' : 'bg-indigo-300'
                ]"></div>
            </div>

            <div class="relative max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Success/Error messages -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark
                            ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200'
                            : 'bg-green-50 border-green-400 text-green-800'
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

                <!-- Header with actions -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
                    <div>
                        <h2 :class="[
                            'text-2xl font-bold mb-2',
                            isDark ? 'text-white' : 'text-gray-900'
                        ]">{{ t('presets_manage_presets') }}</h2>
                        <p :class="[
                            'text-sm',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">{{ t('presets_manage_description') }}</p>
                    </div>

                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
                        <!-- Create Preset Button -->
                        <button @click="showCreateModal = true" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                            isDark
                                ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-indigo-500 focus:ring-offset-gray-900'
                                : 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-indigo-500'
                        ]">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v16m8-8H4"></path>
                            </svg>
                            {{ t('presets_create_preset') }}
                        </button>

                        <!-- Route Link -->
                        <Link :href="route('admin.engines.index')" :class="[
                            'inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
                            isDark
                                ? 'bg-gray-700 hover:bg-gray-800 text-white focus:ring-gray-500 focus:ring-offset-gray-900'
                                : 'bg-gray-200 hover:bg-gray-300 text-gray-800 focus:ring-gray-400'
                        ]">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                            </path>
                        </svg>
                        {{ t('p_modal_engines') }}
                        </Link>
                    </div>
                </div>

                <!-- Presets Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="preset in presets" :key="preset.id" :class="[
                        'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all hover:shadow-2xl',
                        preset.is_default
                            ? (isDark ? 'bg-indigo-900 bg-opacity-50 border-indigo-500' : 'bg-indigo-50 border-indigo-300')
                            : (isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200')
                    ]">
                        <!-- Preset Header -->
                        <div :class="[
                            'px-6 py-4 border-b',
                            preset.is_default
                                ? (isDark ? 'border-indigo-700' : 'border-indigo-200')
                                : (isDark ? 'border-gray-700' : 'border-gray-200')
                        ]">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <h3 :class="[
                                            'font-bold text-lg',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">{{ preset.name }}</h3>
                                        <span v-if="preset.is_default" :class="[
                                            'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                                            isDark
                                                ? 'bg-indigo-600 text-indigo-200'
                                                : 'bg-indigo-100 text-indigo-800'
                                        ]">
                                            {{ t('presets_default') }}
                                        </span>
                                        <span :class="[
                                            'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                                            preset.is_active
                                                ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                                : (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                                        ]">
                                            {{ preset.is_active ? t('presets_active') : t('presets_inactive') }}
                                        </span>
                                    </div>
                                    <p :class="[
                                        'text-sm mb-3',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">{{ preset.description || t('presets_no_description') }}</p>
                                    <div class="flex items-center space-x-2">
                                        <span :class="[
                                            'inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium',
                                            getEngineColor(preset.engine_name)
                                        ]">
                                            {{ getEngineDisplayName(preset.engine_name) }}
                                        </span>
                                        <span :class="[
                                            'text-xs',
                                            isDark ? 'text-gray-500' : 'text-gray-400'
                                        ]">{{ preset.engine_config?.model || t('presets_default_model') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Preset Actions -->
                        <div class="px-6 py-4">
                            <div class="flex items-center justify-between space-x-2">
                                <div class="flex space-x-2">
                                    <button @click="editPreset(preset)" :class="[
                                        'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                        isDark
                                            ? 'bg-blue-900 bg-opacity-50 text-blue-200 hover:bg-opacity-70'
                                            : 'bg-blue-100 text-blue-800 hover:bg-blue-200'
                                    ]">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                        {{ t('presets_edit') }}
                                    </button>
                                    <button @click="duplicatePreset(preset)" :class="[
                                        'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                        isDark
                                            ? 'bg-purple-900 bg-opacity-50 text-purple-200 hover:bg-opacity-70'
                                            : 'bg-purple-100 text-purple-800 hover:bg-purple-200'
                                    ]">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z">
                                            </path>
                                        </svg>
                                        {{ t('presets_duplicate') }}
                                    </button>
                                </div>
                                <div class="flex space-x-2">
                                    <button v-if="!preset.is_default" @click="setAsDefault(preset)" :class="[
                                        'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                        isDark
                                            ? 'bg-green-900 bg-opacity-50 text-green-200 hover:bg-opacity-70'
                                            : 'bg-green-100 text-green-800 hover:bg-green-200'
                                    ]">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        {{ t('presets_set_default') }}
                                    </button>
                                    <button @click="deletePreset(preset)" :class="[
                                        'inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium transition-all hover:scale-105',
                                        isDark
                                            ? 'bg-red-900 bg-opacity-50 text-red-200 hover:bg-opacity-70'
                                            : 'bg-red-100 text-red-800 hover:bg-red-200'
                                    ]" :disabled="preset.is_default">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                            </path>
                                        </svg>
                                        {{ t('presets_delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="presets.length === 0" :class="[
                    'text-center py-16 backdrop-blur-sm border rounded-2xl',
                    isDark ? 'bg-gray-800 bg-opacity-50 border-gray-700' : 'bg-white bg-opacity-50 border-gray-200'
                ]">
                    <div
                        class="w-16 h-16 bg-gradient-to-br from-purple-500 to-indigo-600 rounded-2xl mx-auto mb-4 flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z">
                            </path>
                        </svg>
                    </div>
                    <h3 :class="[
                        'text-lg font-semibold mb-2',
                        isDark ? 'text-white' : 'text-gray-900'
                    ]">{{ t('presets_no_presets') }}</h3>
                    <p :class="[
                        'text-sm mb-6',
                        isDark ? 'text-gray-400' : 'text-gray-600'
                    ]">{{ t('presets_create_first') }}</p>
                    <button @click="showCreateModal = true" :class="[
                        'inline-flex items-center px-6 py-3 rounded-xl text-sm font-medium transition-all transform hover:scale-105',
                        isDark
                            ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                            : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                    ]">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4">
                            </path>
                        </svg>
                        {{ t('presets_create_preset') }}
                    </button>
                </div>
            </div>
        </main>

        <!-- Create/Edit Modal -->
        <PresetModal v-if="showCreateModal || editingPreset" :placeholders="placeholders" :preset="editingPreset" :engines="engines"
            @close="closeModal" @save="savePreset" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';
import PresetModal from '@/Components/Admin/Presets/PresetModal.vue';

const { t } = useI18n();

const isDark = ref(false);
const showCreateModal = ref(false);
const editingPreset = ref(null);

const props = defineProps({
    presets: Array,
    engines: Object,
    placeholders: Object
});

// Engine colors mapping based on engines data
const engineColors = computed(() => {
    const colors = {};
    Object.keys(props.engines).forEach(engineName => {
        switch (engineName) {
            case 'mock':
                colors[engineName] = isDark.value
                    ? 'bg-gray-700 text-gray-300'
                    : 'bg-gray-100 text-gray-800';
                break;
            case 'openai':
                colors[engineName] = isDark.value
                    ? 'bg-green-700 text-green-300'
                    : 'bg-green-100 text-green-800';
                break;
            case 'claude':
                colors[engineName] = isDark.value
                    ? 'bg-purple-700 text-purple-300'
                    : 'bg-purple-100 text-purple-800';
                break;
            case 'local':
                colors[engineName] = isDark.value
                    ? 'bg-blue-700 text-blue-300'
                    : 'bg-blue-100 text-blue-800';
                break;
            default:
                colors[engineName] = isDark.value
                    ? 'bg-gray-700 text-gray-300'
                    : 'bg-gray-100 text-gray-800';
        }
    });
    return colors;
});

const getEngineColor = (engineName) => {
    return engineColors.value[engineName] || (isDark.value
        ? 'bg-gray-700 text-gray-300'
        : 'bg-gray-100 text-gray-800');
};

const getEngineDisplayName = (engineName) => {
    // Use display name from engines data if available
    if (props.engines[engineName]?.display_name) {
        return props.engines[engineName].display_name;
    }

    // Fallback to formatted engine name
    return engineName.charAt(0).toUpperCase() + engineName.slice(1);
};

const editPreset = (preset) => {
    editingPreset.value = preset;
};

const duplicatePreset = (preset) => {
    router.get(route('admin.presets.duplicate', preset.id));
};

const setAsDefault = (preset) => {
    router.post(route('admin.presets.set-default', preset.id));
};

const deletePreset = (preset) => {
    if (confirm(t('presets_confirm_delete', { name: preset.name }))) {
        router.delete(route('admin.presets.destroy', preset.id));
    }
};

const closeModal = () => {
    showCreateModal.value = false;
    editingPreset.value = null;
};

const savePreset = (data) => {
    if (editingPreset.value) {
        router.put(route('admin.presets.update', editingPreset.value.id), data);
    } else {
        router.post(route('admin.presets.store'), data);
    }
    closeModal();
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