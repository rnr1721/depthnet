<template>
  <PageTitle :title="t('chat')" />
  <div :class="[
    'flex h-screen overflow-hidden transition-colors duration-300',
    isDark ? 'bg-gray-900' : 'bg-gray-50'
  ]">
    <!-- Mobile menu overlay -->
    <div v-if="mobileMenuOpen" class="fixed inset-0 z-40 lg:hidden" @click="mobileMenuOpen = false">
      <div class="absolute inset-0 bg-black opacity-50"></div>
    </div>

    <!-- Mobile users overlay -->
    <div v-if="mobileUsersOpen" class="fixed inset-0 z-40 lg:hidden" @click="mobileUsersOpen = false">
      <div class="absolute inset-0 bg-black opacity-50"></div>
      <div :class="[
        'fixed inset-y-0 right-0 z-50 w-80 transform transition-transform duration-300 ease-in-out',
        'flex flex-col shadow-xl',
        isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
      ]">
        <UsersList :users="localUsers" :isDark="isDark" :presetMetadata="presetMetadata"
          @mentionUser="handleMobileMention" @showAbout="showAboutModal = true" />
      </div>
    </div>

    <!-- Sidebar -->
    <ChatSidebar :mobileMenuOpen="mobileMenuOpen" :isDark="isDark" :appName="page.props.app_name" :user="user"
      :isAdmin="isAdmin" v-model:selectedPresetId="selectedPresetId" :availablePresets="availablePresets"
      :currentPreset="currentPreset" v-model:isChatActive="isChatActive" v-model:showThinking="showThinking"
      v-model:selectedExportFormat="selectedExportFormat" v-model:includeThinking="includeThinking"
      :exportFormats="exportFormats" :isExporting="isExporting" @closeMobileMenu="mobileMenuOpen = false"
      @clearHistory="confirmClearHistory" @editPreset="editCurrentPreset" @toggleTheme="toggleTheme"
      @exportChat="exportChat" />

    <!-- Main chat area -->
    <div class="flex-1 flex flex-col overflow-hidden lg:ml-0">
      <!-- Mobile header -->
      <MobileHeader :isDark="isDark" :appName="page.props.app_name" @openMenu="mobileMenuOpen = true"
        @openUsers="mobileUsersOpen = true" />

      <!-- Messages -->
      <ChatMessages ref="messagesComponent" :messages="localMessages" :showThinking="showThinking" :isDark="isDark"
        :appName="page.props.app_name" @deleteMessage="deleteMessage" @scrollUpdate="handleScrollUpdate"
        :showAgentResults="showAgentResults" :showCommandResults="showCommandResults" />

      <!-- Scroll to bottom button -->
      <ScrollToBottomButton v-if="hasUnreadMessages || !isUserAtBottom" :hasUnreadMessages="hasUnreadMessages"
        :isDark="isDark" @click="scrollToBottomForced" />

      <!-- Message input -->
      <MessageInput ref="messageInputComponent" :disabled="form.processing" :isProcessing="isProcessing"
        :isDark="isDark" @send="sendMessage" />
    </div>

    <!-- Users list (desktop) -->
    <div class="hidden lg:flex lg:w-80 flex-col border-l">
      <UsersList :users="localUsers" :isDark="isDark" :presetMetadata="presetMetadata" @mentionUser="mentionUser"
        @showAbout="showAboutModal = true" />
    </div>

    <!-- Preset Modal -->
    <PresetModal v-if="showEditPresetModal" :placeholders="placeholders" :preset="editingPreset" :engines="engines"
      @close="closeEditModal" @save="saveCurrentPreset" />
    <AboutModal v-if="showAboutModal" :isDark="isDark" :appName="page.props.app_name" @close="showAboutModal = false" />
  </div>
</template>

<script setup>
import { Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

import PageTitle from '@/Components/PageTitle.vue';
import PresetModal from '@/Components/Admin/Presets/PresetModal.vue';
import UsersList from '@/Components/Chat/UsersList.vue';
import ChatSidebar from '@/Components/Chat/ChatSidebar.vue';
import MobileHeader from '@/Components/Chat/MobileHeader.vue';
import ChatMessages from '@/Components/Chat/ChatMessages.vue';
import ScrollToBottomButton from '@/Components/Chat/ScrollToBottomButton.vue';
import MessageInput from '@/Components/Chat/MessageInput.vue';
import AboutModal from '@/Components/Chat/AboutModal.vue';

import { useTheme } from '@/Composables/useTheme';
import { useChat } from '@/Composables/useChat';
import { usePresets } from '@/Composables/usePresets';

const { t } = useI18n();
const page = usePage();

const props = defineProps({
  messages: Array,
  user: Object,
  availablePresets: Array,
  currentPresetId: Number,
  currentPreset: Object,
  chatActive: Boolean,
  exportFormats: Array,
  mode: String,
  engines: Object,
  users: Array,
  toLabel: String,
  placeholders: Object,
  presetMetadata: Object,
  showAgentResults: Boolean,
  showCommandResults: Boolean
});

const { isDark, toggleTheme } = useTheme();

const mobileMenuOpen = ref(false);
const mobileUsersOpen = ref(false);

const form = useForm({
  content: '',
});

const isAdmin = computed(() => props.user && props.user.is_admin);

const messagesComponent = ref(null);
const messageInputComponent = ref(null);

const showAboutModal = ref(false);

const {
  localMessages,
  isProcessing,
  localUsers,
  isUserAtBottom,
  shouldAutoRefresh,
  hasUnreadMessages,
  presetMetadata,
  refreshMessages,
  startFrequentRefresh,
  stopFrequentRefresh,
  startUsersRefresh,
  stopUsersRefresh
} = useChat(props);

const {
  selectedPresetId,
  isChatActive,
  selectedExportFormat,
  includeThinking,
  isExporting,
  showEditPresetModal,
  editingPreset,
  engines,
  showThinking,
  updatePresetSettings,
  exportChat,
  editCurrentPreset,
  closeEditModal,
  saveCurrentPreset
} = usePresets(props, isAdmin);

/**
 * Handle scroll updates from messages component
 */
function handleScrollUpdate(scrollData) {
  if (scrollData.isAtBottom !== undefined) {
    isUserAtBottom.value = scrollData.isAtBottom;
  }
  if (scrollData.shouldAutoRefresh !== undefined) {
    shouldAutoRefresh.value = scrollData.shouldAutoRefresh;
  }
  if (scrollData.hasUnreadMessages !== undefined) {
    hasUnreadMessages.value = scrollData.hasUnreadMessages;
  }
}

/**
 * Force scroll to bottom
 */
function scrollToBottomForced() {
  if (messagesComponent.value) {
    messagesComponent.value.scrollToBottomForced();
  }
}

/**
 * Send message
 */
function sendMessage(content) {
  if (!content.trim() || form.processing || isProcessing.value) return;

  isProcessing.value = true;
  mobileMenuOpen.value = false;

  isUserAtBottom.value = true;
  shouldAutoRefresh.value = true;

  form.content = content;
  form.post(route('chat.message'), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      isProcessing.value = false;
      startFrequentRefresh();
      if (messagesComponent.value) {
        messagesComponent.value.scrollToBottom();
      }
      if (messageInputComponent.value) {
        messageInputComponent.value.focusInput();
      }
    },
    onError: () => {
      isProcessing.value = false;
      if (messageInputComponent.value) {
        messageInputComponent.value.focusInput();
      }
    }
  });
}

/**
 * Delete message
 */
function deleteMessage(messageId) {
  if (confirm(t('chat_delete_message_confirm') || 'Are you sure you want to delete this message?')) {
    router.delete(route('chat.delete-message', messageId), {
      preserveScroll: true,
      onSuccess: () => {
        const index = localMessages.value.findIndex(msg => msg.id === messageId);
        if (index !== -1) {
          localMessages.value.splice(index, 1);
        }
      },
      onError: (errors) => {
        console.error('Error deleting message:', errors);
      }
    });
  }
}

/**
 * Clear chat history
 */
function confirmClearHistory() {
  if (confirm(t('chat_delete_all_confirm'))) {
    router.post(route('chat.clear'));
  }
}

/**
 * Mention user in input
 */
function mentionUser(userName) {
  const mention = `${props.toLabel} ${userName}`;
  const currentContent = form.content.toLowerCase();
  const mentionLower = mention.toLowerCase();

  if (currentContent.includes(mentionLower)) {
    if (messageInputComponent.value) {
      messageInputComponent.value.focusInput();
    }
    return;
  }

  const mentionPattern = new RegExp(`^${props.toLabel}\\s+\\w+,\\s*`, 'i');

  let newContent;
  if (mentionPattern.test(form.content)) {
    newContent = form.content.replace(mentionPattern, `${mention}, `);
  } else {
    newContent = `${mention}, ${form.content}`;
  }

  if (messageInputComponent.value) {
    messageInputComponent.value.setContent(newContent);
  }
}

/**
 * Handle mobile mention (closes mobile panel)
 */
function handleMobileMention(userName) {
  mentionUser(userName);
  mobileUsersOpen.value = false;
}

// Watchers
watch([selectedPresetId, isChatActive], () => {
  if (isAdmin.value) {
    updatePresetSettings();
  }
});

watch(() => props.messages, (newMessages) => {
  localMessages.value = [...newMessages];
}, { deep: true });

watch(() => props.users, (newUsers) => {
  localUsers.value = [...newUsers];
}, { deep: true });

watch(() => props.currentPresetId, (newValue) => {
  selectedPresetId.value = newValue;
});

watch(() => props.chatActive, (newValue) => {
  isChatActive.value = newValue;
});

watch(() => props.presetMetadata, (newMetadata) => {
  if (newMetadata) {
    presetMetadata.value = newMetadata;
  }
}, { deep: true });

watch(() => localMessages.value.length, (newLength, oldLength) => {
  if (newLength > oldLength && shouldAutoRefresh.value) {
    setTimeout(() => {
      if (messagesComponent.value) {
        messagesComponent.value.scrollToBottom();
      }
    }, 100);
  }
});

watch(selectedPresetId, (newPresetId, oldPresetId) => {
  if (newPresetId !== oldPresetId && isAdmin.value) {
    isChatActive.value = false;
  }
});

onMounted(() => {
  localMessages.value = [...props.messages];
  localUsers.value = [...props.users];

  if (messagesComponent.value) {
    messagesComponent.value.scrollToBottom();
  }

  // Start refresh intervals
  refreshInterval = setInterval(refreshMessages, 10000);
  startUsersRefresh();
});

onBeforeUnmount(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }
  stopUsersRefresh();
});

// Initialize refresh interval
let refreshInterval = null;
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
