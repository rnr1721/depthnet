<template>
  <div class="relative">
    <div class="absolute bottom-4 right-4 z-10">
      <button @click="$emit('click')" :class="[
        'flex items-center space-x-2 px-4 py-2 rounded-full shadow-lg transition-all transform hover:scale-105',
        'border backdrop-blur-sm',
        isDark
          ? 'bg-gray-800 border-gray-600 text-gray-200 hover:bg-gray-700'
          : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-50'
      ]">
        <span v-if="hasUnreadMessages" :class="[
          'text-sm font-medium',
          isDark ? 'text-indigo-400' : 'text-indigo-600'
        ]">
          {{ t('chat_new_messages') }}
        </span>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3">
          </path>
        </svg>
        <!-- New messages indicator -->
        <span v-if="hasUnreadMessages"
          class="absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full animate-pulse"></span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  hasUnreadMessages: Boolean,
  isDark: Boolean
});

defineEmits(['click']);
</script>
