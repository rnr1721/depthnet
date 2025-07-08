<template>
  <div class="text-center py-4">
    <button @click="loadOlderMessages" :disabled="isLoading || !hasMorePages" :class="[
      'px-6 py-2 rounded-lg border transition-all duration-200',
      'flex items-center space-x-2 mx-auto',
      isDark
        ? 'bg-gray-700 border-gray-600 text-gray-200 hover:bg-gray-600 disabled:opacity-50'
        : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50'
    ]">
      <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
        </path>
      </svg>
      <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
      </svg>
      <span>
        {{ isLoading ? t('chat_loading') : t('chat_load_older_messages') }}
        <span v-if="!isLoading && totalPages" class="text-sm opacity-75">
          ({{ currentPage - 1 }} / {{ totalPages }})
        </span>
      </span>
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
  hasMorePages: Boolean,
  currentPage: Number,
  totalPages: Number,
  isDark: Boolean
});

const emit = defineEmits(['loadOlder']);

const isLoading = ref(false);

/**
 * Load older messages
 */
async function loadOlderMessages() {
  if (isLoading.value || !props.hasMorePages) return;

  isLoading.value = true;

  try {
    emit('loadOlder');
  } finally {
    setTimeout(() => {
      isLoading.value = false;
    }, 500);
  }
}
</script>
