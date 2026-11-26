<template>
  <PageTitle :title="t('chat')" />
  <!--
    Height = visible viewport (see useAppViewport). h-screen (100vh) on mobile is
    the height WITHOUT keyboard and without browser bars -> things jumped.
  -->
  <div :class="[
    'chat-app-root flex overflow-hidden transition-colors duration-300',
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
      @closeMobileMenu="mobileMenuOpen = false" @clearHistory="showClearHistory" @editPreset="handleEditPreset()"
      @toggleTheme="toggleTheme" @exportChat="exportChat" />

    <!-- Main chat area -->
    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-hidden">
      <!-- Mobile header (regular flex child now, not fixed) -->
      <MobileHeader :isDark="isDark" :appName="page.props.app_name" @openMenu="mobileMenuOpen = true"
        @openUsers="mobileTabsOpen = true" />

      <!-- View tabs: preset chat / Laravel log (admin only) -->
      <div v-if="isAdmin" :class="[
        'flex items-center gap-1 px-2 sm:px-4 pt-2 border-b flex-shrink-0',
        isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
      ]">
        <button v-for="tab in viewTabs" :key="tab.id" type="button" @click="activeView = tab.id" :class="[
          'px-3 py-1.5 text-sm rounded-t-lg border-b-2 -mb-px transition-colors flex items-center gap-1.5 max-w-[60%]',
          activeView === tab.id
            ? (isDark ? 'border-indigo-400 text-white' : 'border-indigo-600 text-gray-900')
            : (isDark ? 'border-transparent text-gray-400 hover:text-gray-200' : 'border-transparent text-gray-500 hover:text-gray-800')
        ]">
          <span>{{ tab.icon }}</span>
          <span class="truncate">{{ tab.label }}</span>
          <span v-if="tab.id === 'chat' && hasUnreadMessages && activeView !== 'chat'"
            class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
        </button>
      </div>

      <!-- Log view: mounted on first open, then kept alive (v-show) -->
      <LogViewer v-if="logMounted" v-show="activeView === 'log'" :isDark="isDark" :active="activeView === 'log'" />

      <!-- Loading state -->
      <div v-if="isInitialLoading" v-show="activeView === 'chat'" :class="[
        'flex-1 flex items-center justify-center',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
      ]">
        <div class="text-center">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto mb-4"></div>
          <p :class="isDark ? 'text-gray-400' : 'text-gray-600'">{{ t('chat_loading_messages') }}</p>
        </div>
      </div>

      <!-- Messages + floating button share a relative box -->
      <div v-if="!isInitialLoading" v-show="activeView === 'chat'" class="relative flex-1 min-h-0 flex flex-col">
        <ChatMessages ref="messagesComponent" :messages="localMessages" :pagination="pagination" :isDark="isDark"
          :is-admin="isAdmin" :appName="page.props.app_name" :showAgentResults="showAgentResults"
          :showCommandResults="showCommandResults" :isBackgroundRefreshing="isBackgroundRefreshing"
          :isLoadingOlder="isLoadingOlder" :hasTTS="hasTTS" :presetName="currentPreset?.name"
          :speakingMessageId="currentlySpeakingId" @deleteMessage="deleteMessage" @scrollUpdate="handleScrollUpdate"
          @loadOlder="handleLoadOlder" @speakMessage="handleSpeakMessage" />

        <!-- Scroll to bottom button -->
        <ScrollToBottomButton v-if="hasUnreadMessages || !isUserAtBottom" :hasUnreadMessages="hasUnreadMessages"
          :isDark="isDark" @click="scrollToBottomForced" class="bottom-4" />
      </div>

      <!-- What the agent is doing right now (phases, timeline on tap) -->
      <AgentStatusBar v-show="activeView === 'chat'" :status="agentStatus" :isDark="isDark" />

      <!-- Message input (only for chat view) -->
      <MessageInput v-show="activeView === 'chat'" ref="messageInputComponent" :disabled="false" :canAttachToDocs="isAdmin"
        :isProcessing="isProcessing"
        :isDark="isDark" :hasSTT="hasSTT" :isListening="isListening" :interimText="interimText" :hasTTS="hasTTS"
        :ttsEnabled="ttsEnabled" :isWakeWordListening="isWakeWordListening" :wakeWordDetected="wakeWordDetected"
        :wakeWord="currentPreset?.preset_code || currentPreset?.name || ''" @send="sendMessage"
        @toggleMic="handleToggleMic" @toggleTTS="toggleTTS" />
    </div>

    <!-- Tabs panel (desktop) -->
    <div class="hidden lg:flex lg:w-80 flex-col border-l">
      <TabsPanel :users="localUsers" :availablePresets="availablePresets" :selectedPresetId="selectedPresetId"
        :isDark="isDark" :presetMetadata="presetMetadata" :user="user" :presetActiveMap="presetActiveMap"
        :agents="agents" @mentionUser="mentionUser" @showAbout="showAboutModal = true"
        @selectPreset="handlePresetSelect" @editPreset="handleEditPreset" @togglePresetActive="handleTogglePresetActive"
        @agentFilterChanged="selectedAgentId = $event" />
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
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
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
import LogViewer from '@/Components/Chat/LogViewer.vue';
import AgentStatusBar from '@/Components/Chat/AgentStatusBar.vue';

import { useTheme } from '@/Composables/useTheme';
import { useChat } from '@/Composables/useChat';
import { useVoice } from '@/Composables/useVoice';
import { useVoiceConfig } from '@/Composables/useVoiceConfig';
import { usePresets } from '@/Composables/usePresets';
import { useSelectedPreset } from '@/Composables/useSelectedPreset';
import { useAppViewport } from '@/Composables/useAppViewport';

const { t } = useI18n();
const page = usePage();

const localeMap = { ru: 'ru-RU', en: 'en-US', uk: 'uk-UA', de: 'de-DE', fr: 'fr-FR', es: 'es-ES' };
const appLocale = page.props.locale;
const sttLang = localeMap[appLocale] || 'en-US';

const {
  hasTTS, hasSTT, ttsEnabled, isSpeaking, currentlySpeakingId, lastSpokenMessageId,
  resetInitialLoad, markInitialLoadDone, speakMessage, speakNewMessages,
  stopSpeaking, toggleTTS,
  isListening, isWakeWordListening, wakeWordDetected, interimText, isTranscribing,
  startWakeWord, stopWakeWord, toggleMic, onPhrase, applyConfig,
  sttMode, ttsMode,
} = useVoice({ sttLang });

const { loadVoiceConfig } = useVoiceConfig();

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
useAppViewport();
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

// ── View tabs (chat / log) ──
const activeView = ref('chat');
const logMounted = ref(false);
watch(activeView, (v) => {
  if (v === 'log') logMounted.value = true;
  if (v === 'chat') {
    // Returning to chat: container was display:none, re-pin to bottom
    nextTick(() => { if (isUserAtBottom.value) messagesComponent.value?.scrollToBottom(); });
  }
});
const viewTabs = computed(() => [
  { id: 'chat', icon: '💬', label: currentPreset.value?.name || t('chat') },
  { id: 'log', icon: '📜', label: t('log_tab') },
]);

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
  isLoadingOlder,
  agentStatus,
  fetchStatus,
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
  await toggleMic();
}

onPhrase((text, source) => {
  if (source === 'wake') {
    sendMessage(text);
  } else {
    messageInputComponent.value?.insertRecognizedText(text);
  }
});

// Reload voice config whenever the selected preset changes. applyConfig tears
// down any running recognizer, so no manual stopWakeWord() is needed.
watch(selectedPresetId, async (presetId) => {
  if (!presetId) return;

  const config = await loadVoiceConfig(presetId);
  applyConfig(config);

  // The preset's own code is the spoken handle. Config wake_words win when set.
  const code = currentPreset.value?.preset_code || currentPreset.value?.name || '';
  const configured = config.stt?.wake_words?.length;

  if (!configured && config.stt?.wake_enabled && code) {
    startWakeWord(code.toLowerCase(), () => {
      messageInputComponent.value?.focusInput();
    });
  }
}, { immediate: true });

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

function sendMessage(content, files = [], attachMode = 'documents') {
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
    attach_mode: attachMode,
  })).post(route('chat.message'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      form.reset();
      isProcessing.value = false;
      // Show the just-sent message right away instead of waiting for the next poll,
      // and pick up "queued" so the status bar reacts immediately.
      refreshMessages();
      fetchStatus();
      requestAnimationFrame(() => messagesComponent.value?.scrollToBottom());
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
  if (!isAdmin.value) return;
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

function handleClearHistory(options) {
  if (isClearingHistory.value) return;
  isClearingHistory.value = true;

  const requestData = { preset_id: selectedPresetId.value };
  const flags = {
    clearMessages: 'clear_messages', clearMemory: 'clear_memory', clearVectorMemory: 'clear_vector_memory',
    clearWorkspace: 'clear_workspace', clearGoals: 'clear_goals', clearSkills: 'clear_skills',
    clearPerson: 'clear_person', clearHeart: 'clear_heart', clearOntology: 'clear_ontology',
    clearJournal: 'clear_journal', clearContracts: 'clear_contracts', clearPatterns: 'clear_patterns',
    clearAgent: 'clear_agent',
  };
  for (const [opt, key] of Object.entries(flags)) {
    if (options[opt]) requestData[key] = true;
  }

  // router.post() doesn't return a promise: the old `await` + finally reset the
  // "clearing" flag immediately. onFinish is the right place.
  router.post(route('chat.clear'), requestData, {
    preserveScroll: true,
    onSuccess: async () => {
      if (options.clearMessages) {
        resetChatState();
        await loadLatestMessages(null, false);
        messagesComponent.value?.scrollToBottom();
      }
      showClearHistoryModal.value = false;
    },
    onError: (errors) => console.error('Error clearing history:', errors),
    onFinish: () => { isClearingHistory.value = false; },
  });
}

// -------------------------------------------------------------------------
// Preset editing
// -------------------------------------------------------------------------

function mentionUser(userName) {
  const mention = `${props.toLabel} ${userName}`;
  // The draft lives inside MessageInput, not in `form` (form.content is empty
  // until sending) -> previously a mention wiped what the user had typed.
  const draft = messageInputComponent.value?.getContent?.() ?? '';
  const currentContent = draft.toLowerCase();

  if (currentContent.includes(mention.toLowerCase())) {
    messageInputComponent.value?.focusInput();
    return;
  }

  const mentionPattern = new RegExp(`^${props.toLabel}\\s+\\w+,\\s*`, 'i');
  const newContent = mentionPattern.test(draft)
    ? draft.replace(mentionPattern, `${mention}, `)
    : `${mention}, ${draft}`;

  messageInputComponent.value?.setContent(newContent);
}

async function handleEditPreset(presetId = null) {
  if (!isAdmin.value) return;

  // "Edit current preset" must edit the SELECTED preset, not the server default
  await loadAndEditPreset(presetId || selectedPresetId.value);
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

// NOTE: the old watch(props.currentPresetId -> selectedPresetId) was removed.
// Selection is client-owned (localStorage). That watch could switch the
// selection to a new *default* preset after any Inertia reload while messages
// of the old preset stayed on screen — and the next message went elsewhere.

// Sync availablePresets chat_active flags when server re-renders
watch(() => props.availablePresets, (newPresets) => {
  if (!newPresets) return;
  for (const p of newPresets) {
    presetActiveMap.value[p.id] = !!p.chat_active;
  }
}, { deep: true });

watch(() => props.users, (newUsers) => {
  localUsers.value = [...(newUsers || [])];
}, { deep: true });

// New messages at the END (not older ones prepended): auto-scroll + TTS.
// Watching the last id instead of length: prepending older pages also changes
// length and used to feed old messages into TTS.
watch(() => localMessages.value[localMessages.value.length - 1]?.id ?? 0, (newLast, oldLast) => {
  if (!newLast || !oldLast || newLast <= oldLast) return;

  const newMessages = localMessages.value.filter(m => m.id > oldLast);
  if (!newMessages.length) return;

  if (shouldAutoRefresh.value) {
    messagesComponent.value?.scrollToBottom();
  }
  if (ttsEnabled.value) {
    speakNewMessages(newMessages);
  }
});

// -------------------------------------------------------------------------
// Lifecycle
// -------------------------------------------------------------------------

onMounted(async () => {
  localUsers.value = [...(props.users || [])];

  // Persist the selected preset (restored or default) so admin links open the right one
  savePresetId(selectedPresetId.value);

  try {
    await loadLatestMessages(selectedPresetId.value);
    messagesComponent.value?.scrollToBottom();
    markInitialLoadDone();
  } catch (error) {
    console.error('Failed to load initial messages:', error);
  }

  // Single poller lives in useChat (idempotent)
  startFrequentRefresh();
  startUsersRefresh();
});

onBeforeUnmount(() => {
  cleanupChat();
});
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