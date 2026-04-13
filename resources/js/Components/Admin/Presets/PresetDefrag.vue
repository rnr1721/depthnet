<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                    Memory Defrag
                </h4>
                <span v-if="modelValue.defrag_enabled" :class="[
                    'text-xs px-2 py-0.5 rounded-full font-medium',
                    isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-700'
                ]">Enabled</span>
            </div>
            <button type="button" @click="showGuide = !showGuide" :class="[
                'flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-all',
                showGuide
                    ? (isDark ? 'bg-indigo-900 text-indigo-300' : 'bg-indigo-100 text-indigo-700')
                    : (isDark ? 'text-gray-400 hover:bg-gray-600' : 'text-gray-500 hover:bg-gray-100')
            ]">
                <svg class="w-3 h-3 transition-transform" :class="showGuide ? 'rotate-180' : ''" fill="none"
                    stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
                How it works
            </button>
        </div>

        <p :class="['text-xs mb-4', isDark ? 'text-gray-400' : 'text-gray-500']">
            Compresses vector memory records into concise summaries, grouped by day.
            Keeps the memory store compact and improves retrieval quality over time.
        </p>

        <!-- How it works guide -->
        <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
            enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-96"
            leave-active-class="transition-all duration-150 ease-in overflow-hidden"
            leave-from-class="opacity-100 max-h-96" leave-to-class="opacity-0 max-h-0">
            <div v-if="showGuide" class="mb-4 space-y-3">
                <div
                    :class="['rounded-xl p-4 text-xs space-y-2 leading-relaxed', isDark ? 'bg-gray-900 text-gray-300' : 'bg-gray-100 text-gray-700 ring-1 ring-gray-200']">
                    <p>
                        Vector memory grows indefinitely as the agent runs. Over time, many records become
                        redundant — the same observations rephrased, noise, fragments without context.
                        Defrag fixes this by periodically compressing each day's records into a small number
                        of distilled summaries using the model itself.
                    </p>
                    <p>
                        Records are grouped by calendar day, processed from oldest to newest.
                        Days that already have <span class="font-mono">keep per day</span> records or fewer
                        are skipped automatically — so running defrag multiple times is safe.
                    </p>
                    <p>
                        The distilled records are written back with the original day's timestamp,
                        so the memory timeline stays intact.
                    </p>
                </div>
                <ul :class="['text-xs space-y-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    <li class="flex gap-2">
                        <span class="text-indigo-500 flex-shrink-0">→</span>
                        Run manually: <span class="font-mono">php artisan agent:defrag --preset={{ modelValue.id ?? 'ID'
                            }}</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="text-indigo-500 flex-shrink-0">→</span>
                        Only vector memory is defragged — journal entries are never touched.
                    </li>
                    <li class="flex gap-2">
                        <span class="text-indigo-500 flex-shrink-0">→</span>
                        The <span class="font-mono">[[keep]]</span> variable in the prompt is replaced
                        with the <em>Keep per day</em> value at runtime.
                    </li>
                </ul>
            </div>
        </Transition>

        <div class="space-y-5">
            <!-- Enable toggle -->
            <label :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                <input :checked="modelValue.defrag_enabled"
                    @change="updateField('defrag_enabled', $event.target.checked)" type="checkbox"
                    class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm font-medium">Enable defrag for this preset</span>
            </label>

            <!-- Keep per day -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    Keep per day
                </label>
                <input :value="modelValue.defrag_keep_per_day ?? 3"
                    @input="updateField('defrag_keep_per_day', parseNumber($event.target.value))" type="number" min="1"
                    max="20" step="1" :class="inputClass" placeholder="3" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    Number of distilled summaries to keep per calendar day after compression.
                    Also passed as <span class="font-mono">[[keep]]</span> into the prompt.
                </p>
            </div>

            <!-- Defrag prompt accordion -->
            <div>
                <button type="button" @click="showPrompt = !showPrompt" :class="[
                    'flex items-center gap-2 text-sm font-medium w-full text-left transition-all',
                    isDark ? 'text-gray-300 hover:text-white' : 'text-gray-600 hover:text-gray-900'
                ]">
                    <svg class="w-4 h-4 transition-transform flex-shrink-0" :class="showPrompt ? 'rotate-180' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                    Compression prompt
                    <span :class="['text-xs font-normal ml-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                        {{ modelValue.defrag_prompt ? 'custom' : 'default' }}
                    </span>
                </button>

                <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
                    enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-96"
                    leave-active-class="transition-all duration-150 ease-in overflow-hidden"
                    leave-from-class="opacity-100 max-h-96" leave-to-class="opacity-0 max-h-0">
                    <div v-if="showPrompt" class="mt-3 space-y-2">
                        <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                            Leave empty to use the default prompt from
                            <span class="font-mono">data/defrag/default_prompt.txt</span>.
                            Use <span class="font-mono">[[keep]]</span> to insert the keep-per-day value.
                        </p>
                        <textarea :value="modelValue.defrag_prompt ?? ''"
                            @input="updateField('defrag_prompt', $event.target.value || null)" rows="7"
                            :class="inputClass" :placeholder="defaultPromptPlaceholder"></textarea>
                        <button v-if="modelValue.defrag_prompt" type="button"
                            @click="updateField('defrag_prompt', null)" :class="[
                                'text-xs px-3 py-1 rounded-lg transition-all',
                                isDark ? 'bg-gray-600 text-gray-300 hover:bg-gray-500' : 'bg-gray-200 text-gray-600 hover:bg-gray-300'
                            ]">
                            Reset to default
                        </button>
                    </div>
                </Transition>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';

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
    }
});

const emit = defineEmits(['update:modelValue']);

const showGuide = ref(false);
const showPrompt = ref(false);

const defaultPromptPlaceholder =
    `You are a memory compression assistant. You will receive a numbered list of memory entries recorded during one day.

Your task: compress them into exactly [[keep]] concise, information-dense summaries without losing meaning.
Remove duplicates, noise, and trivial details. Preserve facts, decisions, emotions, and insights.

Respond ONLY with a valid JSON array of strings. No preamble, no markdown, no explanation.`;

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark
        ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400'
        : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const updateField = (field, value) => {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
};

const parseNumber = (value) => {
    const num = parseInt(value);
    return isNaN(num) ? 3 : num;
};
</script>