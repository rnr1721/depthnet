<template>
    <div :class="[
        'rounded transition-colors',
        level > 0 ? 'ml-1 border-l-2 pl-1' : '',
        level > 0 ? (isDark ? 'border-gray-600' : 'border-gray-300') : ''
    ]">
        <!-- Object/Array with children (including JSON strings) -->
        <div v-if="isObjectOrArray">
            <button @click="toggleExpand" :class="[
                'flex items-center justify-between w-full text-left transition-colors rounded px-2 py-1.5 text-sm',
                isDark ? 'hover:bg-gray-700 hover:bg-opacity-30' : 'hover:bg-gray-200 hover:bg-opacity-50'
            ]">
                <div class="flex items-center space-x-2 min-w-0 flex-1">
                    <div :class="[
                        'w-4 h-4 rounded-sm flex items-center justify-center flex-shrink-0',
                        Array.isArray(effectiveValue)
                            ? (isDark ? 'bg-blue-900' : 'bg-blue-100')
                            : (isDark ? 'bg-purple-900' : 'bg-purple-100')
                    ]">
                        <span :class="[
                            'text-xs font-bold',
                            Array.isArray(effectiveValue)
                                ? (isDark ? 'text-blue-300' : 'text-blue-600')
                                : (isDark ? 'text-purple-300' : 'text-purple-600')
                        ]">
                            {{ Array.isArray(effectiveValue) ? 'A' : 'O' }}
                        </span>
                    </div>

                    <span :class="[
                        'font-medium truncate',
                        isDark ? 'text-gray-200' : 'text-gray-700'
                    ]" :title="name">{{ name }}</span>

                    <span v-if="isParsedJson" :class="[
                        'text-xs px-1 rounded font-mono flex-shrink-0',
                        isDark ? 'bg-gray-600 text-gray-400' : 'bg-gray-200 text-gray-500'
                    ]">json</span>
                </div>

                <div class="flex items-center space-x-1 flex-shrink-0 ml-1">
                    <span :class="[
                        'text-xs px-1.5 py-0.5 rounded-full',
                        Array.isArray(effectiveValue)
                            ? (isDark ? 'bg-blue-900 text-blue-300' : 'bg-blue-100 text-blue-700')
                            : (isDark ? 'bg-purple-900 text-purple-300' : 'bg-purple-100 text-purple-700')
                    ]">
                        {{ Array.isArray(effectiveValue) ? effectiveValue.length : Object.keys(effectiveValue).length }}
                    </span>
                    <svg :class="[
                        'w-3 h-3 transition-transform flex-shrink-0',
                        isExpanded ? 'rotate-90' : '',
                        isDark ? 'text-gray-400' : 'text-gray-500'
                    ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
            </button>

            <!-- Children -->
            <div v-if="isExpanded" class="space-y-0.5 mt-0.5">
                <CompactMetadataItem v-for="(childValue, childKey) in effectiveValue" :key="childKey"
                    :name="Array.isArray(effectiveValue) ? `[${childKey}]` : childKey" :value="childValue"
                    :is-dark="isDark" :level="level + 1" :force-expand="forceExpand" />
            </div>
        </div>

        <!-- Primitive value — name on top, value below -->
        <div v-else :class="[
            'px-2 py-1.5 rounded',
            isDark ? 'hover:bg-gray-700 hover:bg-opacity-20' : 'hover:bg-gray-100 hover:bg-opacity-50'
        ]">
            <!-- Name row -->
            <div class="flex items-center space-x-1.5 min-w-0">
                <div :class="[
                    'w-2 h-2 rounded-full flex-shrink-0',
                    getTypeIndicator(value)
                ]"></div>
                <span :class="[
                    'text-xs font-medium truncate',
                    isDark ? 'text-gray-300' : 'text-gray-600'
                ]" :title="name">{{ name }}</span>
            </div>

            <!-- Value row -->
            <div class="flex items-start space-x-1 mt-0.5 pl-3.5">
                <span :class="[
                    'text-xs px-1.5 py-0.5 rounded font-mono break-all',
                    getValueClass(value)
                ]" :title="String(value)">
                    {{ formatValue(value) }}
                </span>
                <button v-if="typeof value === 'string' && value.length > 10" @click="copyToClipboard(value, $event)"
                    :class="[
                        'p-0.5 rounded transition-colors flex-shrink-0 mt-0.5',
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
    forceExpand: { type: Object, default: null }
});

const isExpanded = ref(props.level < 2);

const parsedJsonValue = computed(() => {
    if (typeof props.value !== 'string') return null;
    const trimmed = props.value.trim();
    if (!(trimmed.startsWith('{') || trimmed.startsWith('['))) return null;
    try {
        const parsed = JSON.parse(trimmed);
        if (typeof parsed === 'object' && parsed !== null) return parsed;
    } catch { }
    return null;
});

const isParsedJson = computed(() => parsedJsonValue.value !== null);
const effectiveValue = computed(() => parsedJsonValue.value ?? props.value);

const isObjectOrArray = computed(() => {
    if (parsedJsonValue.value !== null) return true;
    return typeof props.value === 'object' && props.value !== null;
});

watch(() => props.forceExpand, (newValue) => {
    if (newValue !== null) isExpanded.value = newValue.expand;
}, { deep: true });

const toggleExpand = () => { isExpanded.value = !isExpanded.value; };

const getTypeIndicator = (value) => {
    if (typeof value === 'string') return props.isDark ? 'bg-green-400' : 'bg-green-500';
    if (typeof value === 'number') return props.isDark ? 'bg-blue-400' : 'bg-blue-500';
    if (typeof value === 'boolean') return props.isDark ? 'bg-purple-400' : 'bg-purple-500';
    return props.isDark ? 'bg-gray-400' : 'bg-gray-500';
};

const getValueClass = (value) => {
    if (typeof value === 'string') return props.isDark ? 'bg-green-900 bg-opacity-30 text-green-300' : 'bg-green-100 text-green-700';
    if (typeof value === 'number') return props.isDark ? 'bg-blue-900 bg-opacity-30 text-blue-300' : 'bg-blue-100 text-blue-700';
    if (typeof value === 'boolean') return props.isDark ? 'bg-purple-900 bg-opacity-30 text-purple-300' : 'bg-purple-100 text-purple-700';
    if (value === null) return props.isDark ? 'bg-gray-700 bg-opacity-30 text-gray-400' : 'bg-gray-100 text-gray-500';
    return props.isDark ? 'bg-gray-700 bg-opacity-30 text-gray-300' : 'bg-gray-100 text-gray-600';
};

const formatValue = (value) => {
    if (typeof value === 'string') {
        return value.length > 80 ? `"${value.substring(0, 80)}…"` : `"${value}"`;
    }
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    if (value === null) return 'null';
    if (typeof value === 'undefined') return 'undef';
    if (typeof value === 'number') return String(value).length > 10 ? value.toExponential(2) : String(value);
    return String(value);
};

const copyToClipboard = async (text, event) => {
    const button = event.target.closest('button');
    const originalHTML = button?.innerHTML;

    const showCheck = () => {
        if (button) {
            button.innerHTML = '<svg class="w-2.5 h-2.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            setTimeout(() => { button.innerHTML = originalHTML; }, 1000);
        }
    };

    try {
        await navigator.clipboard.writeText(text);
        showCheck();
    } catch {
        try {
            const el = document.createElement('textarea');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            showCheck();
        } catch (e) {
            console.error('Copy failed:', e);
        }
    }
};
</script>