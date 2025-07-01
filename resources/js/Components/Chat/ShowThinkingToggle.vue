<template>
  <div :class="[
    'p-4 rounded-xl border transition-colors',
    isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
  ]">
    <label class="flex items-center justify-between cursor-pointer">
      <span :class="[
        'text-sm font-medium',
        isDark ? 'text-gray-200' : 'text-gray-700'
      ]">{{ t('chat_show_thiking') }}</span>
      <input type="checkbox" :checked="showThinking" @input="$emit('update:showThinking', $event.target.checked)"
        class="sr-only">
      <div :class="[
        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
        showThinking ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-300')
      ]">
        <span :class="[
          'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
          showThinking ? 'translate-x-6' : 'translate-x-1'
        ]"></span>
      </div>
    </label>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  showThinking: Boolean,
  isDark: Boolean
});

defineEmits(['update:showThinking']);
</script>
