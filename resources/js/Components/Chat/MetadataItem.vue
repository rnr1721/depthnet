<template>
    <div :class="[
        'rounded-lg transition-colors',
        level > 0 ? (isDark ? 'bg-gray-700 bg-opacity-30' : 'bg-gray-100 bg-opacity-50') : '',
        level > 0 ? 'ml-4 border-l-2' : '',
        level > 0 ? (isDark ? 'border-gray-600' : 'border-gray-300') : ''
    ]">
        <!-- Object/Array with children -->
        <div v-if="isObjectOrArray" class="space-y-1">
            <!-- Header for object/array -->
            <button @click="toggleExpand" :class="[
                'flex items-center justify-between w-full text-left transition-colors rounded-lg p-3',
                isDark ? 'hover:bg-gray-600 hover:bg-opacity-50' : 'hover:bg-gray-200 hover:bg-opacity-50'
            ]">
                <div class="flex items-center space-x-3">
                    <svg :class="[
                        'w-4 h-4 transition-transform flex-shrink-0',
                        isExpanded ? 'rotate-90' : '',
                        isDark ? 'text-gray-400' : 'text-gray-500'
                    ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>

                    <!-- Icon for object type -->
                    <div :class="[
                        'w-6 h-6 rounded flex items-center justify-center',
                        Array.isArray(value)
                            ? (isDark ? 'bg-blue-900 bg-opacity-50' : 'bg-blue-100')
                            : (isDark ? 'bg-purple-900 bg-opacity-50' : 'bg-purple-100')
                    ]">
                        <svg v-if="Array.isArray(value)" class="w-3 h-3"
                            :class="isDark ? 'text-blue-300' : 'text-blue-600'" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z">
                            </path>
                        </svg>
                        <svg v-else class="w-3 h-3" :class="isDark ? 'text-purple-300' : 'text-purple-600'"
                            fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M3 4a1 1 0 011-1h4a1 1 0 010 2H6.414l2.293 2.293a1 1 0 01-1.414 1.414L5 6.414V8a1 1 0 01-2 0V4zm9 1a1 1 0 010-2h4a1 1 0 011 1v4a1 1 0 01-2 0V6.414l-2.293 2.293a1 1 0 11-1.414-1.414L13.586 5H12zm-9 7a1 1 0 012 0v1.586l2.293-2.293a1 1 0 111.414 1.414L6.414 15H8a1 1 0 010 2H4a1 1 0 01-1-1v-4zm13-1a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 010-2h1.586l-2.293-2.293a1 1 0 111.414-1.414L15 13.586V12a1 1 0 011-1z"
                                clip-rule="evenodd"></path>
                        </svg>
                    </div>

                    <span :class="[
                        'font-medium text-sm',
                        isDark ? 'text-gray-200' : 'text-gray-700'
                    ]">
                        {{ name }}
                    </span>
                </div>

                <div class="flex items-center space-x-2">
                    <span :class="[
                        'text-xs px-2 py-1 rounded-full',
                        Array.isArray(value)
                            ? (isDark ? 'bg-blue-900 bg-opacity-50 text-blue-300' : 'bg-blue-100 text-blue-700')
                            : (isDark ? 'bg-purple-900 bg-opacity-50 text-purple-300' : 'bg-purple-100 text-purple-700')
                    ]">
                        {{ Array.isArray(value) ? `${value.length} items` : `${Object.keys(value).length} keys` }}
                    </span>
                </div>
            </button>

            <!-- Children -->
            <Transition enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 transform -translate-y-2"
                enter-to-class="opacity-100 transform translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 transform translate-y-0"
                leave-to-class="opacity-0 transform -translate-y-2">
                <div v-if="isExpanded" class="space-y-1 pb-2">
                    <MetadataItem v-for="(childValue, childKey) in value" :key="childKey"
                        :name="Array.isArray(value) ? `[${childKey}]` : childKey" :value="childValue" :is-dark="isDark"
                        :level="level + 1" :force-expand="forceExpand" />
                </div>
            </Transition>
        </div>

        <!-- Primitive value -->
        <div v-else :class="[
            'flex items-center justify-between px-3 py-2 rounded-lg',
            isDark ? 'hover:bg-gray-700 hover:bg-opacity-30' : 'hover:bg-gray-100 hover:bg-opacity-50'
        ]">
            <div class="flex items-center space-x-3">
                <!-- Icon for value type -->
                <div :class="[
                    'w-5 h-5 rounded flex items-center justify-center flex-shrink-0',
                    getTypeColor(value)
                ]">
                    <svg class="w-3 h-3" :class="getTypeIconColor(value)" fill="currentColor" viewBox="0 0 20 20">
                        <path v-if="typeof value === 'string'"
                            d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z">
                        </path>
                        <path v-else-if="typeof value === 'number'"
                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z">
                        </path>
                        <path v-else-if="typeof value === 'boolean'" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z">
                        </path>
                        <path v-else d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>

                <span :class="[
                    'font-medium text-sm truncate',
                    isDark ? 'text-gray-200' : 'text-gray-700'
                ]">
                    {{ name }}
                </span>
            </div>

            <div class="flex items-center space-x-2 min-w-0 flex-shrink-0 ml-4">
                <span :class="[
                    'text-xs px-2 py-1 rounded font-mono truncate max-w-xs',
                    getValueClass(value)
                ]" :title="formatValue(value)">
                    {{ formatValue(value) }}
                </span>

                <button v-if="typeof value === 'string' && value.length > 20" @click="copyToClipboard(value)" :class="[
                    'p-1 rounded transition-colors flex-shrink-0',
                    isDark ? 'hover:bg-gray-600 text-gray-400 hover:text-gray-300' : 'hover:bg-gray-200 text-gray-500 hover:text-gray-600'
                ]" title="Copy to clipboard">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z">
                        </path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    name: [String, Number],
    value: null,
    isDark: Boolean,
    level: {
        type: Number,
        default: 0
    },
    forceExpand: {
        type: Boolean,
        default: false
    }
});

const isExpanded = ref(props.level < 2); // Auto-expand first 2 levels

const isObjectOrArray = computed(() => {
    return typeof props.value === 'object' && props.value !== null;
});

// Watch for force expand changes
watch(() => props.forceExpand, (newValue) => {
    if (newValue !== null) {
        isExpanded.value = newValue;
    }
});

const toggleExpand = () => {
    isExpanded.value = !isExpanded.value;
};

/**
 * Get background color for value type icon
 */
const getTypeColor = (value) => {
    const base = props.isDark ? 'bg-opacity-50' : '';

    if (typeof value === 'string') {
        return props.isDark ? 'bg-green-900 bg-opacity-50' : 'bg-green-100';
    } else if (typeof value === 'number') {
        return props.isDark ? 'bg-blue-900 bg-opacity-50' : 'bg-blue-100';
    } else if (typeof value === 'boolean') {
        return props.isDark ? 'bg-purple-900 bg-opacity-50' : 'bg-purple-100';
    } else {
        return props.isDark ? 'bg-gray-700 bg-opacity-50' : 'bg-gray-100';
    }
};

/**
 * Get icon color for value type
 */
const getTypeIconColor = (value) => {
    if (typeof value === 'string') {
        return props.isDark ? 'text-green-300' : 'text-green-600';
    } else if (typeof value === 'number') {
        return props.isDark ? 'text-blue-300' : 'text-blue-600';
    } else if (typeof value === 'boolean') {
        return props.isDark ? 'text-purple-300' : 'text-purple-600';
    } else {
        return props.isDark ? 'text-gray-400' : 'text-gray-500';
    }
};

/**
 * Get CSS class for value based on its type
 */
const getValueClass = (value) => {
    if (typeof value === 'string') {
        return props.isDark ? 'bg-green-900 bg-opacity-30 text-green-300' : 'bg-green-100 text-green-700';
    } else if (typeof value === 'number') {
        return props.isDark ? 'bg-blue-900 bg-opacity-30 text-blue-300' : 'bg-blue-100 text-blue-700';
    } else if (typeof value === 'boolean') {
        return props.isDark ? 'bg-purple-900 bg-opacity-30 text-purple-300' : 'bg-purple-100 text-purple-700';
    } else if (value === null) {
        return props.isDark ? 'bg-gray-700 bg-opacity-30 text-gray-400' : 'bg-gray-100 text-gray-500';
    }
    return props.isDark ? 'bg-gray-700 bg-opacity-30 text-gray-300' : 'bg-gray-100 text-gray-600';
};

/**
 * Format value for display
 */
const formatValue = (value) => {
    if (typeof value === 'string') {
        return value.length > 50 ? `"${value.substring(0, 50)}..."` : `"${value}"`;
    } else if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    } else if (value === null) {
        return 'null';
    } else if (typeof value === 'undefined') {
        return 'undefined';
    }
    return String(value);
};

/**
 * Copy value to clipboard
 */
const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
    } catch (error) {
        console.error('Failed to copy to clipboard:', error);
    }
};
</script>
