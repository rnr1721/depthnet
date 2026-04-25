<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('p_modal_system_message') }}
            </h4>
            <button type="button" @click="addPrompt" :class="[
                'inline-flex items-center px-3 py-1.5 text-xs rounded-lg font-medium transition-colors',
                isDark ? 'bg-indigo-700 hover:bg-indigo-600 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white'
            ]">
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ t('p_prompts_add') }}
            </button>
        </div>

        <!-- Prompts list -->
        <div class="space-y-4">
            <div v-for="(prompt, index) in prompts" :key="prompt._key" :class="[
                'rounded-xl border p-4 transition-all',
                isActive(index)
                    ? (isDark ? 'border-gray-400 bg-gray-700 bg-opacity-60' : 'border-gray-400 bg-gray-100')
                    : (isDark ? 'border-gray-600 bg-gray-800 bg-opacity-40' : 'border-gray-200 bg-white')
            ]">
                <!-- Top row: active badge + code + actions -->
                <div class="flex items-center gap-2 mb-3">
                    <span v-if="isActive(index)" :class="[
                        'text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0',
                        isDark ? 'bg-gray-600 text-gray-200' : 'bg-gray-200 text-gray-700'
                    ]">
                        {{ t('p_prompts_active') }}
                    </span>

                    <input v-model="prompt.code" type="text" :placeholder="t('p_prompts_code_placeholder')"
                        maxlength="50" :class="[
                            'flex-1 min-w-0 text-sm rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 px-3 py-1.5 transition-all',
                            isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500'
                        ]" />

                    <div class="flex items-center gap-1 flex-shrink-0">
                        <!-- Set active -->
                        <button v-if="!isActive(index)" type="button" @click="setActive(index)"
                            :title="t('p_prompts_set_active')"
                            :class="['p-1.5 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-indigo-300 hover:bg-gray-600' : 'text-gray-500 hover:text-indigo-600 hover:bg-indigo-50']">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7" />
                            </svg>
                        </button>

                        <!-- Duplicate -->
                        <button type="button" @click="duplicatePrompt(index)" :title="t('p_prompts_duplicate')"
                            :class="['p-1.5 rounded-lg transition-colors', isDark ? 'text-gray-400 hover:text-blue-300 hover:bg-gray-600' : 'text-gray-500 hover:text-blue-600 hover:bg-blue-50']">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>

                        <!-- Delete (disabled if last) -->
                        <button type="button" @click="removePrompt(index)" :disabled="prompts.length <= 1"
                            :title="prompts.length <= 1 ? t('p_prompts_cannot_delete_last') : t('p_prompts_delete')"
                            :class="[
                                'p-1.5 rounded-lg transition-colors disabled:opacity-30 disabled:cursor-not-allowed',
                                isDark ? 'text-gray-400 hover:text-red-400 hover:bg-gray-600' : 'text-gray-500 hover:text-red-600 hover:bg-red-50'
                            ]">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Description -->
                <input v-model="prompt.description" type="text" :placeholder="t('p_prompts_description_placeholder')"
                    maxlength="500" :class="[
                        'w-full text-xs rounded-lg px-3 py-1.5 mb-3 transition-all border',
                        isDark ? 'bg-gray-700 text-gray-300 border-gray-600 placeholder-gray-500 focus:border-gray-500 outline-none' : 'bg-gray-100 text-gray-600 border-gray-200 placeholder-gray-400 focus:border-gray-300 outline-none'
                    ]" />

                <!-- Content -->
                <div>
                    <textarea :ref="el => textareaRefs[prompt._key] = el" v-model="prompt.content" rows="6" :class="[
                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3 text-sm',
                        isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
                    ]" :placeholder="t('p_modal_system_prompt_desc')" @keydown="e => handleKeydown(e, prompt)"
                        @beforeinput="e => handleBeforeInput(e, prompt)"></textarea>

                    <!-- Placeholder insert buttons -->
                    <div v-if="placeholders && Object.keys(placeholders).length > 0" class="flex flex-wrap gap-2 mt-2">
                        <button v-for="(description, key) in placeholders" :key="key" type="button"
                            @click="insertPlaceholder(key, prompt)" :title="description" :class="[
                                'inline-flex items-center px-2 py-1 text-xs rounded-md transition-colors',
                                isDark ? 'bg-green-900 text-green-200 hover:bg-green-800' : 'bg-green-100 text-green-800 hover:bg-green-200'
                            ]">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v16m8-8H4" />
                            </svg>
                            [[{{ key }}]]
                        </button>
                    </div>

                    <!-- Char counter + clear -->
                    <div class="flex justify-between items-center text-xs mt-1"
                        :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                        <span :class="{ 'text-red-500': (prompt.content || '').length > maxLength }">
                            {{ t('p_modal_symbols') }}: {{ (prompt.content || '').length }} / {{ maxLength }}
                        </span>
                        <button v-if="prompt.content" type="button" @click="prompt.content = ''"
                            class="text-red-500 hover:text-red-600 underline">
                            {{ t('p_modal_clear') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available placeholders reference -->
        <div v-if="placeholders && Object.keys(placeholders).length > 0"
            :class="['mt-4 p-3 rounded-lg border', isDark ? 'bg-gray-800 border-gray-600' : 'bg-gray-50 border-gray-200']">
            <h6 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                {{ t('p_modal_available_placeholders') }}:
            </h6>
            <div class="space-y-1">
                <div v-for="(description, key) in placeholders" :key="key" class="flex items-start space-x-2">
                    <code
                        :class="['text-xs px-1 py-0.5 rounded', isDark ? 'bg-gray-700 text-green-300' : 'bg-gray-100 text-green-700']">
                        [[{{ key }}]]
                    </code>
                    <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">{{ description }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: { type: Object, required: true },   // the full preset form object
    isDark: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    placeholders: { type: Object, default: () => ({}) },
    maxLength: { type: Number, default: 20000 },
});

const emit = defineEmits(['update:modelValue', 'success', 'error']);

// ── Internal prompts state ────────────────────────────────────────────────────

let _keyCounter = 0;
const makeKey = () => `prompt_${++_keyCounter}`;

/**
 * Normalise incoming preset prompts into local working copies with a stable _key.
 * Expects modelValue.prompts = [{id?, code, content, description, is_active?}]
 */
function buildLocal(presetValue) {
    const src = presetValue.prompts;

    if (Array.isArray(src) && src.length > 0) {
        return src.map(p => ({ ...p, _key: makeKey() }));
    }

    // Fallback: no prompts array yet → show one empty editable prompt
    return [{
        _key: makeKey(),
        id: null,
        code: 'default',
        content: '',
        description: '',
        is_active: true,
    }];
}

const prompts = ref(buildLocal(props.modelValue));

// Find the initially active prompt index from is_active flag
function resolveActiveIndex(list) {
    const idx = list.findIndex(p => p.is_active);
    return idx !== -1 ? idx : 0;
}

const activeIndex = ref(resolveActiveIndex(prompts.value));
const textareaRefs = ref({});
const deletedIds = ref([]); // IDs of existing prompts removed in this session

// Sync back to form when prompts change
function emitUpdate() {
    const updated = prompts.value.map((p, i) => ({
        id: p.id ?? undefined,
        code: p.code,
        content: p.content,
        description: p.description,
        is_active: i === activeIndex.value,
    }));
    emit('update:modelValue', { ...props.modelValue, prompts: updated, deleted_prompt_ids: deletedIds.value });
}

watch(prompts, emitUpdate, { deep: true });

// Also watch activeIndex separately
watch(activeIndex, emitUpdate);

// ── Actions ───────────────────────────────────────────────────────────────────

function isActive(index) {
    return index === activeIndex.value;
}

function setActive(index) {
    activeIndex.value = index;
}

function addPrompt() {
    prompts.value.push({
        _key: makeKey(), id: null, code: '', content: '', description: '', is_active: false,
    });
}

function removePrompt(index) {
    if (prompts.value.length <= 1) return;
    const removed = prompts.value[index];
    // Track existing (saved) prompts for deletion on the backend
    if (removed.id) {
        deletedIds.value.push(removed.id);
    }
    prompts.value.splice(index, 1);
    if (activeIndex.value >= prompts.value.length) {
        activeIndex.value = 0;
    }
}

function duplicatePrompt(index) {
    const src = prompts.value[index];
    prompts.value.splice(index + 1, 0, {
        _key: makeKey(),
        id: null,           // duplicated prompts are new records
        code: src.code ? src.code + '_copy' : '',
        content: src.content,
        description: src.description ? src.description + ' (copy)' : '',
        is_active: false,
    });
}

// ── Placeholder insertion (unchanged from original PresetSystemPrompt) ────────

function insertPlaceholder(placeholder, prompt) {
    const textarea = textareaRefs.value[prompt._key];
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const current = prompt.content || '';

    prompt.content = current.substring(0, start) + `[[${placeholder}]]` + current.substring(end);

    nextTick(() => {
        textarea.focus();
        const pos = start + placeholder.length + 4;
        textarea.setSelectionRange(pos, pos);
    });
}

function findPlaceholderAt(text, position) {
    if (!props.placeholders || Object.keys(props.placeholders).length === 0) return null;
    const keys = Object.keys(props.placeholders);
    const regex = new RegExp(`\\[\\[(${keys.join('|')})\\]\\]`, 'g');
    let match;
    while ((match = regex.exec(text)) !== null) {
        const s = match.index, e = match.index + match[0].length;
        if (position >= s && position <= e) return { start: s, end: e, text: match[0], key: match[1] };
    }
    return null;
}

function handleKeydown(event, prompt) {
    const ta = event.target;
    const { selectionStart: ss, selectionEnd: se, value } = ta;
    if (event.key !== 'Backspace' && event.key !== 'Delete') return;
    if (ss !== se) return;

    const ph = findPlaceholderAt(value, event.key === 'Backspace' ? ss - 1 : ss);
    if (!ph) return;

    event.preventDefault();
    prompt.content = value.substring(0, ph.start) + value.substring(ph.end);
    nextTick(() => { ta.focus(); ta.setSelectionRange(ph.start, ph.start); });
}

function handleBeforeInput(event, prompt) {
    if (event.inputType !== 'insertText' && event.inputType !== 'insertCompositionText') return;
    const ta = event.target;
    const { selectionStart: ss, selectionEnd: se, value } = ta;
    if (ss !== se) return;

    const ph = findPlaceholderAt(value, ss);
    if (!ph) return;

    event.preventDefault();
    prompt.content = value.substring(0, ph.start) + event.data + value.substring(ph.end);
    nextTick(() => { ta.focus(); const p = ph.start + event.data.length; ta.setSelectionRange(p, p); });
}
</script>