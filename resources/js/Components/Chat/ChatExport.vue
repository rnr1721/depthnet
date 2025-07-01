<template>
  <div :class="[
    'p-4 rounded-xl border transition-colors',
    isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
  ]">
    <label :class="[
      'block text-sm font-medium mb-2',
      isDark ? 'text-gray-200' : 'text-gray-700'
    ]">{{ t('chat_export_format') || 'Dialog export' }}</label>

    <div class="space-y-3">
      <select :value="selectedExportFormat" @input="$emit('update:selectedExportFormat', $event.target.value)" :class="[
        'w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
        'px-3 py-2 text-sm',
        isDark
          ? 'bg-gray-600 text-white ring-gray-500'
          : 'bg-white text-gray-900 ring-gray-300'
      ]">
        <option value="">{{ t('chat_select_format') || 'Please choose format' }}</option>
        <option v-for="format in exportFormats" :key="format.name" :value="format.name">
          {{ format.displayName }} (.{{ format.extension }})
        </option>
      </select>

      <label class="flex items-center space-x-2 cursor-pointer">
        <input type="checkbox" :checked="includeThinking"
          @input="$emit('update:includeThinking', $event.target.checked)"
          class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <span :class="[
          'text-sm',
          isDark ? 'text-gray-200' : 'text-gray-700'
        ]">{{ t('chat_include_thinking') || 'Turn on AI think messages' }}</span>
      </label>

      <button @click="$emit('export')" :disabled="!selectedExportFormat || isExporting" :class="[
        'w-full px-4 py-2 rounded-lg font-medium transition-all',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        isDark
          ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
          : 'bg-indigo-600 hover:bg-indigo-700 text-white'
      ]">
        <svg v-if="isExporting" class="w-4 h-4 animate-spin inline mr-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
          </path>
        </svg>
        {{ isExporting ? (t('chat_exporting') || 'Export...') : (t('chat_export') || 'Экспортировать') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  selectedExportFormat: String,
  includeThinking: Boolean,
  exportFormats: Array,
  isExporting: Boolean,
  isDark: Boolean
});

defineEmits(['update:selectedExportFormat', 'update:includeThinking', 'export']);
</script>
