<template>
  <div :class="[
    'p-4 border-t flex-shrink-0 lg:mt-4',
    'lg:relative lg:bottom-auto',
    'fixed bottom-0 left-0 right-0 z-30',
    isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
  ]">

    <!-- Wake word detected flash -->
    <Transition name="stt-hint">
      <div v-if="wakeWordDetected" :class="[
        'mb-2 px-3 py-1.5 rounded-lg text-sm flex items-center gap-2',
        isDark ? 'bg-indigo-700 text-white' : 'bg-indigo-500 text-white'
      ]">
        <span class="relative flex h-2 w-2 flex-shrink-0">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
        </span>
        <span>{{ wakeWord || '...' }}</span>
      </div>
    </Transition>

    <!-- STT interim hint -->
    <Transition name="stt-hint">
      <div v-if="isListening" :class="[
        'mb-2 px-3 py-1.5 rounded-lg text-sm flex items-center gap-2 transition-all',
        isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600'
      ]">
        <span class="relative flex h-2 w-2 flex-shrink-0">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
        </span>
        <span class="truncate">{{ interimText || t('chat_voice_listening') }}</span>
      </div>
    </Transition>

    <!-- File previews -->
    <Transition name="stt-hint">
      <div v-if="attachedFiles.length" class="mb-2 flex flex-wrap gap-2">
        <div v-for="(file, i) in attachedFiles" :key="i" :class="['flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs',
          isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600']">
          <span class="truncate max-w-32">{{ file.name }}</span>
          <span :class="['flex-shrink-0', isDark ? 'text-gray-500' : 'text-gray-400']">
            {{ humanSize(file.size) }}
          </span>
          <button type="button" @click="removeFile(i)" :class="['flex-shrink-0 hover:text-red-400 transition-colors']">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </Transition>

    <Transition name="stt-hint">
      <div v-if="hasAnyAttachment" class="mb-2 flex items-center gap-2 flex-wrap">
        <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
          {{ t('chat_image_attach_as') }}
        </span>
        <div :class="['inline-flex rounded-lg overflow-hidden border', isDark ? 'border-gray-600' : 'border-gray-300']">
          <button type="button" @click="attachMode = 'chat'" :class="[
            'px-3 py-1 text-xs font-medium transition-colors',
            attachMode === 'chat'
              ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
              : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
          ]">
            {{ t('chat_image_show_in_chat') }}
          </button>
          <button type="button" @click="attachMode = 'documents'" :class="[
            'px-3 py-1 text-xs font-medium transition-colors',
            attachMode === 'documents'
              ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
              : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
          ]">
            {{ t('chat_image_add_to_docs') }}
          </button>
        </div>
      </div>
    </Transition>

    <form @submit.prevent="handleSubmit">

      <!-- Main line: field + desktop buttons + submit -->
      <div class="flex space-x-3">
        <div class="flex-1 relative">
          <textarea ref="messageInput" v-model="content" :disabled="disabled || isProcessing"
            :placeholder="t('chat_input_ph')" :class="[
              'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all resize-none',
              'px-4 py-3 text-sm leading-relaxed',
              isDark
                ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
            ]" rows="1" @input="autoResize" @keydown="handleKeydown"></textarea>
          <div v-if="isProcessing" class="absolute right-3 top-3">
            <div class="flex space-x-1">
              <div :class="['w-2 h-2 rounded-full animate-bounce', isDark ? 'bg-indigo-400' : 'bg-indigo-500']"
                style="animation-delay: 0ms"></div>
              <div :class="['w-2 h-2 rounded-full animate-bounce', isDark ? 'bg-indigo-400' : 'bg-indigo-500']"
                style="animation-delay: 150ms"></div>
              <div :class="['w-2 h-2 rounded-full animate-bounce', isDark ? 'bg-indigo-400' : 'bg-indigo-500']"
                style="animation-delay: 300ms"></div>
            </div>
          </div>
        </div>

        <!-- Attach file - desktop only -->
        <button type="button" @click="$refs.fileInput.click()" :disabled="disabled || isProcessing"
          :title="t('chat_attach_file')" :class="[
            'hidden lg:flex items-center justify-center',
            'px-3 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
            'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            attachedFiles.length
              ? (isDark ? 'bg-teal-700 text-white' : 'bg-teal-500 text-white')
              : (isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 hover:scale-105'
                : 'bg-gray-100 hover:bg-gray-200 text-gray-600 hover:scale-105'),
            isDark ? 'focus:ring-offset-gray-800' : ''
          ]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.414 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span v-if="attachedFiles.length" :class="['ml-1 text-xs font-bold']">{{ attachedFiles.length }}</span>
        </button>
        <input ref="fileInput" type="file" multiple class="hidden" accept="*/*" @change="onFilesSelected" />

        <!-- Microphone - desktop only, three states -->
        <button v-if="hasSTT" type="button" @click="handleMicClick" :disabled="disabled || isProcessing"
          :title="isListening ? t('chat_voice_stop') : (isWakeWordListening ? t('chat_wake_word_standby') : t('chat_voice_start'))"
          :class="[
            'hidden lg:flex items-center justify-center',
            'px-3 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
            'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
            isListening
              ? (isDark ? 'bg-red-700 hover:bg-red-600 text-white ring-2 ring-red-500 scale-105' : 'bg-red-500 hover:bg-red-600 text-white ring-2 ring-red-400 scale-105')
              : isWakeWordListening
                ? (isDark ? 'bg-indigo-800 text-indigo-300 ring-1 ring-indigo-600' : 'bg-indigo-50 text-indigo-400 ring-1 ring-indigo-300')
                : (isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 enabled:hover:scale-105' : 'bg-gray-100 hover:bg-gray-200 text-gray-600 enabled:hover:scale-105'),
            isDark ? 'focus:ring-offset-gray-800' : ''
          ]">
          <!-- Record begin - stop -->
          <svg v-if="isListening" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
            <rect x="6" y="6" width="12" height="12" rx="2" />
          </svg>
          <!-- Wake word active - pulsed mic -->
          <span v-else-if="isWakeWordListening" class="relative flex items-center justify-center w-5 h-5">
            <span class="animate-ping absolute inline-flex h-3 w-3 rounded-full opacity-40"
              :class="isDark ? 'bg-indigo-400' : 'bg-indigo-300'"></span>
            <svg class="w-5 h-5 relative" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
            </svg>
          </span>
          <!-- Generic mic -->
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
          </svg>
        </button>

        <!-- TTS — desktop only -->
        <button v-if="hasTTS" type="button" @click="emit('toggleTTS')"
          :title="ttsEnabled ? t('chat_tts_disable') : t('chat_tts_enable')" :class="[
            'hidden lg:flex items-center justify-center',
            'px-3 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
            'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            isDark ? 'focus:ring-offset-gray-800' : '',
            ttsEnabled
              ? (isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-indigo-500 hover:bg-indigo-600 text-white')
              : (isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 hover:scale-105' : 'bg-gray-100 hover:bg-gray-200 text-gray-600 hover:scale-105')
          ]">
          <svg v-if="ttsEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M12 6v12m0 0L8 14H5a1 1 0 01-1-1v-2a1 1 0 011-1h3l4-4z" />
          </svg>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
          </svg>
        </button>

        <!-- Submit button -->
        <button type="submit" :disabled="disabled || !content.trim() || isProcessing" :class="[
          'px-6 py-3 rounded-xl font-medium transition-all transform',
          'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
          'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
          'enabled:hover:scale-105 enabled:active:scale-95',
          isDark
            ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
            : 'bg-indigo-600 hover:bg-indigo-700 text-white'
        ]">
          <svg v-if="disabled || isProcessing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 9-18 9v-7l12-2L3 10V3z" />
          </svg>
        </button>
      </div>

      <!-- Mobile buttons - responsive grid -->
      <div class="flex lg:hidden flex-col gap-2 mt-2">
        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">

          <!-- Attach -->
          <button type="button" @click="$refs.fileInput.click()" :class="[
            'flex flex-col items-center gap-1 px-2 py-2 rounded-lg text-xs font-medium transition-all',
            attachedFiles.length
              ? (isDark ? 'bg-teal-700 text-white' : 'bg-teal-500 text-white')
              : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
          ]">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.414 6.585a6 6 0 108.486 8.486L20.5 13" />
            </svg>
            <span class="hidden sm:inline">{{ attachedFiles.length ? attachedFiles.length + ' ' + t('chat_files') :
              t('chat_attach_file') }}</span>
            <span class="sm:hidden">{{ attachedFiles.length || t('chat_file') }}</span>
          </button>

          <!-- Mic — mobile, three state -->
          <button v-if="hasSTT" type="button" @click="handleMicClick" :disabled="disabled || isProcessing"
            :title="isListening ? t('chat_voice_stop') : (isWakeWordListening ? t('chat_wake_word_standby') : t('chat_voice_start'))"
            :class="[
              'flex flex-col items-center gap-1 px-2 py-2 rounded-lg text-xs font-medium transition-all',
              isListening
                ? (isDark ? 'bg-red-700 text-white' : 'bg-red-500 text-white')
                : isWakeWordListening
                  ? (isDark ? 'bg-indigo-800 text-indigo-300' : 'bg-indigo-50 text-indigo-400')
                  : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
            ]">
            <!-- Record - stop -->
            <svg v-if="isListening" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <rect x="6" y="6" width="12" height="12" rx="2" />
            </svg>
            <!-- Wake word — pulsed mic -->
            <span v-else-if="isWakeWordListening" class="relative flex items-center justify-center w-5 h-5">
              <span class="animate-ping absolute inline-flex h-3 w-3 rounded-full opacity-40"
                :class="isDark ? 'bg-indigo-400' : 'bg-indigo-300'"></span>
              <svg class="w-5 h-5 relative" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
              </svg>
            </span>
            <!-- Generic mic -->
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
            </svg>
            <span class="hidden sm:inline">{{ isListening ? t('chat_voice_stop') : t('chat_voice_start') }}</span>
            <span class="sm:hidden">{{ isListening ? t('chat_voice_input_stop') : t('chat_voice_input_start') }}</span>
          </button>

          <!-- TTS -->
          <button v-if="hasTTS" type="button" @click="emit('toggleTTS')" :class="[
            'flex flex-col items-center gap-1 px-2 py-2 rounded-lg text-xs font-medium transition-all',
            ttsEnabled
              ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
              : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
          ]">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="ttsEnabled" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M12 6v12m0 0L8 14H5a1 1 0 01-1-1v-2a1 1 0 011-1h3l4-4z" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
            </svg>
            <span class="hidden sm:inline">{{ ttsEnabled ? t('chat_tts_disable') : t('chat_tts_enable') }}</span>
            <span class="sm:hidden">{{ ttsEnabled ? t('chat_tts_on') : t('chat_tts_off') }}</span>
          </button>

        </div>
      </div>

    </form>
  </div>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
  disabled: Boolean,
  isProcessing: Boolean,
  isDark: Boolean,
  hasSTT: { type: Boolean, default: false },
  isListening: { type: Boolean, default: false },
  interimText: { type: String, default: '' },
  hasTTS: { type: Boolean, default: false },
  ttsEnabled: { type: Boolean, default: false },
  isWakeWordListening: { type: Boolean, default: false },
  wakeWordDetected: { type: Boolean, default: false },
  wakeWord: { type: String, default: '' },
});

const emit = defineEmits(['send', 'toggleMic', 'toggleTTS']);

const content = ref('');
const messageInput = ref(null);

// ── File attachment ──
const attachedFiles = ref([]);
const fileInput = ref(null);

const hasAnyAttachment = computed(() => attachedFiles.value.length > 0);

// 'chat' = show to agent now (described, not stored); 'documents' = embed as file
const attachMode = ref('chat');

const isMobile = computed(() => {
  if (typeof window === 'undefined') return false;
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
    || window.innerWidth < 1024;
});

async function handleMicClick() {
  emit('toggleMic');
}

function insertRecognizedText(text) {
  if (!text) return;
  const separator = content.value.trim() ? ' ' : '';
  content.value = (content.value + separator + text).trim();
  nextTick(() => { autoResize(); focusInput(); });
}

function autoResize() {
  nextTick(() => {
    if (messageInput.value) {
      messageInput.value.style.height = 'auto';
      messageInput.value.style.height = Math.min(messageInput.value.scrollHeight, 120) + 'px';
    }
  });
}

function handleSubmit() {
  if (!content.value.trim() || props.disabled || props.isProcessing) return;
  // Only meaningful when images present; harmless otherwise.
  emit('send', content.value, attachedFiles.value, hasAnyAttachment.value ? attachMode.value : 'documents');
  content.value = '';
  attachedFiles.value = [];
  attachMode.value = 'chat'; // reset to default for next message
  if (messageInput.value) messageInput.value.style.height = 'auto';
  focusInput();
}

function handleKeydown(event) {
  if (event.key === 'Enter' && !event.shiftKey) {
    if (isMobile.value) {
      return;
    } else {
      event.preventDefault();
      handleSubmit();
    }
  }
}

function focusInput() {
  nextTick(() => { messageInput.value?.focus(); });
}

function setContent(newContent) {
  content.value = newContent;
  nextTick(() => {
    autoResize();
    focusInput();
    if (messageInput.value) {
      const length = messageInput.value.value.length;
      messageInput.value.setSelectionRange(length, length);
    }
  });
}

function onFilesSelected(e) {
  const newFiles = Array.from(e.target.files || []);
  attachedFiles.value = [...attachedFiles.value, ...newFiles].slice(0, 10);
  e.target.value = '';
}

function removeFile(index) {
  attachedFiles.value = attachedFiles.value.filter((_, i) => i !== index);
}

function humanSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1_048_576) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1_048_576).toFixed(1) + ' MB';
}

defineExpose({ focusInput, setContent, insertRecognizedText });
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

.stt-hint-enter-active,
.stt-hint-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.stt-hint-enter-from,
.stt-hint-leave-to {
  opacity: 0;
  transform: translateY(4px);
}
</style>