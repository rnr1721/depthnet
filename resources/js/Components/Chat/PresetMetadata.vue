<template>
    <div v-if="hasMetadata" :class="[
        'rounded-lg border p-3',
        isDark ? 'bg-gray-800 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
    ]">
        <div class="flex items-center justify-between mb-2">
            <h6 :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">
                {{ title }}
            </h6>
            <div class="flex items-center space-x-1">
                <span
                    :class="['text-xs px-1.5 py-0.5 rounded', isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600']">
                    {{ itemCount }}
                </span>
                <button v-if="canToggle" @click="toggleAll" :class="['text-xs px-1.5 py-0.5 rounded transition-colors',
                    isDark ? 'bg-blue-900 text-blue-200 hover:bg-blue-800' : 'bg-blue-100 text-blue-800 hover:bg-blue-200'
                ]">
                    {{ allExpanded ? '−' : '+' }}
                </button>
            </div>
        </div>

        <div class="space-y-1 max-h-48 overflow-y-auto">
            <CompactMetadataItem v-for="(value, key) in metadata" :key="key" :name="key" :value="value"
                :is-dark="isDark" :level="0" :force-expand="allExpanded" />
        </div>
    </div>

    <div v-else :class="[
        'rounded-lg border border-dashed p-3 text-center',
        isDark ? 'border-gray-600 bg-gray-800 bg-opacity-30' : 'border-gray-300 bg-gray-50 bg-opacity-50'
    ]">
        <svg class="w-6 h-6 mx-auto mb-1 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
            </path>
        </svg>
        <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
            {{ emptyMessage }}
        </p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import CompactMetadataItem from './CompactMetadataItem.vue';

const props = defineProps({
    metadata: {
        type: Object,
        default: () => ({})
    },
    isDark: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        default: 'Metadata'
    },
    emptyMessage: {
        type: String,
        default: 'No metadata'
    }
});

const allExpanded = ref(false);

const hasMetadata = computed(() => {
    return props.metadata && Object.keys(props.metadata).length > 0;
});

const itemCount = computed(() => {
    return Object.keys(props.metadata || {}).length;
});

const canToggle = computed(() => {
    return hasMetadata.value && itemCount.value > 1;
});

const toggleAll = () => {
    allExpanded.value = !allExpanded.value;
};
</script>
