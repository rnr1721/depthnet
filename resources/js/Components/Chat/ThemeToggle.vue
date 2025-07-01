<template>
  <div :class="[
    'p-4 rounded-xl border transition-colors',
    isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
  ]">
    <div class="flex items-center justify-between">
      <span :class="[
        'text-sm font-medium',
        isDark ? 'text-gray-200' : 'text-gray-700'
      ]">{{ t('chat_dark_mode') }}</span>
      <button @click="$emit('toggle')" :class="[
        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 cursor-pointer',
        isDark ? 'bg-indigo-600' : 'bg-gray-300'
      ]">
        <span :class="[
          'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
          isDark ? 'translate-x-6' : 'translate-x-1'
        ]"></span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  isDark: Boolean
});

defineEmits(['toggle']);
</script>
