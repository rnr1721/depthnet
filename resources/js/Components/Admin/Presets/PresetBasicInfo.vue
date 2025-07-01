<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">
        <h4 :class="['text-lg font-semibold mb-4', isDark ? 'text-white' : 'text-gray-900']">
            {{ t('p_modal_main_info') }}
        </h4>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Preset Name -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_preset_name') }} *
                </label>
                <input :value="modelValue.name" @input="updateField('name', $event.target.value)" type="text" required
                    :class="inputClass" :placeholder="t('p_modal_name_example_ph')" />
                <div v-if="errors.name" class="text-red-500 text-xs mt-1">
                    {{ errors.name }}
                </div>
            </div>

            <!-- Engine Selection -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('p_modal_preset_engine') }} *
                </label>
                <select :value="modelValue.engine_name" @change="updateField('engine_name', $event.target.value)"
                    required :class="inputClass">
                    <option value="">{{ t('p_modal_choose_engine') }}</option>
                    <option v-for="(engine, name) in enabledEngines" :key="name" :value="name">
                        {{ engine.display_name }}
                    </option>
                </select>
                <div v-if="errors.engine_name" class="text-red-500 text-xs mt-1">
                    {{ errors.engine_name }}
                </div>
            </div>
        </div>

        <!-- Description -->
        <div class="mt-6">
            <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('p_modal_preset_description') }}
            </label>
            <textarea :value="modelValue.description" @input="updateField('description', $event.target.value)" rows="3"
                :class="inputClass" :placeholder="t('p_modal_preset_description_ph')"></textarea>
        </div>

        <!-- Checkboxes -->
        <div class="mt-6 flex flex-wrap gap-6">
            <label :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                <input :checked="modelValue.is_active" @change="updateField('is_active', $event.target.checked)"
                    type="checkbox" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm font-medium">{{ t('p_modal_active') }}</span>
            </label>

            <label v-if="!hideDefaultOption"
                :class="['flex items-center space-x-3 cursor-pointer', isDark ? 'text-white' : 'text-gray-900']">
                <input :checked="modelValue.is_default" @change="updateField('is_default', $event.target.checked)"
                    type="checkbox" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm font-medium">{{ t('p_modal_default') }}</span>
            </label>
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
    engines: {
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
    hideDefaultOption: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue', 'engine-changed']);

const enabledEngines = computed(() => {
    return Object.fromEntries(
        Object.entries(props.engines).filter(([name, engine]) => engine.enabled)
    );
});

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400' : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const updateField = (field, value) => {
    const updated = { ...props.modelValue, [field]: value };
    emit('update:modelValue', updated);

    // Emit special event for engine changes
    if (field === 'engine_name') {
        emit('engine-changed', value);
    }
};
</script>
