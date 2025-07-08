<template>
    <div :class="[
        'p-3 rounded-lg border transition-all duration-200 group cursor-pointer',
        'hover:scale-[1.02] transform',
        isActive
            ? (isDark ? 'bg-indigo-900 bg-opacity-50 border-indigo-500 shadow-lg' : 'bg-indigo-50 border-indigo-500 shadow-lg')
            : (isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600 hover:bg-gray-600 hover:border-gray-500' : 'bg-white border-gray-200 hover:bg-gray-50 hover:border-gray-300')
    ]" @click="$emit('select')">

        <!-- Header with name and actions -->
        <div class="flex items-start justify-between mb-2">
            <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2">
                    <!-- Active indicator -->
                    <div v-if="isActive" :class="[
                        'w-2 h-2 rounded-full bg-green-400 animate-pulse flex-shrink-0'
                    ]"></div>

                    <!-- Preset name -->
                    <h4 :class="[
                        'font-semibold text-sm truncate',
                        isActive
                            ? (isDark ? 'text-indigo-200' : 'text-indigo-800')
                            : (isDark ? 'text-gray-200' : 'text-gray-900')
                    ]" :title="preset.name">
                        {{ preset.name }}
                    </h4>

                    <!-- Default badge -->
                    <span v-if="preset.is_default" :class="[
                        'text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0',
                        isActive
                            ? 'bg-white bg-opacity-20 text-green-700'
                            : (isDark ? 'bg-yellow-900 bg-opacity-50 text-yellow-300' : 'bg-yellow-100 text-yellow-800')
                    ]">
                        {{ t('chat_current_preset') || 'Default' }}
                    </span>
                </div>

                <!-- Description -->
                <p v-if="preset.description" :class="[
                    'text-xs mt-1 truncate',
                    isActive
                        ? (isDark ? 'text-indigo-300' : 'text-indigo-600')
                        : (isDark ? 'text-gray-400' : 'text-gray-600')
                ]" :title="preset.description">
                    {{ preset.description }}
                </p>
            </div>

            <!-- Edit button -->
            <button @click.stop="$emit('edit', preset.id)" :class="[
                'p-1.5 rounded-md transition-all duration-200 flex-shrink-0 ml-2',
                'opacity-0 group-hover:opacity-100 transform group-hover:scale-110',
                isActive
                    ? (isDark
                        ? 'hover:bg-indigo-800 text-indigo-300 hover:text-indigo-200'
                        : 'hover:bg-indigo-200 text-indigo-600 hover:text-indigo-800')
                    : (isDark
                        ? 'hover:bg-gray-600 text-gray-400 hover:text-gray-200'
                        : 'hover:bg-gray-200 text-gray-600 hover:text-gray-800')
            ]" :title="t('chat_edit_preset') || 'Edit preset'">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                    </path>
                </svg>
            </button>
        </div>

        <!-- Engine and model info -->
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2 min-w-0 flex-1">
                <!-- Engine badge -->
                <span :class="[
                    'text-xs px-2 py-1 rounded-full font-mono font-medium flex-shrink-0',
                    isActive
                        ? (isDark ? 'bg-gray-800 bg-opacity-50 text-gray-300' : 'bg-white bg-opacity-70 text-gray-700')
                        : (isDark ? 'bg-gray-600 bg-opacity-50 text-gray-300' : 'bg-gray-100 text-gray-600')
                ]">
                    {{ preset.engine_display_name || preset.engine_name }}
                </span>

                <!-- Model name -->
                <span v-if="preset.model" :class="[
                    'text-xs truncate font-mono',
                    isActive
                        ? (isDark ? 'text-indigo-300' : 'text-indigo-600')
                        : (isDark ? 'text-gray-400' : 'text-gray-500')
                ]" :title="preset.model">
                    {{ preset.model }}
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    preset: {
        type: Object,
        required: true
    },
    isActive: {
        type: Boolean,
        default: false
    },
    isDark: {
        type: Boolean,
        required: true
    }
});

defineEmits(['select', 'edit']);
</script>
