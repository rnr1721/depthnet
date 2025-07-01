<template>
  <div :class="[
    'p-4 rounded-xl border transition-colors',
    isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
  ]">
    <label :class="[
      'block text-sm font-medium mb-2',
      isDark ? 'text-gray-200' : 'text-gray-700'
    ]">{{ t('chat_preset_selection') || 'Preset' }}</label>

    <div class="flex gap-2">
      <select :value="selectedPresetId" @input="$emit('update:selectedPresetId', parseInt($event.target.value))" :class="[
        'flex-1 rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
        'px-3 py-2 text-sm',
        isDark
          ? 'bg-gray-600 text-white ring-gray-500'
          : 'bg-white text-gray-900 ring-gray-300'
      ]">
        <option v-for="preset in availablePresets" :key="preset.id" :value="preset.id">
          {{ preset.name }} ({{ preset.engine_display_name }})
        </option>
      </select>

      <!-- Preset edit button -->
      <button @click="$emit('editPreset')" :class="[
        'px-3 py-2 rounded-lg text-sm font-medium transition-all hover:scale-105 focus:outline-none focus:ring-2 focus:ring-indigo-500',
        isDark
          ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
          : 'bg-indigo-600 hover:bg-indigo-700 text-white'
      ]" :title="t('chat_edit_current_preset') || 'Edit current preset'">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
          </path>
        </svg>
      </button>
    </div>

    <!-- Show current preset info -->
    <div v-if="currentPreset" class="mt-2 text-xs opacity-75">
      <div>{{ t('chat_current_model') || 'Model' }}: {{ currentPreset.model || 'N/A' }}</div>
      <div v-if="currentPreset.description" class="mt-1">{{ currentPreset.description }}</div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  selectedPresetId: Number,
  availablePresets: Array,
  currentPreset: Object,
  isDark: Boolean
});

defineEmits(['update:selectedPresetId', 'editPreset']);
</script>
