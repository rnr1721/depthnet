<template>
  <div :class="[
    'flex-1 overflow-y-auto p-4 sm:p-6 space-y-4',
    isDark ? 'bg-gray-900' : 'bg-gray-50'
  ]" ref="messagesContainer">
    <!-- Empty state -->
    <div v-if="filteredMessages.length === 0" :class="[
      'text-center my-8 p-8 rounded-2xl',
      isDark ? 'text-gray-400 bg-gray-800' : 'text-gray-500 bg-white'
    ]">
      <div class="text-4xl mb-4">💬</div>
      <p class="text-lg font-medium">{{ t('chat_please_write_message') }}</p>
      <p class="text-sm mt-2 opacity-75">{{ t('chat_start_dialog_with_ai') }}</p>
    </div>

    <!-- Messages -->
    <ChatMessage v-for="message in filteredMessages" :key="message.id" :message="message" :isDark="isDark"
      :appName="appName" @delete="$emit('deleteMessage', message.id)" :showAgentResults="showAgentResults"
      :showCommandResults="showCommandResults" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import ChatMessage from './ChatMessage.vue';

const { t } = useI18n();

const props = defineProps({
  messages: Array,
  showThinking: Boolean,
  isDark: Boolean,
  appName: String,
  showAgentResults: Boolean,
  showCommandResults: Boolean
});

const emit = defineEmits(['deleteMessage', 'scrollUpdate']);

const messagesContainer = ref(null);

const filteredMessages = computed(() => {
  if (props.showThinking) {
    return props.messages;
  } else {
    return props.messages.filter(message => message.is_visible_to_user);
  }
});

/**
 * Check if user is at bottom of messages container
 */
function checkIfAtBottom() {
  if (!messagesContainer.value) return true;

  const container = messagesContainer.value;
  const threshold = 100;

  const isAtBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - threshold;

  emit('scrollUpdate', {
    isAtBottom,
    shouldAutoRefresh: isAtBottom
  });

  return isAtBottom;
}

/**
 * Scroll to bottom of messages
 */
function scrollToBottom() {
  setTimeout(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
  }, 100);
}

/**
 * Force scroll to bottom
 */
function scrollToBottomForced() {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
  }
  emit('scrollUpdate', {
    isAtBottom: true,
    shouldAutoRefresh: true,
    hasUnreadMessages: false
  });
}

onMounted(() => {
  if (messagesContainer.value) {
    messagesContainer.value.addEventListener('scroll', checkIfAtBottom);
  }
  scrollToBottom();
});

onBeforeUnmount(() => {
  if (messagesContainer.value) {
    messagesContainer.value.removeEventListener('scroll', checkIfAtBottom);
  }
});

// Expose methods to parent
defineExpose({
  scrollToBottom,
  scrollToBottomForced,
  checkIfAtBottom
});
</script>

<style>
/* Custom scrollbar styling */
.overflow-y-auto::-webkit-scrollbar {
  width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background-color: #d1d5db;
  border-radius: 9999px;
}

.dark .overflow-y-auto::-webkit-scrollbar-thumb {
  background-color: #4b5563;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background-color: #9ca3af;
}

.dark .overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background-color: #6b7280;
}
</style>
