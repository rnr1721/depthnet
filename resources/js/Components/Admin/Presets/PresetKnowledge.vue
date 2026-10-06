<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('knowledge_title') }}
                </h4>
                <span v-if="modelValue.knowledge_formulator_preset_id" :class="[
                    'text-xs px-2 py-0.5 rounded-full font-medium',
                    isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-700'
                ]">{{ t('knowledge_formulator_active') }}</span>
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
                {{ t('knowledge_how_it_works') }}
            </button>
        </div>

        <p :class="['text-xs mb-4', isDark ? 'text-gray-400' : 'text-gray-500']">
            {{ t('knowledge_desc') }}
        </p>

        <!-- How it works guide -->
        <Transition enter-active-class="transition-all duration-200 ease-out overflow-hidden"
            enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-[32rem]"
            leave-active-class="transition-all duration-150 ease-in overflow-hidden"
            leave-from-class="opacity-100 max-h-[32rem]" leave-to-class="opacity-0 max-h-0">
            <div v-if="showGuide" class="mb-4 space-y-3">
                <div
                    :class="['rounded-xl p-4 text-xs space-y-2 leading-relaxed', isDark ? 'bg-gray-900 text-gray-300' : 'bg-gray-100 text-gray-700 ring-1 ring-gray-200']">
                    <p>{{ t('knowledge_guide_p1') }}</p>
                    <p>{{ t('knowledge_guide_p2') }}</p>
                    <p>{{ t('knowledge_guide_p3') }}</p>
                </div>
                <ul :class="['text-xs space-y-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    <li class="flex gap-2">
                        <span class="text-indigo-500 flex-shrink-0">→</span>
                        {{ t('knowledge_guide_direct') }}
                    </li>
                    <li class="flex gap-2">
                        <span class="text-indigo-500 flex-shrink-0">→</span>
                        {{ t('knowledge_guide_formulator') }}
                    </li>
                    <li class="flex gap-2">
                        <span class="text-indigo-500 flex-shrink-0">→</span>
                        {{ t('knowledge_guide_recall') }}
                    </li>
                </ul>
            </div>
        </Transition>

        <div class="space-y-5">
            <!-- Formulator preset select -->
            <div>
                <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                    {{ t('knowledge_formulator_preset') }}
                </label>
                <select :value="modelValue.knowledge_formulator_preset_id ?? ''"
                    @change="updateField('knowledge_formulator_preset_id', $event.target.value ? parseInt($event.target.value) : null)"
                    :class="selectClass">
                    <option value="">{{ t('knowledge_formulator_none') }}</option>
                    <option v-for="p in availableFormulatorPresets" :key="p.id" :value="p.id">
                        {{ p.name }} ({{ p.engine_name }})
                    </option>
                </select>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ t('knowledge_formulator_hint') }}
                </p>
                <p v-if="errors.knowledge_formulator_preset_id" class="text-xs mt-1 text-red-500">
                    {{ errors.knowledge_formulator_preset_id }}
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
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
    availablePresets: {
        type: Array,
        default: () => []
    },
    errors: {
        type: Object,
        default: () => ({})
    }
});

const emit = defineEmits(['update:modelValue']);

const showGuide = ref(false);

// Any active preset except this one can serve as the formulator.
const availableFormulatorPresets = computed(() =>
    props.availablePresets.filter(p => p.id !== props.modelValue.id)
);

const selectClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark
        ? 'bg-gray-600 text-white ring-gray-500'
        : 'bg-white text-gray-900 ring-gray-300'
]);

const updateField = (field, value) => {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
};

// NOTE: the formulator preset only matters when the Knowledge plugin's
// input_mode is "formulator". That flag lives in the plugin config, not on the
// preset (modelValue), so this component can't see it — hence the always-visible
// select plus an honest hint. If you ever prop input_mode in from the plugin
// config form, wrap the select block in v-if / :disabled on it here.
</script>