<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="$emit('close')"></div>

        <!-- Modal -->
        <div
            :class="['relative w-full max-w-md backdrop-blur-sm border shadow-2xl rounded-2xl p-6', isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200']">
            <h3 :class="['text-lg font-bold mb-1', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('exchange_export_title') }}
            </h3>
            <p :class="['text-sm mb-5', isDark ? 'text-gray-400' : 'text-gray-600']">
                {{ t('exchange_export_intro', { name: itemName }) }}
            </p>

            <!-- What travels -->
            <div
                :class="['rounded-xl p-3 mb-5 text-xs', isDark ? 'bg-gray-900 bg-opacity-50 text-gray-400' : 'bg-gray-50 text-gray-600']">
                {{ t('exchange_export_note_keys') }}
            </div>

            <!-- include_skills (presets only) -->
            <label v-if="kind === 'preset'" class="flex items-center gap-3 mb-5 cursor-pointer">
                <input type="checkbox" v-model="includeSkills"
                    :class="['w-4 h-4 rounded', isDark ? 'accent-indigo-500' : 'accent-indigo-600']" />
                <span :class="['text-sm', isDark ? 'text-gray-300' : 'text-gray-700']">
                    {{ t('exchange_export_include_skills') }}
                </span>
            </label>

            <div class="flex justify-end gap-3">
                <button @click="$emit('close')"
                    :class="['px-4 py-2 rounded-xl text-sm font-medium transition-colors', isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700']">
                    {{ t('exchange_action_cancel') }}
                </button>
                <button @click="doExport"
                    class="px-4 py-2 rounded-xl text-sm font-medium bg-indigo-600 hover:bg-indigo-700 text-white transition-all transform hover:scale-105">
                    {{ t('exchange_action_download') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    show: { type: Boolean, default: false },
    isDark: { type: Boolean, default: false },
    kind: { type: String, required: true },   // 'preset' | 'agent'
    itemId: { type: [Number, String], default: null },  // null while the modal is closed
    itemName: { type: String, default: '' },
});

defineEmits(['close']);

const includeSkills = ref(false);

const doExport = () => {
    const base = props.kind === 'preset'
        ? route('admin.exchange.export.preset', props.itemId)
        : route('admin.exchange.export.agent', props.itemId);

    const url = props.kind === 'preset'
        ? `${base}?include_skills=${includeSkills.value ? 1 : 0}`
        : base;

    // Streamed download — navigate the browser to the endpoint.
    window.location.href = url;
};
</script>