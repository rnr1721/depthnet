<template>
  <div :class="[
    'p-4 border-t flex-shrink-0 lg:mt-4',
    'lg:relative lg:bottom-auto',
    'fixed bottom-0 left-0 right-0 z-30',
    isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
  ]">
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
            ]" rows="1" @input="autoResize" @keydown="handleKeydown">></textarea>
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

        <!-- Microphone - desktop only -->
        <button v-if="hasSTT" type="button" @click="handleMicClick" :disabled="disabled || isProcessing"
          :title="isListening ? t('chat_voice_stop') : t('chat_voice_start')" :class="[
            'hidden lg:flex items-center justify-center',
            'px-3 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
            'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
            isListening
              ? (isDark ? 'bg-red-700 hover:bg-red-600 text-white ring-2 ring-red-500 scale-105' : 'bg-red-500 hover:bg-red-600 text-white ring-2 ring-red-400 scale-105')
              : (isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 enabled:hover:scale-105' : 'bg-gray-100 hover:bg-gray-200 text-gray-600 enabled:hover:scale-105'),
            isDark ? 'focus:ring-offset-gray-800' : ''
          ]">
          <svg v-if="isListening" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
            <rect x="6" y="6" width="12" height="12" rx="2" />
          </svg>
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

      <!-- Mobile row with voice buttons - only < lg -->
      <div v-if="hasSTT || hasTTS" class="flex lg:hidden items-center gap-2 mt-2">
        <span :class="['text-xs flex-shrink-0', isDark ? 'text-gray-500' : 'text-gray-400']">
          {{ t('chat_voice') }}:
        </span>

        <!-- Mobile microphone -->
        <button v-if="hasSTT" type="button" @click="handleMicClick" :disabled="disabled || isProcessing" :class="[
          'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
          'disabled:opacity-50 disabled:cursor-not-allowed',
          isListening
            ? (isDark ? 'bg-red-700 text-white ring-2 ring-red-500' : 'bg-red-500 text-white ring-2 ring-red-400')
            : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
        ]">
          <span v-if="isListening" class="relative flex h-2 w-2 flex-shrink-0">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-300 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-red-200"></span>
          </span>
          <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
          </svg>
          <span>{{ isListening ? t('chat_voice_stop') : t('chat_voice_start') }}</span>
        </button>

        <!-- TTS мобильный -->
        <button v-if="hasTTS" type="button" @click="emit('toggleTTS')" :class="[
          'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
          ttsEnabled
            ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
            : (isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600')
        ]">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path v-if="ttsEnabled" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M12 6v12m0 0L8 14H5a1 1 0 01-1-1v-2a1 1 0 011-1h3l4-4z" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
          </svg>
          <span>{{ ttsEnabled ? t('chat_tts_disable') : t('chat_tts_enable') }}</span>
        </button>
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
});

const emit = defineEmits(['send', 'toggleMic', 'toggleTTS']);

const content = ref('');
const messageInput = ref(null);

// Определяем, мобильное ли устройство
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
  emit('send', content.value);
  content.value = '';
  if (messageInput.value) messageInput.value.style.height = 'auto';
  focusInput();
}

function handleKeydown(event) {
  // On mobile devices: Enter always creates a new line (unless Shift is held down)
  // On desktop devices: Enter sends, Shift+Enter creates a new line
  if (event.key === 'Enter' && !event.shiftKey) {
    if (isMobile.value) {
      // На мобильных — новая строка
      return; // позволяет стандартное поведение textarea (новая строка)
    } else {
      // На десктопе — отправка
      event.preventDefault();
      handleSubmit();
    }
  }

  // Shift+Enter always creates a new line (on both mobile and desktop)
  // This is the default behavior for textareas, so we do nothing.
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