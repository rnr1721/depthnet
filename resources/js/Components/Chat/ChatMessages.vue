<template>
  <div :class="[
    'chat-scroll flex-1 min-h-0 overflow-y-auto p-3 sm:p-6 space-y-4',
    isDark ? 'bg-gray-900' : 'bg-gray-50'
  ]" ref="messagesContainer">

    <!-- Load older messages button -->
    <LoadOlderMessagesButton v-if="pagination && pagination.has_more_pages" :hasMorePages="pagination.has_more_pages"
      :currentPage="pagination.current_page" :totalPages="pagination.last_page" :isDark="isDark"
      :isLoading="isLoadingOlder" @loadOlder="handleLoadOlder" />

    <!-- Empty state -->
    <div v-if="filteredMessages.length === 0" :class="[
      'text-center my-8 p-8 rounded-2xl relative',
      isDark ? 'text-gray-400 bg-gray-800' : 'text-gray-500 bg-white'
    ]">
      <div v-if="isBackgroundRefreshing" class="absolute top-4 right-4">
        <div class="flex items-center space-x-2">
          <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-indigo-600"></div>
          <span :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">{{ t('chat_checking_messages')
            }}</span>
        </div>
      </div>

      <div class="text-4xl mb-4">💬</div>
      <p class="text-lg font-medium">{{ t('chat_please_write_message') }}</p>
      <p class="text-sm mt-2 opacity-75">{{ t('chat_start_dialog_with_ai') }}</p>
    </div>

    <!-- Messages -->
    <ChatMessage v-for="message in filteredMessages" :key="message.id" :message="message" :is-admin="isAdmin"
      :isDark="isDark" :appName="appName" @delete="$emit('deleteMessage', message.id)"
      :showAgentResults="showAgentResults" :showCommandResults="showCommandResults" :hasTTS="hasTTS"
      :speakingMessageId="speakingMessageId" :presetName="presetName" @speak="$emit('speakMessage', $event)" />

    <!--
      The "Refreshing..." line for the non-empty state was removed on purpose:
      it appeared every 5 seconds at the bottom and made the list jump.
    -->
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import ChatMessage from './ChatMessage.vue';
import LoadOlderMessagesButton from './LoadOlderMessagesButton.vue';

const { t } = useI18n();

const props = defineProps({
  messages: Array,
  isAdmin: { type: Boolean, default: false },
  isDark: Boolean,
  appName: String,
  showAgentResults: Boolean,
  showCommandResults: Boolean,
  pagination: Object,
  isBackgroundRefreshing: Boolean,
  isLoadingOlder: { type: Boolean, default: false },
  hasTTS: { type: Boolean, default: false },
  speakingMessageId: { type: [Number, String, null], default: null },
  presetName: { type: String, default: null },
});

const emit = defineEmits(['deleteMessage', 'scrollUpdate', 'speakMessage', 'loadOlder']);

const messagesContainer = ref(null);
const BOTTOM_THRESHOLD = 100;

const filteredMessages = computed(() => props.messages || []);

// "Was the user at the bottom before the last layout change?"
// Used to keep the list pinned when the container shrinks (keyboard opens).
let stickToBottom = true;
let resizeObserver = null;

// ── Load older: restore position when the new page is actually rendered ──
let pendingRestore = null;

function handleLoadOlder() {
  const el = messagesContainer.value;
  pendingRestore = el ? { height: el.scrollHeight, top: el.scrollTop } : null;
  emit('loadOlder');
}

watch(() => props.messages?.[0]?.id, async () => {
  if (!pendingRestore) return;
  const saved = pendingRestore;
  pendingRestore = null;
  await nextTick();
  const el = messagesContainer.value;
  if (el) el.scrollTop = saved.top + (el.scrollHeight - saved.height);
});

watch(() => props.isLoadingOlder, (loading) => {
  // Request finished without new messages -> drop pending restore
  if (!loading) setTimeout(() => { pendingRestore = null; }, 0);
});

// ── Bottom tracking ──
function isAtBottomNow() {
  const el = messagesContainer.value;
  if (!el) return true;
  return el.scrollTop + el.clientHeight >= el.scrollHeight - BOTTOM_THRESHOLD;
}

function checkIfAtBottom() {
  const isAtBottom = isAtBottomNow();
  stickToBottom = isAtBottom;
  emit('scrollUpdate', { isAtBottom, shouldAutoRefresh: isAtBottom });
  return isAtBottom;
}

function scrollToBottom() {
  // Two frames: let images/markdown lay out first.
  requestAnimationFrame(() => requestAnimationFrame(() => {
    const el = messagesContainer.value;
    if (el) el.scrollTop = el.scrollHeight;
    stickToBottom = true;
  }));
}

function scrollToBottomForced() {
  const el = messagesContainer.value;
  if (el) el.scrollTop = el.scrollHeight;
  stickToBottom = true;
  emit('scrollUpdate', { isAtBottom: true, shouldAutoRefresh: true, hasUnreadMessages: false });
}

onMounted(() => {
  const el = messagesContainer.value;
  if (el) {
    el.addEventListener('scroll', checkIfAtBottom, { passive: true });

    // Keyboard open/close, orientation change, input growing: container height
    // changes WITHOUT a scroll event. If the user was at the bottom, keep it there.
    if ('ResizeObserver' in window) {
      resizeObserver = new ResizeObserver(() => {
        if (stickToBottom) el.scrollTop = el.scrollHeight;
      });
      resizeObserver.observe(el);
    }
  }
  scrollToBottom();
});

onBeforeUnmount(() => {
  messagesContainer.value?.removeEventListener('scroll', checkIfAtBottom);
  resizeObserver?.disconnect();
});

defineExpose({ scrollToBottom, scrollToBottomForced, checkIfAtBottom });
</script>

<style>
.chat-scroll {
  /* no scroll chaining / pull-to-refresh from the message list */
  overscroll-behavior: contain;
  /* we restore position manually after prepending older messages */
  overflow-anchor: none;
  -webkit-overflow-scrolling: touch;
}

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

/* Chat page: the document itself never scrolls, only the message list. */
html.chat-viewport-locked,
html.chat-viewport-locked body {
  height: 100%;
  overflow: hidden;
  overscroll-behavior: none;
  /* Any gap (keyboard animation, browser bars) blends with the chat, not white */
  background-color: #f9fafb;
  /* gray-50 */
}

html.dark.chat-viewport-locked,
html.dark.chat-viewport-locked body {
  background-color: #111827;
  /* gray-900 */
}

/* Root of Chat/Index.vue: follows the layout viewport; --app-height only when
   the keyboard overlays the page (iOS), see useAppViewport.js */
.chat-app-root {
  position: fixed;
  inset: 0;
  height: var(--app-height, auto);
}
</style>
