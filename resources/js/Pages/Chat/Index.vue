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

    <!-- Mobile tabs overlay -->
    <div v-if="mobileTabsOpen" class="fixed inset-0 z-40 lg:hidden" @click="mobileTabsOpen = false">
      <div class="absolute inset-0 bg-black opacity-50"></div>
      <div @click.stop :class="[
        'fixed inset-y-0 right-0 z-50 w-80 transform transition-transform duration-300 ease-in-out',
        'flex flex-col shadow-xl pt-20',
        isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
      ]">
        <TabsPanel :users="localUsers" :availablePresets="availablePresets" :selectedPresetId="selectedPresetId"
          :isDark="isDark" :presetMetadata="presetMetadata" :user="user" @mentionUser="handleMobileMention"
          @showAbout="showAboutModal = true" @selectPreset="handlePresetSelect" @editPreset="handleEditPreset" />
      </div>
    </div>

    <!-- Sidebar -->
    <ChatSidebar :mobileMenuOpen="mobileMenuOpen" :isDark="isDark" :appName="page.props.app_name" :user="user"
      :isAdmin="isAdmin" :currentPreset="currentPreset" v-model:isChatActive="isChatActive"
      v-model:selectedExportFormat="selectedExportFormat" :exportFormats="exportFormats" :isExporting="isExporting"
      @closeMobileMenu="mobileMenuOpen = false" @clearHistory="showClearHistory" @editPreset="editCurrentPreset"
      @toggleTheme="toggleTheme" @exportChat="exportChat" />

    <!-- Main chat area -->
    <div class="flex-1 flex flex-col overflow-hidden lg:ml-0">
      <!-- Mobile header -->
      <MobileHeader :isDark="isDark" :appName="page.props.app_name" @openMenu="mobileMenuOpen = true"
        @openUsers="mobileTabsOpen = true" />

      <div class="lg:hidden h-20"></div>

      <!-- Loading state -->
      <div v-if="isInitialLoading" :class="[
        'flex-1 flex items-center justify-center',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
      ]">
        <div class="text-center">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto mb-4"></div>
          <p :class="isDark ? 'text-gray-400' : 'text-gray-600'">{{ t('chat_loading_messages') }}</p>
        </div>
      </div>

      <!-- Messages -->
      <ChatMessages v-if="!isInitialLoading" ref="messagesComponent" :messages="localMessages" :pagination="pagination"
        :isDark="isDark" :appName="page.props.app_name" @deleteMessage="deleteMessage"
        @scrollUpdate="handleScrollUpdate" @loadOlder="handleLoadOlder" :showAgentResults="showAgentResults"
        :showCommandResults="showCommandResults" :isBackgroundRefreshing="isBackgroundRefreshing"
        class="pb-24 lg:pb-0" />

      <!-- Scroll to bottom button -->
      <ScrollToBottomButton v-if="hasUnreadMessages || !isUserAtBottom" :hasUnreadMessages="hasUnreadMessages"
        :isDark="isDark" @click="scrollToBottomForced" class="bottom-24 lg:bottom-4" />

      <!-- Message input -->
      <MessageInput ref="messageInputComponent" :disabled="form.processing" :isProcessing="isProcessing"
        :isDark="isDark" @send="sendMessage" />
    </div>

    <!-- Tabs panel (desktop) -->
    <div class="hidden lg:flex lg:w-80 flex-col border-l">
      <TabsPanel :users="localUsers" :availablePresets="availablePresets" :selectedPresetId="selectedPresetId"
        :isDark="isDark" :presetMetadata="presetMetadata" :user="user" @mentionUser="mentionUser"
        @showAbout="showAboutModal = true" @selectPreset="handlePresetSelect" @editPreset="handleEditPreset" />
    </div>

    <!-- Preset Modal -->
    <PresetModal v-if="showEditPresetModal" :placeholders="placeholders" :preset="editingPreset" :engines="engines"
      @close="closeEditModal" @save="saveAnyPreset" />

    <!-- About Modal -->
    <AboutModal v-if="showAboutModal" :isDark="isDark" :available-presets="availablePresets"
      :appName="page.props.app_name" @close="showAboutModal = false" />

    <!-- Clear History Modal -->
    <ClearHistoryModal :show="showClearHistoryModal" :isDark="isDark" :isClearing="isClearingHistory"
      @close="showClearHistoryModal = false" @confirm="handleClearHistory" />
  </div>
</template>

<script setup>
import { useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

import PageTitle from '@/Components/PageTitle.vue';
import PresetModal from '@/Components/Admin/Presets/PresetModal.vue';
import TabsPanel from '@/Components/Chat/TabsPanel.vue';
import ChatSidebar from '@/Components/Chat/ChatSidebar.vue';
import MobileHeader from '@/Components/Chat/MobileHeader.vue';
import ChatMessages from '@/Components/Chat/ChatMessages.vue';
import ScrollToBottomButton from '@/Components/Chat/ScrollToBottomButton.vue';
import MessageInput from '@/Components/Chat/MessageInput.vue';
import AboutModal from '@/Components/Chat/AboutModal.vue';
import ClearHistoryModal from '@/Components/Chat/ClearHistoryModal.vue';

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
  showCommandResults: Boolean,
  pagination: Object,
});

const { isDark, toggleTheme } = useTheme();

const mobileMenuOpen = ref(false);
const mobileTabsOpen = ref(false);

const form = useForm({
  content: '',
});

const isAdmin = computed(() => props.user && props.user.is_admin);

// Get current preset from availablePresets based on selectedPresetId
const currentPreset = computed(() => {
  if (props.availablePresets && selectedPresetId.value) {
    const found = props.availablePresets.find(preset => preset.id === selectedPresetId.value);
    if (found) return found;
  }
  return props.currentPreset || null;
});

const messagesComponent = ref(null);
const messageInputComponent = ref(null);

const showAboutModal = ref(false);
const showClearHistoryModal = ref(false);
const isClearingHistory = ref(false);

const {
  localMessages,
  isProcessing,
  localUsers,
  isUserAtBottom,
  shouldAutoRefresh,
  hasUnreadMessages,
  presetMetadata,
  isInitialLoading,
  isBackgroundRefreshing,
  pagination,
  loadLatestMessages,
  refreshMessages,
  startFrequentRefresh,
  stopFrequentRefresh,
  startUsersRefresh,
  loadOlderMessages,
  switchPreset,
  resetChatState,
  cleanup: cleanupChat
} = useChat(props);

const {
  selectedPresetId,
  isChatActive,
  selectedExportFormat,
  isExporting,
  showEditPresetModal,
  editingPreset,
  engines,
  updatePresetSettings,
  exportChat,
  editCurrentPreset,
  closeEditModal,
} = usePresets(props, isAdmin);

/**
 * Handle preset selection with chat reload
 */
async function handlePresetSelect(presetId) {
  if (presetId === selectedPresetId.value) return;

  selectedPresetId.value = presetId;

  // Switch to new preset and reload messages
  const success = await switchPreset(presetId);

  if (success && isAdmin.value) {
    updatePresetSettings();
  }

  // Scroll to bottom after loading
  setTimeout(() => {
    if (messagesComponent.value) {
      messagesComponent.value.scrollToBottom();
    }
  }, 100);
}

/**
 * Handle load older messages request
 */
function handleLoadOlder() {
  loadOlderMessages();
}

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
  mobileTabsOpen.value = false;

  isUserAtBottom.value = true;
  shouldAutoRefresh.value = true;

  form.content = content;
  form.post(route('chat.message'), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      isProcessing.value = false;

      startFrequentRefresh();

      requestAnimationFrame(() => {
        if (messagesComponent.value) {
          messagesComponent.value.scrollToBottom();
        }
        if (messageInputComponent.value) {
          messageInputComponent.value.focusInput();
        }
      });
    },
    onError: (errors) => {
      console.error('Send message error:', errors);
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
 * Show clear history modal
 */
function showClearHistory() {
  showClearHistoryModal.value = true;
}

/**
 * Handle clear history confirmation
 */
async function handleClearHistory(options) {
  if (isClearingHistory.value) return;

  try {
    isClearingHistory.value = true;

    // Build request data based on selected options
    const requestData = {};
    if (options.clearMessages) requestData.clear_messages = true;
    if (options.clearMemory) requestData.clear_memory = true;
    if (options.clearVectorMemory) requestData.clear_vector_memory = true;

    await router.post(route('chat.clear'), requestData, {
      preserveScroll: true,
      onSuccess: () => {
        if (options.clearMessages) {
          resetChatState();

          stopFrequentRefresh();

          setTimeout(async () => {
            try {
              await loadLatestMessages(null, false);
              startFrequentRefresh();

              setTimeout(() => {
                if (messagesComponent.value) {
                  messagesComponent.value.scrollToBottom();
                }
              }, 100);
            } catch (error) {
              console.error('Failed to reload messages after clear:', error);
            }
          }, 100);
        }

        showClearHistoryModal.value = false;
      },
      onError: (errors) => {
        console.error('Error clearing history:', errors);
      }
    });
  } catch (error) {
    console.error('Failed to clear history:', error);
  } finally {
    isClearingHistory.value = false;
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
 * Handle preset editing from tabs panel (can edit any preset)
 * @param {number} presetId - ID of preset to edit, if not provided uses current
 */
async function handleEditPreset(presetId = null) {
  if (!isAdmin.value) return;

  if (presetId) {
    // Load full preset data before editing
    await loadAndEditPreset(presetId);
  } else {
    editCurrentPreset();
  }
}

/**
 * Load full preset data and open for editing
 * @param {number} presetId 
 */
async function loadAndEditPreset(presetId) {
  try {
    console.log('Loading full preset data for ID:', presetId);

    // Use admin route to get full preset data
    const response = await axios.get(route('admin.presets.show', presetId));

    if (response.data && response.data.success && response.data.data) {
      const fullPresetData = response.data.data;
      console.log('Loaded full preset data:', fullPresetData);

      // Use the full data (same as admin panel)
      editingPreset.value = fullPresetData;
      showEditPresetModal.value = true;
    } else {
      console.error('Invalid response format:', response.data);
      throw new Error('Invalid response format');
    }
  } catch (error) {
    console.error('Failed to load preset data:', error);

    // Fallback to using available data if API fails
    const presetToEdit = props.availablePresets.find(p => p.id === presetId);
    if (presetToEdit) {
      console.log('Using fallback preset data:', presetToEdit);
      editingPreset.value = presetToEdit;
      showEditPresetModal.value = true;
    }
  }
}

/**
 * Save any preset using existing chat route
 */
async function saveAnyPreset(presetData) {
  if (!presetData.id) {
    console.error('No preset ID provided for saving');
    return;
  }

  try {

    const response = await axios.put(route('chat.preset.update', presetData.id), presetData);

    if (response.data.success) {

      const presetIndex = props.availablePresets.findIndex(p => p.id === presetData.id);
      if (presetIndex !== -1) {
        Object.assign(props.availablePresets[presetIndex], {
          name: presetData.name,
          description: presetData.description,
          engine_name: presetData.engine_name,
          engine_display_name: presetData.engine_display_name || presetData.engine_name,
          engine_config: presetData.engine_config,
          model: presetData.engine_config?.model || null,
          is_active: presetData.is_active,
          is_default: presetData.is_default
        });
      }

      if (presetData.id === selectedPresetId.value) {
        setTimeout(async () => {
          try {
            await refreshMessages();
          } catch (error) {
            console.error('Failed to refresh after preset update:', error);
          }
        }, 100);
      }

      showEditPresetModal.value = false;
    } else {
      console.error('Failed to update preset:', response.data.errors || response.data.message);
    }
  } catch (error) {
    console.error('Error updating preset:', error);

    if (error.response?.status === 422) {
      console.error('Validation errors:', error.response.data);

      if (error.response.data.errors) {
        console.error('Field errors:', error.response.data.errors);
        Object.entries(error.response.data.errors).forEach(([field, messages]) => {
          console.error(`Field "${field}":`, messages);
        });
      }
    }
  }
}

/**
 * Handle mobile mention (closes mobile panel)
 */
function handleMobileMention(userName) {
  mentionUser(userName);
  mobileTabsOpen.value = false;
}

watch([selectedPresetId, isChatActive], () => {
  if (isAdmin.value) {
    updatePresetSettings();
  }
});

watch(() => props.users, (newUsers) => {
  localUsers.value = [...newUsers];
}, { deep: true });

watch(() => props.currentPresetId, (newValue) => {
  selectedPresetId.value = newValue;
});

watch(() => props.chatActive, (newValue) => {
  isChatActive.value = newValue;
});

watch(() => localMessages.value.length, (newLength, oldLength) => {
  if (newLength > oldLength && shouldAutoRefresh.value) {
    requestAnimationFrame(() => {
      setTimeout(() => {
        if (messagesComponent.value) {
          messagesComponent.value.scrollToBottom();
        }
      }, 50); // Reduced delay for better responsiveness
    });
  }
});

watch(selectedPresetId, (newPresetId, oldPresetId) => {
  if (newPresetId !== oldPresetId && isAdmin.value) {
    isChatActive.value = false;
  }
});

onMounted(async () => {
  localUsers.value = [...props.users];

  try {
    await loadLatestMessages();

    if (messagesComponent.value) {
      messagesComponent.value.scrollToBottom();
    }
  } catch (error) {
    console.error('Failed to load initial messages:', error);
  }

  refreshInterval = setInterval(() => {
    refreshMessages().catch(error => {
      console.error('Periodic refresh failed:', error);
    });
  }, 5000);

  startUsersRefresh();
});

onBeforeUnmount(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }

  cleanupChat();

  if (typeof window !== 'undefined') {
    let id = requestAnimationFrame(() => { });
    cancelAnimationFrame(id);
  }
});

let refreshInterval = null;
</script>

<style>
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
