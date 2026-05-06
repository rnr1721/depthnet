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
          :isDark="isDark" :presetMetadata="presetMetadata" :user="user" :presetActiveMap="presetActiveMap"
          :agents="agents" @mentionUser="handleMobileMention" @showAbout="showAboutModal = true"
          @selectPreset="handlePresetSelect" @editPreset="handleEditPreset"
          @togglePresetActive="handleTogglePresetActive" @agentFilterChanged="selectedAgentId = $event" />
      </div>
    </div>

    <!-- Sidebar -->
    <ChatSidebar :mobileMenuOpen="mobileMenuOpen" :isDark="isDark" :appName="page.props.app_name" :user="user"
      :isAdmin="isAdmin" :currentPreset="currentPreset" :currentPresetId="selectedPresetId"
      v-model:isChatActive="isChatActive" v-model:selectedExportFormat="selectedExportFormat"
      :exportFormats="exportFormats" :isExporting="isExporting" :selectedAgentId="selectedAgentId"
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
        :isDark="isDark" :is-admin="isAdmin" :appName="page.props.app_name" :showAgentResults="showAgentResults"
        :showCommandResults="showCommandResults" :isBackgroundRefreshing="isBackgroundRefreshing" :hasTTS="hasTTS"
        :presetName="currentPreset?.name" :speakingMessageId="currentlySpeakingId" @deleteMessage="deleteMessage"
        @scrollUpdate="handleScrollUpdate" @loadOlder="handleLoadOlder" @speakMessage="handleSpeakMessage"
        class="pb-40 lg:pb-0" />

      <!-- Scroll to bottom button -->
      <ScrollToBottomButton v-if="hasUnreadMessages || !isUserAtBottom" :hasUnreadMessages="hasUnreadMessages"
        :isDark="isDark" @click="scrollToBottomForced" class="bottom-24 lg:bottom-4" />

      <!-- Message input -->
      <MessageInput ref="messageInputComponent" :disabled="form.processing" :isProcessing="isProcessing"
        :isDark="isDark" :hasSTT="hasSTT" :isListening="isListening" :interimText="interimText" :hasTTS="hasTTS"
        :ttsEnabled="ttsEnabled" @send="sendMessage" @toggleMic="handleToggleMic" @toggleTTS="toggleTTS" />
    </div>

    <!-- Tabs panel (desktop) -->
    <div class="hidden lg:flex lg:w-80 flex-col border-l">
      <TabsPanel :users="localUsers" :availablePresets="availablePresets" :selectedPresetId="selectedPresetId"
        :isDark="isDark" :presetMetadata="presetMetadata" :user="user" :presetActiveMap="presetActiveMap"
        :agents="agents" @mentionUser="mentionUser" @showAbout="showAboutModal = true"
        @selectPreset="handlePresetSelect" @editPreset="handleEditPreset" @togglePresetActive="handleTogglePresetActive"
        @agentFilterChanged="selectedAgentId = $event" /> />
    </div>

    <!-- Preset Modal -->
    <PresetModal v-if="showEditPresetModal" :placeholders="currentPlaceholders" :preset="editingPreset"
      :engines="engines" :available-presets="availablePresets" @close="closeEditModal" @save="saveAnyPreset" />

    <!-- About Modal -->
    <AboutModal v-if="showAboutModal" :isDark="isDark" :available-presets="availablePresets"
      :appName="page.props.app_name" @close="showAboutModal = false" />

    <!-- Clear History Modal -->
    <ClearHistoryModal :show="showClearHistoryModal" :isDark="isDark" :isClearing="isClearingHistory"
      :agentName="currentPresetAgentName" @close="showClearHistoryModal = false" @confirm="handleClearHistory" />
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
import { useSpeech } from '@/Composables/useSpeech';
import { usePresets } from '@/Composables/usePresets';
import { useSelectedPreset } from '@/Composables/useSelectedPreset';

const { t } = useI18n();
const page = usePage();

const localeMap = { ru: 'ru-RU', en: 'en-US', uk: 'uk-UA', de: 'de-DE', fr: 'fr-FR', es: 'es-ES' };
const appLocale = page.props.locale;
const sttLang = localeMap[appLocale] || 'en-US';

const {
  hasTTS,
  hasSTT,
  ttsEnabled,
  isSpeaking,
  currentlySpeakingId,
  lastSpokenMessageId,
  resetInitialLoad,
  markInitialLoadDone,
  isListening,
  interimText,
  speakMessage,
  speakNewMessages,
  stopSpeaking,
  toggleTTS,
  toggleListening,
} = useSpeech({ sttLang });

const props = defineProps({
  messages: Array,
  user: Object,
  availablePresets: Array,
  currentPresetId: Number,
  currentPreset: Object,
  chatActive: Boolean,      // legacy global flag (kept for back-compat)
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
  agents: { type: Array, default: () => [] },
});

const { isDark, toggleTheme } = useTheme();
const { savePresetId } = useSelectedPreset();

const mobileMenuOpen = ref(false);
const mobileTabsOpen = ref(false);

const form = useForm({ content: '', files: [] });

const isAdmin = computed(() => props.user && props.user.is_admin);

// Derive current preset object from availablePresets
const currentPreset = computed(() => {
  if (props.availablePresets && selectedPresetId.value) {
    const found = props.availablePresets.find(p => p.id === selectedPresetId.value);
    if (found) return found;
  }
  return props.currentPreset || null;
});

const messagesComponent = ref(null);
const messageInputComponent = ref(null);

const showAboutModal = ref(false);
const showClearHistoryModal = ref(false);
const isClearingHistory = ref(false);

const selectedAgentId = ref(null);

// Find agent name for the currently selected preset — used in ClearHistoryModal
const currentPresetAgentName = computed(() => {
  if (!props.agents?.length || !selectedPresetId.value) return null;
  const agent = props.agents.find(a =>
    a.planner?.id === selectedPresetId.value ||
    a.roles?.some(r =>
      r.preset?.id === selectedPresetId.value ||
      r.validator?.id === selectedPresetId.value
    )
  );
  return agent?.name ?? null;
});

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
  cleanup: cleanupChat,
} = useChat(props);

const {
  selectedPresetId,
  isChatActive,         // computed: loop active for currently selected preset
  presetActiveMap,      // reactive map { presetId: bool }
  setPresetActive,      // (presetId, value) — toggle loop for any preset
  selectedExportFormat,
  isExporting,
  showEditPresetModal,
  editingPreset,
  engines,
  updatePresetSettings, // no-op kept for compat
  exportChat,
  editCurrentPreset,
  closeEditModal,
  currentPlaceholders,
} = usePresets(props, isAdmin);

async function handleToggleMic() {
  const text = await toggleListening();
  if (text && messageInputComponent.value) {
    messageInputComponent.value.insertRecognizedText(text);
  }
}

function handleSpeakMessage(message) {
  if (currentlySpeakingId.value === message.id) {
    stopSpeaking();
    return;
  }
  speakMessage(message);
}

// -------------------------------------------------------------------------
// Preset selection — UI only, no effect on running loops
// -------------------------------------------------------------------------

async function handlePresetSelect(presetId) {
  if (presetId === selectedPresetId.value) return;

  selectedPresetId.value = presetId;
  savePresetId(presetId); // persist for admin page links

  resetInitialLoad();

  // Load messages for the newly selected preset
  await switchPreset(presetId);

  setTimeout(() => {
    messagesComponent.value?.scrollToBottom();
  }, 100);
}

// -------------------------------------------------------------------------
// Loop toggle — independent from selection
// -------------------------------------------------------------------------

/**
 * Toggle the thinking loop for any preset (called from TabsPanel or Sidebar).
 */
function handleTogglePresetActive(presetId, value) {
  if (!isAdmin.value) return;
  setPresetActive(presetId, value);
}

// -------------------------------------------------------------------------
// Message sending — must include preset_id
// -------------------------------------------------------------------------

function sendMessage(content, files = []) {
  if (!content.trim() || form.processing || isProcessing.value) return;

  isProcessing.value = true;
  mobileMenuOpen.value = false;
  mobileTabsOpen.value = false;
  isUserAtBottom.value = true;
  shouldAutoRefresh.value = true;

  form.content = content;
  form.files = files;

  form.transform(data => ({
    ...data,
    preset_id: selectedPresetId.value,
  })).post(route('chat.message'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      form.reset();
      isProcessing.value = false;
      startFrequentRefresh();
      requestAnimationFrame(() => {
        messagesComponent.value?.scrollToBottom();
        messageInputComponent.value?.focusInput();
      });
    },
    onError: (errors) => {
      console.error('Send message error:', errors);
      isProcessing.value = false;
      messageInputComponent.value?.focusInput();
    },
  });
}

// -------------------------------------------------------------------------
// Scroll / older messages
// -------------------------------------------------------------------------

function handleLoadOlder() {
  loadOlderMessages();
}

function handleScrollUpdate(scrollData) {
  if (scrollData.isAtBottom !== undefined) isUserAtBottom.value = scrollData.isAtBottom;
  if (scrollData.shouldAutoRefresh !== undefined) shouldAutoRefresh.value = scrollData.shouldAutoRefresh;
  if (scrollData.hasUnreadMessages !== undefined) hasUnreadMessages.value = scrollData.hasUnreadMessages;
}

function scrollToBottomForced() {
  messagesComponent.value?.scrollToBottomForced();
}

// -------------------------------------------------------------------------
// Delete / clear history
// -------------------------------------------------------------------------

function deleteMessage(messageId) {
  if (confirm(t('chat_delete_message_confirm') || 'Are you sure you want to delete this message?')) {
    router.delete(route('chat.delete-message', messageId), {
      preserveScroll: true,
      onSuccess: () => {
        const index = localMessages.value.findIndex(msg => msg.id === messageId);
        if (index !== -1) localMessages.value.splice(index, 1);
      },
      onError: (errors) => console.error('Error deleting message:', errors),
    });
  }
}

function showClearHistory() {
  showClearHistoryModal.value = true;
}

async function handleClearHistory(options) {
  if (isClearingHistory.value) return;

  try {
    isClearingHistory.value = true;

    const requestData = { preset_id: selectedPresetId.value };
    if (options.clearMessages) requestData.clear_messages = true;
    if (options.clearMemory) requestData.clear_memory = true;
    if (options.clearVectorMemory) requestData.clear_vector_memory = true;
    if (options.clearWorkspace) requestData.clear_workspace = true;
    if (options.clearGoals) requestData.clear_goals = true;
    if (options.clearSkills) requestData.clear_skills = true;
    if (options.clearPerson) requestData.clear_person = true;
    if (options.clearHeart) requestData.clear_heart = true;
    if (options.clearJournal) requestData.clear_journal = true;
    if (options.clearAgent) requestData.clear_agent = true;

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
              setTimeout(() => messagesComponent.value?.scrollToBottom(), 100);
            } catch (error) {
              console.error('Failed to reload messages after clear:', error);
            }
          }, 100);
        }

        showClearHistoryModal.value = false;
      },
      onError: (errors) => console.error('Error clearing history:', errors),
    });
  } catch (error) {
    console.error('Failed to clear history:', error);
  } finally {
    isClearingHistory.value = false;
  }
}

// -------------------------------------------------------------------------
// Preset editing
// -------------------------------------------------------------------------

function mentionUser(userName) {
  const mention = `${props.toLabel} ${userName}`;
  const currentContent = form.content.toLowerCase();

  if (currentContent.includes(mention.toLowerCase())) {
    messageInputComponent.value?.focusInput();
    return;
  }

  const mentionPattern = new RegExp(`^${props.toLabel}\\s+\\w+,\\s*`, 'i');
  const newContent = mentionPattern.test(form.content)
    ? form.content.replace(mentionPattern, `${mention}, `)
    : `${mention}, ${form.content}`;

  messageInputComponent.value?.setContent(newContent);
}

async function handleEditPreset(presetId = null) {
  if (!isAdmin.value) return;

  if (presetId) {
    await loadAndEditPreset(presetId);
  } else {
    editCurrentPreset();
  }
}

async function loadAndEditPreset(presetId) {
  try {
    const response = await axios.get(route('admin.presets.show', presetId));

    if (response.data?.success && response.data.data) {
      editingPreset.value = response.data.data.preset;
      currentPlaceholders.value = response.data.data.placeholders ?? props.placeholders;
      showEditPresetModal.value = true;
    } else {
      throw new Error('Invalid response format');
    }
  } catch (error) {
    console.error('Failed to load preset data:', error);
    const presetToEdit = props.availablePresets?.find(p => p.id === presetId);
    if (presetToEdit) {
      editingPreset.value = presetToEdit;
      showEditPresetModal.value = true;
    }
  }
}

async function saveAnyPreset(presetData) {
  if (!presetData.id) return;

  try {
    const response = await axios.put(route('chat.preset.update', presetData.id), presetData);

    if (response.data.success) {
      const idx = props.availablePresets?.findIndex(p => p.id === presetData.id);
      if (idx !== -1) {
        Object.assign(props.availablePresets[idx], {
          name: presetData.name,
          description: presetData.description,
          engine_name: presetData.engine_name,
          engine_display_name: presetData.engine_display_name || presetData.engine_name,
          engine_config: presetData.engine_config,
          model: presetData.engine_config?.model || null,
          is_active: presetData.is_active,
          is_default: presetData.is_default,
        });
      }

      if (presetData.id === selectedPresetId.value) {
        setTimeout(async () => {
          try { await refreshMessages(); } catch { }
        }, 100);
      }

      showEditPresetModal.value = false;
    } else {
      console.error('Failed to update preset:', response.data.errors || response.data.message);
    }
  } catch (error) {
    console.error('Error updating preset:', error);

    if (error.response?.status === 422 && error.response.data.errors) {
      Object.entries(error.response.data.errors).forEach(([field, messages]) => {
        console.error(`Field "${field}":`, messages);
      });
    }
  }
}

function handleMobileMention(userName) {
  mentionUser(userName);
  mobileTabsOpen.value = false;
}

// -------------------------------------------------------------------------
// Watchers
// -------------------------------------------------------------------------

// Sync selectedPresetId from server-side prop changes
watch(() => props.currentPresetId, (newValue) => {
  selectedPresetId.value = newValue;
});

// Sync availablePresets chat_active flags when server re-renders
watch(() => props.availablePresets, (newPresets) => {
  if (!newPresets) return;
  for (const p of newPresets) {
    presetActiveMap.value[p.id] = !!p.chat_active;
  }
}, { deep: true });

watch(() => props.users, (newUsers) => {
  localUsers.value = [...newUsers];
}, { deep: true });

// Auto-scroll when new messages arrive
watch(() => localMessages.value.length, (newLength, oldLength) => {
  if (newLength > oldLength && shouldAutoRefresh.value) {
    requestAnimationFrame(() => {
      setTimeout(() => messagesComponent.value?.scrollToBottom(), 50);
    });

    // Auto-voice for new messages
    if (ttsEnabled.value && newLength > oldLength) {
      const newMessages = localMessages.value.slice(oldLength);
      speakNewMessages(newMessages);
    }
  }
});

// -------------------------------------------------------------------------
// Lifecycle
// -------------------------------------------------------------------------

onMounted(async () => {
  localUsers.value = [...props.users];

  // Persist the selected preset (restored or default) so admin links open the right one
  savePresetId(selectedPresetId.value);

  try {
    // Load messages for the restored/selected preset (may differ from server default)
    await loadLatestMessages(selectedPresetId.value);
    messagesComponent.value?.scrollToBottom();
    markInitialLoadDone();
  } catch (error) {
    console.error('Failed to load initial messages:', error);
  }

  refreshInterval = setInterval(() => {
    refreshMessages().catch(err => console.error('Periodic refresh failed:', err));
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
    cancelAnimationFrame(requestAnimationFrame(() => { }));
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