<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('p_modal_system_message') }}
            </h4>
            <div class="flex items-center space-x-2">
                <span :class="['text-xs px-2 py-1 rounded-lg',
                    modelValue.system_prompt ?
                        (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800') :
                        (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-100 text-gray-600')
                ]">
                    {{ modelValue.system_prompt ? t('p_modal_present') : t('p_modal_default') }}
                </span>
            </div>
        </div>

        <div class="space-y-4">
            <!-- System prompt input -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_user_sys_message') }}
                </label>

                <textarea ref="systemPromptRef" :value="modelValue.system_prompt"
                    @input="updateSystemPrompt($event.target.value)" @keydown="handleKeydown"
                    @beforeinput="handleBeforeInput" rows="6" :class="inputClass"
                    :placeholder="t('p_modal_system_prompt_desc')"></textarea>

                <!-- Quick placeholder buttons -->
                <div v-if="placeholders && Object.keys(placeholders).length > 0" class="flex flex-wrap gap-2 mt-2">
                    <button v-for="(description, key) in placeholders" :key="key" type="button"
                        @click="insertPlaceholder(key)" :class="[
                            'inline-flex items-center px-2 py-1 text-xs rounded-md transition-colors',
                            isDark ? 'bg-green-900 text-green-200 hover:bg-green-800' : 'bg-green-100 text-green-800 hover:bg-green-200'
                        ]" :title="description">
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4">
                            </path>
                        </svg>
                        [[{{ key }}]]
                    </button>
                </div>

                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_sys_message_desc') }}
                </p>

                <div v-if="errors.system_prompt" class="text-red-500 text-xs mt-1">
                    {{ errors.system_prompt }}
                </div>
            </div>

            <!-- Character counter and actions -->
            <div class="flex justify-between items-center text-xs" :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                <span :class="{ 'text-red-500': promptLength > maxLength }">
                    {{ t('p_modal_symbols') }}: {{ promptLength }} / {{ maxLength }}
                </span>
                <div class="flex items-center space-x-2">
                    <button v-if="modelValue.system_prompt" type="button" @click="clearSystemPrompt"
                        :class="['text-red-500 hover:text-red-600 underline text-xs']">
                        {{ t('p_modal_clear') }}
                    </button>
                    <button v-if="modelValue.system_prompt" type="button" @click="copyToClipboard"
                        :class="['text-blue-500 hover:text-blue-600 underline text-xs']">
                        {{ t('p_modal_copy') }}
                    </button>
                </div>
            </div>

            <!-- Preview of effective system prompt -->
            <div v-if="!modelValue.system_prompt && defaultSystemPrompt"
                :class="['p-3 rounded-lg border', isDark ? 'bg-gray-800 border-gray-600' : 'bg-gray-50 border-gray-200']">
                <h6 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                    {{ t('p_modal_default_system_message') }}:
                </h6>
                <p :class="['text-xs whitespace-pre-wrap', isDark ? 'text-gray-400' : 'text-gray-600']">
                    {{ defaultSystemPrompt }}
                </p>
            </div>

            <!-- Placeholder usage info -->
            <div v-if="placeholders && Object.keys(placeholders).length > 0"
                :class="['p-3 rounded-lg border', isDark ? 'bg-gray-800 border-gray-600' : 'bg-gray-50 border-gray-200']">
                <h6 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                    {{ t('p_modal_available_placeholders') }}:
                </h6>
                <div class="space-y-1">
                    <div v-for="(description, key) in placeholders" :key="key" class="flex items-start space-x-2">
                        <code
                            :class="['text-xs px-1 py-0.5 rounded', isDark ? 'bg-gray-700 text-green-300' : 'bg-gray-100 text-green-700']">
                            [[{{ key }}]]
                        </code>
                        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ description }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Object,
        required: true
    },
    isDark: {
        type: Boolean,
        default: false
    },
    errors: {
        type: Object,
        default: () => ({})
    },
    placeholders: {
        type: Object,
        default: () => ({})
    },
    defaultSystemPrompt: {
        type: String,
        default: ''
    },
    maxLength: {
        type: Number,
        default: 10000
    }
});

const emit = defineEmits(['update:modelValue', 'success', 'error']);

const systemPromptRef = ref(null);

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const promptLength = computed(() => {
    return (props.modelValue.system_prompt || '').length;
});

const updateField = (field, value) => {
    const updated = { ...props.modelValue, [field]: value };
    emit('update:modelValue', updated);
};

const updateSystemPrompt = (value) => {
    updateField('system_prompt', value);
};

const clearSystemPrompt = () => {
    updateField('system_prompt', '');
    emit('success', t('p_modal_system_prompt_cleared'));
};

const copyToClipboard = async () => {
    try {
        await navigator.clipboard.writeText(props.modelValue.system_prompt);
        emit('success', t('p_modal_copied_to_clipboard'));
    } catch (error) {
        emit('error', t('p_modal_copy_failed'));
    }
};

/**
 * Insert placeholder at cursor position
 */
const insertPlaceholder = (placeholder) => {
    const textarea = systemPromptRef.value;
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const currentValue = props.modelValue.system_prompt || '';

    const newValue = currentValue.substring(0, start) + `[[${placeholder}]]` + currentValue.substring(end);
    updateField('system_prompt', newValue);

    nextTick(() => {
        textarea.focus();
        const newPosition = start + placeholder.length + 4; // +4 for braces [[]]
        textarea.setSelectionRange(newPosition, newPosition);
    });
};

/**
 * Handle keydown events for smart placeholder deletion
 */
const handleKeydown = (event) => {
    const textarea = event.target;
    const { selectionStart, selectionEnd, value } = textarea;

    if (event.key !== 'Backspace' && event.key !== 'Delete') return;
    if (selectionStart !== selectionEnd) return;

    let checkPosition;
    if (event.key === 'Backspace') {
        checkPosition = selectionStart - 1;
    } else {
        checkPosition = selectionStart;
    }

    const placeholder = findPlaceholderAt(value, checkPosition);

    if (placeholder) {
        event.preventDefault();
        const newValue = value.substring(0, placeholder.start) + value.substring(placeholder.end);
        updateField('system_prompt', newValue);

        nextTick(() => {
            textarea.focus();
            textarea.setSelectionRange(placeholder.start, placeholder.start);
        });
    }
};

/**
 * Handle beforeinput for typing over placeholders
 */
const handleBeforeInput = (event) => {
    if (event.inputType !== 'insertText' && event.inputType !== 'insertCompositionText') return;

    const textarea = event.target;
    const { selectionStart, selectionEnd, value } = textarea;

    if (selectionStart !== selectionEnd) return;

    const placeholder = findPlaceholderAt(value, selectionStart);

    if (placeholder) {
        event.preventDefault();
        const newValue = value.substring(0, placeholder.start) + event.data + value.substring(placeholder.end);
        updateField('system_prompt', newValue);

        nextTick(() => {
            textarea.focus();
            const newPosition = placeholder.start + event.data.length;
            textarea.setSelectionRange(newPosition, newPosition);
        });
    }
};

/**
 * Find placeholder at specific position
 */
const findPlaceholderAt = (text, position) => {
    if (!props.placeholders || Object.keys(props.placeholders).length === 0) return null;

    const placeholderKeys = Object.keys(props.placeholders);
    const regex = new RegExp(`\\[\\[(${placeholderKeys.join('|')})\\]\\]`, 'g');

    let match;
    while ((match = regex.exec(text)) !== null) {
        const start = match.index;
        const end = match.index + match[0].length;

        if (position >= start && position <= end) {
            return {
                start,
                end,
                text: match[0],
                key: match[1]
            };
        }
    }

    return null;
};
</script>
