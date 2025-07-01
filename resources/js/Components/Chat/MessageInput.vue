<template>
  <div :class="[
    'p-4 border-t flex-shrink-0',
    isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
  ]">
    <form @submit.prevent="handleSubmit" class="flex space-x-3">
      <div class="flex-1 relative">
        <textarea ref="messageInput" v-model="content" :disabled="disabled || isProcessing"
          :placeholder="t('chat_input_ph')" :class="[
            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all resize-none',
            'px-4 py-3 text-sm leading-relaxed',
            isDark
              ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
              : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
          ]" rows="1" @input="autoResize" @keydown.enter.exact.prevent="handleSubmit"></textarea>

        <!-- Processing indicator -->
        <div v-if="isProcessing" class="absolute right-3 top-3">
          <div class="flex space-x-1">
            <div :class="[
              'w-2 h-2 rounded-full animate-bounce',
              isDark ? 'bg-indigo-400' : 'bg-indigo-500'
            ]" style="animation-delay: 0ms"></div>
            <div :class="[
              'w-2 h-2 rounded-full animate-bounce',
              isDark ? 'bg-indigo-400' : 'bg-indigo-500'
            ]" style="animation-delay: 150ms"></div>
            <div :class="[
              'w-2 h-2 rounded-full animate-bounce',
              isDark ? 'bg-indigo-400' : 'bg-indigo-500'
            ]" style="animation-delay: 300ms"></div>
          </div>
        </div>
      </div>

      <button type="submit" :disabled="disabled || !content.trim() || isProcessing" :class="[
        'px-6 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
        'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
        'enabled:hover:scale-105 enabled:active:scale-95',
        isDark
          ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
          : 'bg-indigo-600 hover:bg-indigo-700 text-white'
      ]">
        <svg v-if="disabled || isProcessing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
          </path>
        </svg>
        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 9-18 9v-7l12-2L3 10V3z">
          </path>
        </svg>
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
  disabled: Boolean,
  isProcessing: Boolean,
  isDark: Boolean
});

const emit = defineEmits(['send']);

const content = ref('');
const messageInput = ref(null);

/**
 * Auto-resize textarea based on content
 */
function autoResize() {
  nextTick(() => {
    if (messageInput.value) {
      messageInput.value.style.height = 'auto';
      messageInput.value.style.height = Math.min(messageInput.value.scrollHeight, 120) + 'px';
    }
  });
}

/**
 * Handle form submission
 */
function handleSubmit() {
  if (!content.value.trim() || props.disabled || props.isProcessing) return;

  emit('send', content.value);
  content.value = '';

  if (messageInput.value) {
    messageInput.value.style.height = 'auto';
  }

  focusInput();
}

/**
 * Focus input field
 */
function focusInput() {
  nextTick(() => {
    if (messageInput.value) {
      messageInput.value.focus();
    }
  });
}

/**
 * Set content (for mentions)
 */
function setContent(newContent) {
  content.value = newContent;
  nextTick(() => {
    autoResize();
    focusInput();

    // Set cursor at end
    if (messageInput.value) {
      const length = messageInput.value.value.length;
      messageInput.value.setSelectionRange(length, length);
    }
  });
}

// Expose methods to parent
defineExpose({
  focusInput,
  setContent
});
</script>

<style>
/* Custom animations */
@keyframes chat-bounce {

  0%,
  80%,
  100% {
    transform: scale(0);
  }

  40% {
    transform: scale(1);
  }
}

.animate-bounce {
  animation: chat-bounce 1.4s infinite ease-in-out both;
}
</style>
