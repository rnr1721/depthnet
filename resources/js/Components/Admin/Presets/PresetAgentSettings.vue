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
                    <option value="separate">{{ t('p_modal_agent_result_separate') }}</option>
                    <option value="attached">{{ t('p_modal_agent_result_attached') }}</option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_agent_result_mode_desc') }}
                </p>
                <div v-if="errors.agent_result_mode" class="text-red-500 text-xs mt-1">
                    {{ errors.agent_result_mode }}
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

            <!-- Max Context Limit -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_max_context_limit') }}
                </label>
                <input :value="modelValue.max_context_limit"
                    @input="updateField('max_context_limit', parseNumber($event.target.value))" type="number" min="1"
                    max="50" step="1" :class="inputClass" :placeholder="t('p_modal_max_context_placeholder')" />
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('p_modal_max_context_limit_desc') }}
                </p>
                <div v-if="errors.max_context_limit" class="text-red-500 text-xs mt-1">
                    {{ errors.max_context_limit }}
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
                            @click="togglePlugin(plugin.name)" :class="[
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
    }
});

const emit = defineEmits(['update:modelValue']);

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const disabledPluginsList = computed(() => {
    const disabled = props.modelValue.plugins_disabled || '';
    return disabled.split(',').map(p => p.trim()).filter(p => p.length > 0);
});

const updateField = (field, value) => {
    const updated = { ...props.modelValue, [field]: value };
    emit('update:modelValue', updated);
};

const parseNumber = (value) => {
    const num = parseInt(value);
    return isNaN(num) ? 0 : num;
};

const isPluginDisabled = (pluginName) => {
    return disabledPluginsList.value.includes(pluginName);
};

const togglePlugin = (pluginName) => {
    const currentList = disabledPluginsList.value;
    let newList;

    if (isPluginDisabled(pluginName)) {
        // Remove from disabled list (enable plugin)
        newList = currentList.filter(p => p !== pluginName);
    } else {
        // Add to disabled list (disable plugin)
        newList = [...currentList, pluginName];
    }

    const newValue = newList.join(', ');
    updateField('plugins_disabled', newValue);
};
</script>
