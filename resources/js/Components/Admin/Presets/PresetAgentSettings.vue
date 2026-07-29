<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('p_modal_agent') }}
            </h4>
        </div>

        <div class="space-y-6">

            <!-- Agent Result Mode -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_agent_result_mode') }}
                </label>
                <select :value="modelValue.agent_result_mode"
                    @input="updateField('agent_result_mode', $event.target.value)" :class="inputClass">
                    <option value="tool_calls">{{ t('p_modal_agent_result_tool_calls') }}</option>
                    <option value="internal">{{ t('p_modal_agent_result_internal') }}</option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_agent_result_mode_desc_' + modelValue.agent_result_mode) }}
                </p>
                <div v-if="errors.agent_result_mode" class="text-red-500 text-xs mt-1">
                    {{ errors.agent_result_mode }}
                </div>
            </div>

            <!-- Input Mode -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_input_mode') }}
                </label>
                <select :value="modelValue.input_mode" @input="updateField('input_mode', $event.target.value)"
                    :class="inputClass">
                    <option value="pool">{{ t('p_modal_input_mode_pool') }}</option>
                    <option value="single">{{ t('p_modal_input_mode_single') }}</option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_input_mode_desc') }}
                </p>
                <div v-if="errors.input_mode" class="text-red-500 text-xs mt-1">
                    {{ errors.input_mode }}
                </div>
            </div>

            <!-- Max Context Limit -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_max_context_limit') }}
                </label>
                <input :value="modelValue.max_context_limit"
                    @input="updateField('max_context_limit', parseNumber($event.target.value))" type="number" min="1"
                    max="100" step="1" :class="inputClass" :placeholder="t('p_modal_max_context_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_max_context_limit_desc') }}
                </p>
                <div v-if="errors.max_context_limit" class="text-red-500 text-xs mt-1">
                    {{ errors.max_context_limit }}
                </div>
            </div>

            <!-- Max Context Limit Extended -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_max_context_limit_extended') }}
                </label>
                <input :value="modelValue.max_context_limit_extended"
                    @input="updateField('max_context_limit_extended', $event.target.value === '' ? null : parseNumber($event.target.value))"
                    type="number" min="1" max="100" step="1" :class="inputClass"
                    :placeholder="t('p_modal_max_context_extended_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_max_context_limit_extended_desc') }}
                </p>
                <div v-if="errors.max_context_limit_extended" class="text-red-500 text-xs mt-1">
                    {{ errors.max_context_limit_extended }}
                </div>
            </div>

            <!-- ────────────────────────────────────────────────────────── -->
            <!-- Pre-pass (reasoning)                                       -->
            <!-- ────────────────────────────────────────────────────────── -->
            <div :class="[
                'rounded-xl border p-4 space-y-4',
                isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200'
            ]">
                <div>
                    <h5 :class="['text-sm font-semibold mb-0.5', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_pre_pass_title') }}
                    </h5>
                    <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_pre_pass_desc') }}
                    </p>
                </div>

                <!-- Always-on toggle -->
                <label :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                    <input :checked="modelValue.pre_pass_enabled"
                        @change="updateField('pre_pass_enabled', $event.target.checked)" type="checkbox"
                        class="w-4 h-4 rounded text-indigo-600" />
                    <span class="text-sm font-medium">{{ t('p_modal_pre_pass_enabled') }}</span>
                </label>
                <p :class="['text-xs -mt-2 ml-7', isDark ? 'text-gray-500' : 'text-gray-400']">
                    {{ t('p_modal_pre_pass_enabled_desc') }}
                </p>

                <!-- Instruction — always available: used both by always-on mode
                     and by the on-demand Reflect plugin trigger. -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_pre_pass_instruction') }}
                    </label>
                    <textarea :value="modelValue.pre_pass_instruction"
                        @input="updateField('pre_pass_instruction', $event.target.value)" rows="4" :class="inputClass"
                        :placeholder="t('p_modal_pre_pass_instruction_ph')"></textarea>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_pre_pass_instruction_desc') }}
                    </p>
                    <div v-if="errors.pre_pass_instruction" class="text-red-500 text-xs mt-1">
                        {{ errors.pre_pass_instruction }}
                    </div>
                </div>
            </div>

            <!-- Loop Interval -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_loop_interval_sec') }}
                </label>
                <input :value="modelValue.loop_interval"
                    @input="updateField('loop_interval', parseNumber($event.target.value))" type="number" min="1"
                    max="3600" step="1" :class="inputClass" :placeholder="t('p_modal_loop_interval_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_loop_interval_sec_desc') }}
                </p>
                <div v-if="errors.loop_interval" class="text-red-500 text-xs mt-1">
                    {{ errors.loop_interval }}
                </div>
            </div>

            <!-- Relative dates in pool -->
            <label :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                <input :checked="modelValue.pool_relative_dates"
                    @change="updateField('pool_relative_dates', $event.target.checked)" type="checkbox"
                    class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm font-medium">{{ t('p_modal_pool_relative_dates') }}</span>
            </label>

            <!-- Pulse dates toggle -->
            <label :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                <input :checked="modelValue.pulse_dates" @change="updateField('pulse_dates', $event.target.checked)"
                    type="checkbox" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm font-medium">{{ t('p_modal_pulse_dates') }}</span>
            </label>

            <!-- Turn Trigger -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_turn_trigger') }}
                </label>
                <select :value="modelValue.turn_trigger" @input="updateField('turn_trigger', $event.target.value)"
                    :class="inputClass">
                    <option value="none">{{ t('p_modal_turn_trigger_none') }}</option>
                    <option value="no_speak">{{ t('p_modal_turn_trigger_no_speak') }}</option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_turn_trigger_desc') }}
                </p>
                <div v-if="errors.turn_trigger" class="text-red-500 text-xs mt-1">
                    {{ errors.turn_trigger }}
                </div>
            </div>

            <!-- Disabled Plugins -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_plugins_disabled') }}
                </label>
                <input :value="modelValue.plugins_disabled"
                    @input="updateField('plugins_disabled', $event.target.value)" type="text" :class="inputClass"
                    :placeholder="t('p_modal_disabled_plugins_ph')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_disabled_plugins_desc') }}
                </p>
                <div v-if="errors.plugins_disabled" class="text-red-500 text-xs mt-1">
                    {{ errors.plugins_disabled }}
                </div>

                <!-- Plugin suggestions (if available) -->
                <div v-if="availablePlugins && availablePlugins.length > 0" class="mt-2">
                    <p :class="['text-xs font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                        {{ t('p_modal_available_plugins') }}:
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <button v-for="plugin in availablePlugins" :key="plugin.name" type="button"
                            @click="toggleDisabledPlugin(plugin.name)" :class="[
                                'inline-flex items-center px-2 py-1 text-xs rounded-md transition-colors',
                                isPluginDisabled(plugin.name)
                                    ? (isDark ? 'bg-red-900 text-red-200 hover:bg-red-800' : 'bg-red-100 text-red-800 hover:bg-red-200')
                                    : (isDark ? 'bg-green-900 text-green-200 hover:bg-green-800' : 'bg-green-100 text-green-800 hover:bg-green-200')
                            ]" :title="plugin.description">
                            <svg v-if="isPluginDisabled(plugin.name)" class="w-3 h-3 mr-1" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <svg v-else class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7"></path>
                            </svg>
                            {{ plugin.name }}
                        </button>
                    </div>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                        {{ t('p_modal_click_to_toggle_plugins') }}
                    </p>
                </div>
            </div>

            <!-- Pre-run Commands -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_pre_run_commands') }}
                </label>
                <input :value="modelValue.pre_run_commands"
                    @input="updateField('pre_run_commands', $event.target.value)" type="text" :class="inputClass"
                    :placeholder="t('p_modal_pre_run_commands_ph')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_pre_run_commands_desc') }}
                </p>
                <div v-if="errors.pre_run_commands" class="text-red-500 text-xs mt-1">
                    {{ errors.pre_run_commands }}
                </div>
            </div>

            <!-- ────────────────────────────────────────────────────────── -->
            <!-- Cross-preset execution                                     -->
            <!-- ────────────────────────────────────────────────────────── -->
            <div :class="[
                'rounded-xl border p-4 space-y-4',
                isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200'
            ]">
                <div>
                    <h5 :class="['text-sm font-semibold mb-0.5', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_cross_preset_title') }}
                    </h5>
                    <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_cross_preset_desc') }}
                    </p>
                </div>

                <!-- Target preset selector -->
                <div>
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('p_modal_target_preset') }}
                    </label>
                    <select :value="modelValue.target_preset_id"
                        @input="updateField('target_preset_id', $event.target.value ? parseInt($event.target.value) : null)"
                        :class="inputClass">
                        <option :value="null">— {{ t('p_modal_target_preset_none') }} —</option>
                        <option v-for="p in availableTargetPresets" :key="p.id" :value="p.id">
                            {{ p.name }}
                            <template v-if="p.preset_code"> ({{ p.preset_code }})</template>
                        </option>
                    </select>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('p_modal_target_preset_desc') }}
                    </p>
                    <div v-if="errors.target_preset_id" class="text-red-500 text-xs mt-1">
                        {{ errors.target_preset_id }}
                    </div>
                </div>

                <!-- Whitelist — only shown when target is selected -->
                <Transition enter-active-class="transition-all duration-200 ease-out"
                    enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition-all duration-150 ease-in"
                    leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1">
                    <div v-if="modelValue.target_preset_id" class="space-y-3">
                        <div>
                            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('p_modal_target_plugins_whitelist') }}
                            </label>
                            <input :value="modelValue.target_plugins_whitelist"
                                @input="updateField('target_plugins_whitelist', $event.target.value || null)"
                                type="text" :class="inputClass"
                                :placeholder="t('p_modal_target_plugins_whitelist_ph')" />
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('p_modal_target_plugins_whitelist_desc') }}
                            </p>
                            <div v-if="errors.target_plugins_whitelist" class="text-red-500 text-xs mt-1">
                                {{ errors.target_plugins_whitelist }}
                            </div>
                        </div>

                        <!-- Whitelist plugin badges — only cross-preset capable plugins -->
                        <div v-if="crossPresetPlugins.length > 0">
                            <p :class="['text-xs font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('p_modal_cross_preset_capable_plugins') }}:
                            </p>
                            <div class="flex flex-wrap gap-1">
                                <button v-for="plugin in crossPresetPlugins" :key="plugin.name" type="button"
                                    @click="toggleWhitelistPlugin(plugin.name)" :class="[
                                        'inline-flex items-center px-2 py-1 text-xs rounded-md transition-colors',
                                        isPluginWhitelisted(plugin.name)
                                            ? (isDark ? 'bg-indigo-900 text-indigo-200 hover:bg-indigo-800' : 'bg-indigo-100 text-indigo-800 hover:bg-indigo-200')
                                            : (isDark ? 'bg-gray-600 text-gray-400 hover:bg-gray-500' : 'bg-gray-100 text-gray-500 hover:bg-gray-200')
                                    ]" :title="plugin.description">
                                    <svg v-if="isPluginWhitelisted(plugin.name)" class="w-3 h-3 mr-1" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <svg v-else class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 4v16m8-8H4"></path>
                                    </svg>
                                    {{ plugin.name }}
                                </button>
                            </div>
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                                {{ t('p_modal_cross_preset_click_to_toggle') }}
                            </p>
                        </div>
                    </div>
                </Transition>
            </div>

        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
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
    availablePlugins: {
        type: Array,
        default: () => []
    },
    availablePresets: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:modelValue']);

// ── Styles ─────────────────────────────────────────────────────────────────

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

// ── Computed ───────────────────────────────────────────────────────────────

/**
 * Presets available as cross-preset targets — everyone except self.
 * modelValue.id is null on create, so the filter is safe either way.
 */
const availableTargetPresets = computed(() =>
    props.availablePresets.filter(p => p.id !== props.modelValue.id)
);

/**
 * Plugins that support cross-preset execution (allow_cross_preset === true).
 * Backend passes this flag per plugin in availablePlugins.
 * Falls back gracefully if the flag is absent (older API).
 */
const crossPresetPlugins = computed(() =>
    props.availablePlugins.filter(p => p.allow_cross_preset === true)
);

const disabledPluginsList = computed(() => {
    const disabled = props.modelValue.plugins_disabled || '';
    return disabled.split(',').map(p => p.trim()).filter(p => p.length > 0);
});

const whitelistedPluginsList = computed(() => {
    const whitelist = props.modelValue.target_plugins_whitelist || '';
    return whitelist.split(',').map(p => p.trim()).filter(p => p.length > 0);
});

// ── Helpers ────────────────────────────────────────────────────────────────

const updateField = (field, value) => {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
};

const parseNumber = (value) => {
    const num = parseInt(value);
    return isNaN(num) ? 0 : num;
};

// plugins_disabled toggle
const isPluginDisabled = (pluginName) => disabledPluginsList.value.includes(pluginName);

const toggleDisabledPlugin = (pluginName) => {
    const list = [...disabledPluginsList.value];
    const idx = list.indexOf(pluginName);
    if (idx === -1) list.push(pluginName);
    else list.splice(idx, 1);
    updateField('plugins_disabled', list.join(', '));
};

// target_plugins_whitelist toggle
const isPluginWhitelisted = (pluginName) => whitelistedPluginsList.value.includes(pluginName);

const toggleWhitelistPlugin = (pluginName) => {
    const list = [...whitelistedPluginsList.value];
    const idx = list.indexOf(pluginName);
    if (idx === -1) list.push(pluginName);
    else list.splice(idx, 1);
    updateField('target_plugins_whitelist', list.length > 0 ? list.join(', ') : null);
};
</script>