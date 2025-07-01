<template>
    <div :class="[
        'rounded transition-colors',
        level > 0 ? 'ml-3 border-l border-opacity-30' : '',
        level > 0 ? (isDark ? 'border-gray-500' : 'border-gray-400') : ''
    ]">
        <!-- Object/Array with children -->
        <div v-if="isObjectOrArray">
            <!-- Header for object/array -->
            <button @click="toggleExpand" :class="[
                'flex items-center justify-between w-full text-left transition-colors rounded p-2 text-sm',
                isDark ? 'hover:bg-gray-700 hover:bg-opacity-30' : 'hover:bg-gray-200 hover:bg-opacity-50'
            ]">
                <div class="flex items-center space-x-2 min-w-0 flex-1">
                    <div :class="[
                        'w-4 h-4 rounded-sm flex items-center justify-center flex-shrink-0',
                        Array.isArray(value)
                            ? (isDark ? 'bg-blue-900' : 'bg-blue-100')
                            : (isDark ? 'bg-purple-900' : 'bg-purple-100')
                    ]">
                        <span :class="[
                            'text-xs font-bold',
                            Array.isArray(value)
                                ? (isDark ? 'text-blue-300' : 'text-blue-600')
                                : (isDark ? 'text-purple-300' : 'text-purple-600')
                        ]">
                            {{ Array.isArray(value) ? 'A' : 'O' }}
                        </span>
                    </div>

                    <span :class="[
                        'font-medium truncate',
                        isDark ? 'text-gray-200' : 'text-gray-700'
                    ]" :title="name">
                        {{ name }}
                    </span>
                </div>

                <div class="flex items-center space-x-1 flex-shrink-0">
                    <span :class="[
                        'text-xs px-1.5 py-0.5 rounded-full',
                        Array.isArray(value)
                            ? (isDark ? 'bg-blue-900 text-blue-300' : 'bg-blue-100 text-blue-700')
                            : (isDark ? 'bg-purple-900 text-purple-300' : 'bg-purple-100 text-purple-700')
                    ]">
                        {{ Array.isArray(value) ? value.length : Object.keys(value).length }}
                    </span>

                    <svg :class="[
                        'w-3 h-3 transition-transform',
                        isExpanded ? 'rotate-90' : '',
                        isDark ? 'text-gray-400' : 'text-gray-500'
                    ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
            </button>

            <!-- Children -->
            <div v-if="isExpanded" class="space-y-1 pl-2">
                <CompactMetadataItem v-for="(childValue, childKey) in value" :key="childKey"
                    :name="Array.isArray(value) ? `[${childKey}]` : childKey" :value="childValue" :is-dark="isDark"
                    :level="level + 1" :force-expand="forceExpand" />
            </div>
        </div>

        <!-- Primitive value -->
        <div v-else :class="[
            'flex items-center justify-between px-2 py-1.5 rounded text-sm',
            isDark ? 'hover:bg-gray-700 hover:bg-opacity-20' : 'hover:bg-gray-100 hover:bg-opacity-50'
        ]">
            <div class="flex items-center space-x-2 min-w-0 flex-1">
                <!-- Tiny type indicator -->
                <div :class="[
                    'w-2 h-2 rounded-full flex-shrink-0',
                    getTypeIndicator(value)
                ]"></div>

                <span :class="[
                    'font-medium truncate',
                    isDark ? 'text-gray-200' : 'text-gray-700'
                ]" :title="name">
                    {{ name }}
                </span>
            </div>

            <div class="flex items-center space-x-1 min-w-0 flex-shrink-0 ml-2">
                <span :class="[
                    'text-xs px-1.5 py-0.5 rounded font-mono truncate max-w-24',
                    getValueClass(value)
                ]" :title="String(value)">
                    {{ formatValue(value) }}
                </span>

                <button v-if="typeof value === 'string' && value.length > 10" @click="copyToClipboard(value, $event)"
                    :class="[
                        'p-0.5 rounded transition-colors flex-shrink-0',
                        isDark ? 'hover:bg-gray-600 text-gray-400' : 'hover:bg-gray-200 text-gray-500'
                    ]" title="Copy">
                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

const isExpanded = ref(props.level < 2); // Auto-expand first 2 levels for better visibility

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
 * Get tiny color indicator for value type
 */
const getTypeIndicator = (value) => {
    if (typeof value === 'string') {
        return props.isDark ? 'bg-green-400' : 'bg-green-500';
    } else if (typeof value === 'number') {
        return props.isDark ? 'bg-blue-400' : 'bg-blue-500';
    } else if (typeof value === 'boolean') {
        return props.isDark ? 'bg-purple-400' : 'bg-purple-500';
    } else {
        return props.isDark ? 'bg-gray-400' : 'bg-gray-500';
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
 * Format value for display (more compact)
 */
const formatValue = (value) => {
    if (typeof value === 'string') {
        if (value.length > 20) {
            return `"${value.substring(0, 15)}..."`;
        }
        return `"${value}"`;
    } else if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    } else if (value === null) {
        return 'null';
    } else if (typeof value === 'undefined') {
        return 'undef';
    } else if (typeof value === 'number') {
        return String(value).length > 10 ? value.toExponential(2) : String(value);
    }
    return String(value);
};

/**
 * Copy value to clipboard
 */
const copyToClipboard = async (text) => {
    try {
        await navigator.clipboard.writeText(text);

        // Show visual feedback
        const button = event.target.closest('button');
        if (button) {
            const originalHTML = button.innerHTML;
            button.innerHTML = '<svg class="w-2.5 h-2.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';

            setTimeout(() => {
                button.innerHTML = originalHTML;
            }, 1000);
        }
    } catch (error) {
        console.error('Failed to copy to clipboard:', error);

        // Fallback for older browsers
        try {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);

            // Show feedback for fallback too
            const button = event.target.closest('button');
            if (button) {
                const originalHTML = button.innerHTML;
                button.innerHTML = '<svg class="w-2.5 h-2.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';

                setTimeout(() => {
                    button.innerHTML = originalHTML;
                }, 1000);
            }
        } catch (fallbackError) {
            console.error('Fallback copy failed too:', fallbackError);
        }
    }
};
</script>
