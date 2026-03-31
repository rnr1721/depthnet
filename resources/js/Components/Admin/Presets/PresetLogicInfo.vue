<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('p_modal_handoff_logic') }}
            </h4>
        </div>

        <div class="space-y-6">
            <!-- Preset Code and Wait Time -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Preset Code -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_preset_code') }}
                    </label>
                    <input :value="modelValue.preset_code" @input="updateField('preset_code', $event.target.value)"
                        type="text" :class="inputClass" :placeholder="t('p_modal_preset_code_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_preset_code_desc') }}
                    </p>
                    <div v-if="errors.preset_code" class="text-red-500 text-xs mt-1">
                        {{ errors.preset_code }}
                    </div>
                </div>

                <!-- Before Execution Wait -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_before_execution_wait') }}
                    </label>
                    <input :value="modelValue.before_execution_wait"
                        @input="updateField('before_execution_wait', parseNumber($event.target.value))" type="number"
                        min="1" max="60" step="1" :class="inputClass"
                        :placeholder="t('p_modal_before_execution_wait_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_before_execution_wait_desc') }}
                    </p>
                    <div v-if="errors.before_execution_wait" class="text-red-500 text-xs mt-1">
                        {{ errors.before_execution_wait }}
                    </div>
                </div>
            </div>

            <!-- Auto-Handoff Settings -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Preset Code Next -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_preset_code_next') }}
                    </label>
                    <input :value="modelValue.preset_code_next"
                        @input="updateField('preset_code_next', $event.target.value)" type="text" :class="inputClass"
                        :placeholder="t('p_modal_preset_code_next_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_preset_code_next_desc') }}
                    </p>
                    <div v-if="errors.preset_code_next" class="text-red-500 text-xs mt-1">
                        {{ errors.preset_code_next }}
                    </div>
                </div>

                <!-- Error Behavior -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_error_behavior') }}
                    </label>
                    <select :value="modelValue.error_behavior"
                        @input="updateField('error_behavior', $event.target.value)" :class="inputClass">
                        <option value="stop">{{ t('p_modal_error_stop') }}</option>
                        <option value="continue">{{ t('p_modal_error_continue') }}</option>
                        <option value="fallback">{{ t('p_modal_error_fallback') }}</option>
                    </select>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_error_behavior_desc') }}
                    </p>
                </div>
            </div>

            <!-- Default Call Message -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_default_call_message') }}
                </label>
                <textarea :value="modelValue.default_call_message"
                    @input="updateField('default_call_message', $event.target.value)" rows="3" :class="inputClass"
                    :placeholder="t('p_modal_default_call_message_ph')"></textarea>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_default_call_message_desc') }}
                </p>
                <div v-if="errors.default_call_message" class="text-red-500 text-xs mt-1">
                    {{ errors.default_call_message }}
                </div>
            </div>

            <!-- Handoff Permissions -->
            <div>
                <h6 :class="['text-sm font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_handoff_permissions') }}
                </h6>
                <div class="flex flex-wrap gap-6">
                    <label
                        :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                        <input :checked="modelValue.allow_handoff_from"
                            @change="updateField('allow_handoff_from', $event.target.checked)" type="checkbox"
                            class="w-4 h-4 rounded text-indigo-600" />
                        <span class="text-sm">{{ t('p_modal_allow_handoff_from') }}</span>
                    </label>

                    <label
                        :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                        <input :checked="modelValue.allow_handoff_to"
                            @change="updateField('allow_handoff_to', $event.target.checked)" type="checkbox"
                            class="w-4 h-4 rounded text-indigo-600" />
                        <span class="text-sm">{{ t('p_modal_allow_handoff_to') }}</span>
                    </label>
                </div>
                <p :class="['text-xs mt-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_handoff_permissions_desc') }}
                </p>
            </div>

            <!-- Next Preset Select -->
            <div v-if="otherPresets.length > 0" class="border-t pt-4"
                :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_quick_handoff_setup') }}
                </label>
                <select :value="modelValue.preset_code_next ?? ''" @change="onNextPresetChange($event.target.value)"
                    :class="inputClass">
                    <option value="">{{ t('p_modal_not_selected') }}</option>
                    <option v-for="preset in otherPresets" :key="preset.id" :value="preset.preset_code ?? ''"
                        :disabled="!preset.preset_code">
                        {{ preset.name }}{{ !preset.preset_code ? ' — ' + t('p_modal_no_code') : '' }}
                    </option>
                </select>
            </div>

            <!-- Workflow Preview -->
            <div v-if="modelValue.preset_code_next" class="border-t pt-4"
                :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <h6 :class="['text-sm font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_workflow_preview') }}
                </h6>
                <div class="flex items-center space-x-2 text-sm">
                    <div
                        :class="['px-3 py-2 rounded-lg bg-opacity-50', isDark ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-800']">
                        {{ modelValue.preset_code || t('p_modal_this_preset') }}
                    </div>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    <div
                        :class="['px-3 py-2 rounded-lg bg-opacity-50', isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800']">
                        {{ modelValue.preset_code_next }}
                    </div>
                </div>
                <p :class="['text-xs mt-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_auto_handoff_after_execution') }}
                </p>
            </div>

            <!-- RAG -->
            <div class="border-t pt-4" :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <!-- Header row -->
                <div class="flex items-center justify-between mb-1">
                    <h6 :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">RAG</h6>
                    <button type="button" @click="showRagHint = !showRagHint" :class="[
                        'flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-all',
                        showRagHint
                            ? (isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-700')
                            : (isDark ? 'text-gray-400 hover:bg-gray-600' : 'text-gray-500 hover:bg-gray-100')
                    ]">
                        <svg class="w-3 h-3 transition-transform" :class="showRagHint ? 'rotate-180' : ''" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                        Setup guide
                    </button>
                </div>

                <p :class="['text-xs mb-3', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_rag_desc') }}
                </p>

                <select :value="modelValue.rag_preset_id ?? ''" @change="onRagPresetChange($event.target.value)"
                    :class="inputClass">
                    <option value="">{{ t('p_modal_rag_not_selected') }}</option>
                    <option v-for="preset in otherPresets" :key="preset.id" :value="preset.id">
                        {{ preset.name }} ({{ preset.engine_name }})
                    </option>
                </select>

                <!-- Collapsible setup guide -->
                <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
                    enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-96"
                    leave-active-class="transition-all duration-150 ease-in overflow-hidden"
                    leave-from-class="opacity-100 max-h-96" leave-to-class="opacity-0 max-h-0">
                    <div v-if="showRagHint" class="mt-3 space-y-3">
                        <p :class="['text-xs leading-relaxed', isDark ? 'text-gray-400' : 'text-gray-500']">
                            Create a separate lightweight preset (e.g. Haiku or a local Ollama model) and paste
                            this <span class="font-mono">system_prompt</span> into it. That preset formulates search
                            queries; its vector memory is the knowledge base.
                        </p>
                        <!-- Prompt box -->
                        <div
                            :class="['relative rounded-xl', isDark ? 'bg-gray-900' : 'bg-gray-100 ring-1 ring-gray-200']">
                            <pre
                                :class="['px-4 py-3 text-xs leading-relaxed font-mono whitespace-pre-wrap pr-16', isDark ? 'text-emerald-300' : 'text-gray-700']">{{ ragSystemPrompt }}</pre>
                            <button type="button" @click="copyPrompt" :class="[
                                'absolute top-2 right-2 px-2.5 py-1 rounded-lg text-xs font-medium transition-all',
                                copied
                                    ? (isDark ? 'bg-emerald-800 text-emerald-200' : 'bg-emerald-100 text-emerald-700')
                                    : (isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-white text-gray-600 hover:bg-gray-100 ring-1 ring-gray-200')
                            ]">
                                {{ copied ? '✓ Copied' : 'Copy' }}
                            </button>
                        </div>
                        <!-- Tips -->
                        <ul :class="['text-xs space-y-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                            <li class="flex gap-2"><span class="text-emerald-500 flex-shrink-0">→</span> Enable the
                                <span class="font-mono">[vectormemory]</span> plugin on the RAG preset.
                            </li>
                            <li class="flex gap-2"><span class="text-emerald-500 flex-shrink-0">→</span> Use a fast
                                cheap model — it only outputs a short search query per cycle.</li>
                            <li class="flex gap-2"><span class="text-emerald-500 flex-shrink-0">→</span> The RAG preset
                                does not need to be active or in a loop.</li>
                        </ul>
                    </div>
                </Transition>
            </div>

            <!-- RAG mode -->
            <div :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_rag_mode') }}
                </label>
                <select :value="modelValue.rag_mode" @input="updateField('rag_mode', $event.target.value)"
                    :class="inputClass">
                    <option value="flat">{{ t('p_modal_rag_mode_flat') }}</option>
                    <option value="associative">{{ t('p_modal_rag_mode_assoc') }}</option>
                </select>
            </div>

            <!-- RAG engine -->
            <div :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_rag_engine') }}
                </label>
                <select :value="modelValue.rag_engine" @input="updateField('rag_engine', $event.target.value)"
                    :class="inputClass">
                    <option value="tfidf">{{ t('p_modal_rag_engine_tfidf') }}</option>
                    <option value="embedding">{{ t('p_modal_rag_engine_embedding') }}</option>
                </select>
            </div>



            <!-- RAG display settings -->
            <div class="" :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <h6 :class="['text-sm font-medium mb-3', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_rag_display_limits') }}
                </h6>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">

                    <!-- Rag context limit -->
                    <div>
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_rag_context_limit') }}
                        </label>
                        <input :value="modelValue.rag_context_limit ?? 5"
                            @input="updateField('rag_context_limit', parseNumber($event.target.value))" type="number"
                            min="4" max="20" step="1" :class="inputClass"
                            :placeholder="t('p_modal_rag_context_limit_placeholder')" />
                        <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ t('p_modal_rag_context_limit_desc') }}
                        </p>
                        <div v-if="errors.rag_context_limit" class="text-red-500 text-xs mt-1">
                            {{ errors.rag_context_limit }}
                        </div>
                    </div>

                    <!-- Rag results -->
                    <div>
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_rag_results') }}
                        </label>
                        <input :value="modelValue.rag_results ?? 5"
                            @input="updateField('rag_results', parseNumber($event.target.value))" type="number" min="4"
                            max="20" step="1" :class="inputClass" :placeholder="t('p_modal_rag_results_placeholder')" />
                        <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ t('p_modal_rag_results_desc') }}
                        </p>
                        <div v-if="errors.rag_results" class="text-red-500 text-xs mt-1">
                            {{ errors.rag_results }}
                        </div>
                    </div>


                    <!-- Journal limit -->
                    <div>
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_rag_journal_limit') }}
                        </label>
                        <input :value="modelValue.rag_journal_limit ?? 3"
                            @input="updateField('rag_journal_limit', parseNumber($event.target.value))" type="number"
                            min="1" max="20" step="1" :class="inputClass" />
                    </div>

                    <!-- Skills limit -->
                    <div>
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_rag_skills_limit') }}
                        </label>
                        <input :value="modelValue.rag_skills_limit ?? 3"
                            @input="updateField('rag_skills_limit', parseNumber($event.target.value))" type="number"
                            min="1" max="20" step="1" :class="inputClass" />
                    </div>

                    <!-- Content limit -->
                    <div>
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_rag_content_limit') }}
                        </label>
                        <input :value="modelValue.rag_content_limit ?? 400"
                            @input="updateField('rag_content_limit', parseNumber($event.target.value))" type="number"
                            min="100" max="2000" step="50" :class="inputClass" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Journal context window -->
                    <div>
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('p_modal_rag_journal_context_window') }}
                        </label>
                        <input :value="modelValue.rag_journal_context_window ?? 0"
                            @input="updateField('rag_journal_context_window', parseNumber($event.target.value))"
                            type="number" min="0" max="5" step="1" :class="inputClass" />
                        <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ t('p_modal_rag_journal_context_window_desc') }}
                        </p>
                    </div>

                    <!-- Relative dates -->
                    <div class="flex items-center pt-6">
                        <label
                            :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                            <input :checked="modelValue.rag_relative_dates"
                                @change="updateField('rag_relative_dates', $event.target.checked)" type="checkbox"
                                class="w-4 h-4 rounded text-indigo-600" />
                            <span class="text-sm">{{ t('p_modal_rag_relative_dates') }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Inner Voice -->
            <div class="border-t pt-4" :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                <div class="flex items-center justify-between mb-1">
                    <h6 :class="['text-sm font-medium', isDark ? 'text-white' : 'text-gray-900']">Inner Voice</h6>
                    <button type="button" @click="showVoiceHint = !showVoiceHint" :class="[
                        'flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-all',
                        showVoiceHint
                            ? (isDark ? 'bg-violet-900 text-violet-300' : 'bg-violet-100 text-violet-700')
                            : (isDark ? 'text-gray-400 hover:bg-gray-600' : 'text-gray-500 hover:bg-gray-100')
                    ]">
                        <svg class="w-3 h-3 transition-transform" :class="showVoiceHint ? 'rotate-180' : ''" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                        Setup guide
                    </button>
                </div>

                <p :class="['text-xs mb-3', isDark ? 'text-gray-400' : 'text-gray-500']">
                    A separate preset whose response is injected as <span class="font-mono">[[inner_voice]]</span> into
                    the system prompt before each cycle. Use it as an advisor, conscience, muse, or subconscious.
                </p>

                <select :value="modelValue.voice_preset_id ?? ''" @change="onVoicePresetChange($event.target.value)"
                    :class="inputClass">
                    <option value="">— Disabled —</option>
                    <option v-for="preset in otherPresets" :key="preset.id" :value="preset.id">
                        {{ preset.name }} ({{ preset.engine_name }})
                    </option>
                </select>

                <!-- Collapsible setup guide -->
                <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
                    enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-screen"
                    leave-active-class="transition-all duration-150 ease-in overflow-hidden"
                    leave-from-class="opacity-100 max-h-screen" leave-to-class="opacity-0 max-h-0">
                    <div v-if="showVoiceHint" class="mt-3 space-y-3">
                        <p :class="['text-xs leading-relaxed', isDark ? 'text-gray-400' : 'text-gray-500']">
                            Create a lightweight preset and tune its <span class="font-mono">system_prompt</span> to
                            define the character of the voice. Pick one of the examples below:
                        </p>

                        <!-- Voice examples -->
                        <div class="space-y-2">
                            <div v-for="example in voiceExamples" :key="example.label"
                                :class="['rounded-xl overflow-hidden', isDark ? 'bg-gray-900' : 'bg-gray-100 ring-1 ring-gray-200']">
                                <div
                                    :class="['flex items-center justify-between px-4 py-2 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                    <span
                                        :class="['text-xs font-medium', isDark ? 'text-violet-300' : 'text-violet-700']">
                                        {{ example.label }}
                                    </span>
                                    <button type="button" @click="copyVoicePrompt(example)" :class="[
                                        'text-xs px-2 py-0.5 rounded-lg transition-all',
                                        copiedVoice === example.label
                                            ? (isDark ? 'bg-violet-800 text-violet-200' : 'bg-violet-100 text-violet-700')
                                            : (isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-white text-gray-600 hover:bg-gray-100 ring-1 ring-gray-200')
                                    ]">
                                        {{ copiedVoice === example.label ? '✓ Copied' : 'Copy' }}
                                    </button>
                                </div>
                                <pre
                                    :class="['px-4 py-3 text-xs font-mono whitespace-pre-wrap leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-700']">{{ example.prompt }}</pre>
                            </div>
                        </div>

                        <ul :class="['text-xs space-y-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                            <li class="flex gap-2"><span class="text-violet-500 flex-shrink-0">→</span> Add <span
                                    class="font-mono">[[inner_voice]]</span> to the main preset's system prompt where
                                you want the voice to appear.</li>
                            <li class="flex gap-2"><span class="text-violet-500 flex-shrink-0">→</span> The voice preset
                                does not need to be active or in a loop — it's called silently.</li>
                            <li class="flex gap-2"><span class="text-violet-500 flex-shrink-0">→</span> Use a fast cheap
                                model — the output is short by design.</li>
                        </ul>
                    </div>
                </Transition>
            </div>

            <!-- Voice context limit -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_voice_context_limit') }}
                </label>
                <input :value="modelValue.voice_context_limit ?? 5"
                    @input="updateField('voice_context_limit', parseNumber($event.target.value))" type="number" min="0"
                    max="20" step="1" :class="inputClass" :placeholder="t('p_modal_voice_context_limit_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_voice_context_limit_desc') }}
                </p>
                <div v-if="errors.voice_context_limit" class="text-red-500 text-xs mt-1">
                    {{ errors.voice_context_limit }}
                </div>
            </div>

            <!-- Cycle Inner Voice -->
            <div class="mt-6">
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_cycle_prompt_preset') }}
                </label>
                <select :value="modelValue.cycle_prompt_preset_id"
                    @input="updateField('cycle_prompt_preset_id', $event.target.value ? parseInt($event.target.value) : null)"
                    :class="inputClass">
                    <option :value="null">{{ t('p_modal_none') }}</option>
                    <option v-for="preset in availablePresets" :key="preset.id" :value="preset.id">
                        {{ preset.name }}
                    </option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_cycle_prompt_preset_desc') }}
                </p>
                <div v-if="errors.cycle_prompt_preset_id" class="text-red-500 text-xs mt-1">
                    {{ errors.cycle_prompt_preset_id }}
                </div>
            </div>

            <!-- Cycle prompt context limit -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_cp_context_limit') }}
                </label>
                <input :value="modelValue.cp_context_limit ?? 5"
                    @input="updateField('cp_context_limit', parseNumber($event.target.value))" type="number" min="4"
                    max="20" step="1" :class="inputClass" :placeholder="t('p_modal_cp_context_limit_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_cp_context_limit_desc') }}
                </p>
                <div v-if="errors.cp_context_limit" class="text-red-500 text-xs mt-1">
                    {{ errors.cp_context_limit }}
                </div>
            </div>

            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_voice_mp_commands') }}
                </label>
                <input :value="modelValue.voice_mp_commands"
                    @input="updateField('voice_mp_commands', $event.target.value)" type="text" :class="inputClass"
                    :placeholder="t('p_modal_voice_mp_commands_ph')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_voice_mp_commands_desc') }}
                </p>
                <div v-if="errors.voice_mp_commands" class="text-red-500 text-xs mt-1">
                    {{ errors.voice_mp_commands }}
                </div>
            </div>

        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
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
    availablePresets: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:modelValue', 'success', 'error']);

const showRagHint = ref(false);
const copied = ref(false);

const ragSystemPrompt = `You are a search query formulator.
Given the conversation below, output ONLY a short
keyword-rich search query (maximum 15 words,
no punctuation, no explanation).
Output nothing else.`;

const copyPrompt = async () => {
    try {
        await navigator.clipboard.writeText(ragSystemPrompt);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        copied.value = false;
    }
};

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const updateField = (field, value) => {
    const updated = { ...props.modelValue, [field]: value };
    emit('update:modelValue', updated);
};

const parseNumber = (value) => {
    const num = parseInt(value);
    return isNaN(num) ? 5 : num;
};

const setNextPreset = (preset) => {
    if (preset.preset_code && preset.preset_code !== props.modelValue.preset_code) {
        const updated = { ...props.modelValue, preset_code_next: preset.preset_code };
        if (!props.modelValue.default_call_message) {
            updated.default_call_message = preset.description || `Continue with ${preset.name}`;
        }
        emit('update:modelValue', updated);
        emit('success', t('p_modal_next_preset_set', { name: preset.name }));
    }
};

const onNextPresetChange = (presetCode) => {
    const updated = { ...props.modelValue, preset_code_next: presetCode || '' };
    if (presetCode && !props.modelValue.default_call_message) {
        const preset = props.availablePresets.find(p => p.preset_code === presetCode);
        if (preset) updated.default_call_message = preset.description || `Continue with ${preset.name}`;
    }
    emit('update:modelValue', updated);
};

const otherPresets = computed(() =>
    props.availablePresets.filter(p => p.id !== props.modelValue.id)
);

const onRagPresetChange = (value) => {
    updateField('rag_preset_id', value ? parseInt(value) : null);
};

const showVoiceHint = ref(false);
const copiedVoice = ref(null);

const voiceExamples = [
    {
        label: 'Advisor',
        prompt: `You are the agent's inner advisor.
Given the conversation below, offer ONE short practical suggestion
or question the agent should consider before responding.
Maximum 2 sentences. No preamble.`
    },
    {
        label: 'Conscience',
        prompt: `You are the agent's conscience.
If you sense any ethical concern, bias, or potential harm in the conversation,
raise it briefly. If everything seems fine, stay silent (output nothing).
Maximum 1 sentence.`
    },
    {
        label: 'Skeptic',
        prompt: `You are the agent's inner skeptic.
Identify ONE assumption or weak point in the current reasoning.
Be blunt. Maximum 1 sentence.`
    },
    {
        label: 'Muse',
        prompt: `You are the agent's creative muse.
Offer ONE unexpected angle, metaphor, or idea related to the conversation.
Maximum 1 sentence. Be surprising.`
    },
];

const copyVoicePrompt = async (example) => {
    try {
        await navigator.clipboard.writeText(example.prompt);
        copiedVoice.value = example.label;
        setTimeout(() => { copiedVoice.value = null; }, 2000);
    } catch {
        copiedVoice.value = null;
    }
};

const onVoicePresetChange = (value) => {
    updateField('voice_preset_id', value ? parseInt(value) : null);
};
</script>