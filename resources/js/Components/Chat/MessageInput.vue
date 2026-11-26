<template>
  <!--
    Not `fixed` anymore: a normal flex child at the bottom of the chat column.
    Together with --app-height (useAppViewport) the keyboard shrinks the chat
    instead of moving this block around.
  -->
  <div ref="rootEl" :class="[
    'relative px-3 pt-2 sm:px-4 sm:pt-3 border-t flex-shrink-0',
    'pb-[max(0.5rem,env(safe-area-inset-bottom))] lg:pb-4',
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
          <button type="button" @click="removeFile(i)" class="flex-shrink-0 hover:text-red-400 transition-colors">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </Transition>

    <Transition name="stt-hint">
      <div v-if="hasAnyAttachment && canAttachToDocs" class="mb-2 flex items-center gap-2 flex-wrap">
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

    <!-- Markdown toolbar -->
    <Transition name="stt-hint">
      <MarkdownToolbar v-if="showFormat" class="mb-2" :isDark="isDark" :preview="showPreview"
        @format="applyFormat" @togglePreview="showPreview = !showPreview" />
    </Transition>

    <!-- Preview -->
    <Transition name="stt-hint">
      <div v-if="showFormat && showPreview" :class="[
        'mb-2 px-3 py-2 rounded-xl text-sm max-h-[30vh] overflow-y-auto message-content leading-relaxed',
        isDark ? 'bg-gray-900 text-gray-200 ring-1 ring-gray-700' : 'bg-gray-50 text-gray-800 ring-1 ring-gray-200'
      ]">
        <div v-if="content.trim()" v-html="previewHtml"></div>
        <div v-else :class="['italic text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
          {{ t('chat_md_preview_empty') }}
        </div>
      </div>
    </Transition>

    <!-- Emoji popover -->
    <Transition name="stt-hint">
      <div v-if="showEmoji" ref="emojiEl" class="absolute bottom-full mb-2 right-3 lg:right-auto lg:left-4 z-40">
        <EmojiPicker :isDark="isDark" @pick="insertEmoji" />
      </div>
    </Transition>

    <form @submit.prevent="handleSubmit">
      <div class="flex items-end gap-2 sm:gap-3">
        <div class="flex-1 relative min-w-0">
          <!--
            readonly (not disabled) while processing: disabling a focused field
            makes Android close the keyboard -> whole layout jumps twice.
            text-base on mobile: iOS zooms into inputs with font-size < 16px.
          -->
          <textarea ref="messageInput" v-model="content" :disabled="disabled" :readonly="isProcessing"
            :placeholder="t('chat_input_ph')" enterkeyhint="send" :class="[
              'block w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-shadow resize-none',
              'px-4 py-3 text-base lg:text-sm leading-relaxed',
              isProcessing ? 'opacity-70' : '',
              isDark
                ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
            ]" rows="1" @input="autoResize" @keydown="handleKeydown"></textarea>
          <div v-if="isProcessing" class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
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

        <!-- Desktop tool buttons -->
        <div class="hidden lg:flex items-end gap-2">
          <!-- Emoji -->
          <button ref="emojiBtnDesktop" type="button" @pointerdown.prevent @click="toggleEmoji"
            :title="t('chat_emoji')" :class="toolBtn(showEmoji)">
            <span class="text-lg leading-5 w-5 h-5 flex items-center justify-center">😊</span>
          </button>

          <!-- Formatting -->
          <button type="button" @pointerdown.prevent @click="toggleFormat" :title="t('chat_md_format')"
            :class="toolBtn(showFormat)">
            <span class="text-sm font-semibold w-5 h-5 flex items-center justify-center">Aa</span>
          </button>

          <!-- Attach -->
          <button type="button" @click="fileInput.click()" :disabled="disabled || isProcessing"
            :title="t('chat_attach_file')" :class="[toolBtn(false), attachedFiles.length
              ? (isDark ? '!bg-teal-700 !text-white' : '!bg-teal-500 !text-white') : '']">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.414 6.585a6 6 0 108.486 8.486L20.5 13" />
            </svg>
            <span v-if="attachedFiles.length" class="ml-1 text-xs font-bold">{{ attachedFiles.length }}</span>
          </button>

          <!-- Mic -->
          <button v-if="hasSTT" type="button" @click="handleMicClick" :disabled="disabled || isProcessing"
            :title="micTitle" :class="micBtnClass">
            <MicIcon :isListening="isListening" :isWakeWordListening="isWakeWordListening" :isDark="isDark" />
          </button>

          <!-- TTS -->
          <button v-if="hasTTS" type="button" @click="emit('toggleTTS')"
            :title="ttsEnabled ? t('chat_tts_disable') : t('chat_tts_enable')" :class="toolBtn(ttsEnabled)">
            <TtsIcon :enabled="ttsEnabled" />
          </button>
        </div>

        <!-- Submit -->
        <button type="submit" @pointerdown.prevent :disabled="disabled || !content.trim() || isProcessing" :class="[
          'px-4 sm:px-6 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
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

      <!-- Mobile: one compact icon row (labels ate vertical space under the keyboard) -->
      <div class="flex lg:hidden items-center gap-1 mt-1.5">
        <button ref="emojiBtnMobile" type="button" @pointerdown.prevent @click="toggleEmoji" :title="t('chat_emoji')"
          :class="mobileBtn(showEmoji)">
          <span class="text-lg leading-none">😊</span>
        </button>

        <button type="button" @pointerdown.prevent @click="toggleFormat" :title="t('chat_md_format')"
          :class="mobileBtn(showFormat)">
          <span class="text-sm font-semibold">Aa</span>
        </button>

        <button type="button" @click="fileInput.click()" :title="t('chat_attach_file')"
          :class="[mobileBtn(false), attachedFiles.length ? (isDark ? '!bg-teal-700 !text-white' : '!bg-teal-500 !text-white') : '']">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.414 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span v-if="attachedFiles.length" class="ml-0.5 text-xs font-bold">{{ attachedFiles.length }}</span>
        </button>

        <button v-if="hasSTT" type="button" @click="handleMicClick" :disabled="disabled || isProcessing"
          :title="micTitle" :class="mobileMicClass">
          <MicIcon :isListening="isListening" :isWakeWordListening="isWakeWordListening" :isDark="isDark" />
        </button>

        <button v-if="hasTTS" type="button" @click="emit('toggleTTS')"
          :title="ttsEnabled ? t('chat_tts_disable') : t('chat_tts_enable')" :class="mobileBtn(ttsEnabled)">
          <TtsIcon :enabled="ttsEnabled" />
        </button>
      </div>

      <input ref="fileInput" type="file" multiple class="hidden" accept="*/*" @change="onFilesSelected" />
    </form>
  </div>
</template>

<script setup>
import { ref, nextTick, computed, h, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import EmojiPicker from './EmojiPicker.vue';
import MarkdownToolbar from './MarkdownToolbar.vue';
import { renderMarkdown } from '@/Utils/markdown';
import { wrapSelection, prefixLines, codeBlock, link, insertText } from '@/Utils/textareaFormat';

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
  // Only admins may store files into preset documents (server enforces it too)
  canAttachToDocs: { type: Boolean, default: false },
});

const emit = defineEmits(['send', 'toggleMic', 'toggleTTS']);

const content = ref('');
const messageInput = ref(null);
const rootEl = ref(null);
const emojiEl = ref(null);
const emojiBtnDesktop = ref(null);
const emojiBtnMobile = ref(null);

// ── Small inline icon components (were duplicated for desktop/mobile) ──
const MicIcon = (p) => {
  const micPath = 'M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z';
  const mic = (cls) => h('svg', { class: cls, fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' },
    [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': 2, d: micPath })]);
  if (p.isListening) {
    return h('svg', { class: 'w-5 h-5', fill: 'currentColor', viewBox: '0 0 24 24' },
      [h('rect', { x: 6, y: 6, width: 12, height: 12, rx: 2 })]);
  }
  if (p.isWakeWordListening) {
    return h('span', { class: 'relative flex items-center justify-center w-5 h-5' }, [
      h('span', { class: ['animate-ping absolute inline-flex h-3 w-3 rounded-full opacity-40', p.isDark ? 'bg-indigo-400' : 'bg-indigo-300'] }),
      mic('w-5 h-5 relative'),
    ]);
  }
  return mic('w-5 h-5');
};
MicIcon.props = ['isListening', 'isWakeWordListening', 'isDark'];

const TtsIcon = (p) => h('svg', { class: 'w-5 h-5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' },
  p.enabled
    ? [h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': 2, d: 'M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M12 6v12m0 0L8 14H5a1 1 0 01-1-1v-2a1 1 0 011-1h3l4-4z' })]
    : [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': 2, d: 'M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z' }),
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': 2, d: 'M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2' }),
    ]);
TtsIcon.props = ['enabled'];

// ── Button styles ──
function toolBtn(active) {
  return [
    'flex items-center justify-center px-3 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
    'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
    'disabled:opacity-50 disabled:cursor-not-allowed',
    props.isDark ? 'focus:ring-offset-gray-800' : '',
    active
      ? (props.isDark ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-indigo-500 hover:bg-indigo-600 text-white')
      : (props.isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 hover:scale-105' : 'bg-gray-100 hover:bg-gray-200 text-gray-600 hover:scale-105'),
  ];
}

function mobileBtn(active) {
  return [
    'h-9 min-w-10 px-2.5 rounded-lg flex items-center justify-center transition-colors flex-shrink-0',
    'disabled:opacity-50',
    active
      ? (props.isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
      : (props.isDark ? 'bg-gray-700 text-gray-300 active:bg-gray-600' : 'bg-gray-100 text-gray-600 active:bg-gray-200'),
  ];
}

const micTitle = computed(() => props.isListening
  ? t('chat_voice_stop')
  : (props.isWakeWordListening ? t('chat_wake_word_standby') : t('chat_voice_start')));

const micBtnClass = computed(() => [
  'flex items-center justify-center px-3 py-3 rounded-xl font-medium transition-all transform flex-shrink-0',
  'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
  'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
  props.isListening
    ? (props.isDark ? 'bg-red-700 hover:bg-red-600 text-white ring-2 ring-red-500 scale-105' : 'bg-red-500 hover:bg-red-600 text-white ring-2 ring-red-400 scale-105')
    : props.isWakeWordListening
      ? (props.isDark ? 'bg-indigo-800 text-indigo-300 ring-1 ring-indigo-600' : 'bg-indigo-50 text-indigo-400 ring-1 ring-indigo-300')
      : (props.isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-300 enabled:hover:scale-105' : 'bg-gray-100 hover:bg-gray-200 text-gray-600 enabled:hover:scale-105'),
  props.isDark ? 'focus:ring-offset-gray-800' : '',
]);

const mobileMicClass = computed(() => [
  'h-9 min-w-10 px-2.5 rounded-lg flex items-center justify-center transition-colors flex-shrink-0 disabled:opacity-50',
  props.isListening
    ? (props.isDark ? 'bg-red-700 text-white' : 'bg-red-500 text-white')
    : props.isWakeWordListening
      ? (props.isDark ? 'bg-indigo-800 text-indigo-300' : 'bg-indigo-50 text-indigo-400')
      : (props.isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600'),
]);

// ── File attachment ──
const attachedFiles = ref([]);
const fileInput = ref(null);
const hasAnyAttachment = computed(() => attachedFiles.value.length > 0);
// 'chat' = show to agent now (described, not stored); 'documents' = embed as file
const attachMode = ref('chat');

// ── Emoji / formatting state ──
const showEmoji = ref(false);
const showFormat = ref(localStorage.getItem('chat_md_toolbar') === 'true');
const showPreview = ref(false);

const previewHtml = computed(() => renderMarkdown(content.value));

function toggleEmoji() {
  showEmoji.value = !showEmoji.value;
}

function toggleFormat() {
  showFormat.value = !showFormat.value;
  try { localStorage.setItem('chat_md_toolbar', String(showFormat.value)); } catch { }
  if (!showFormat.value) showPreview.value = false;
}

function onDocPointerDown(e) {
  if (!showEmoji.value) return;
  const inside = emojiEl.value?.contains(e.target)
    || emojiBtnDesktop.value?.contains(e.target)
    || emojiBtnMobile.value?.contains(e.target);
  if (!inside) showEmoji.value = false;
}

function onDocKeydown(e) {
  if (e.key === 'Escape' && showEmoji.value) showEmoji.value = false;
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocPointerDown);
  document.addEventListener('keydown', onDocKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointerDown);
  document.removeEventListener('keydown', onDocKeydown);
});

/**
 * Apply an edit result {value, start, end} to the textarea.
 * Uses execCommand('insertText') on the changed range so native Ctrl+Z works;
 * falls back to assigning the value.
 */
function applyEdit(result) {
  const el = messageInput.value;
  if (!el) { content.value = result.value; return; }

  const oldVal = el.value;
  const newVal = result.value;
  let p = 0;
  while (p < oldVal.length && p < newVal.length && oldVal[p] === newVal[p]) p++;
  let so = oldVal.length, sn = newVal.length;
  while (so > p && sn > p && oldVal[so - 1] === newVal[sn - 1]) { so--; sn--; }

  el.focus();
  el.setSelectionRange(p, so);
  let ok = false;
  try { ok = document.execCommand('insertText', false, newVal.slice(p, sn)); } catch { ok = false; }
  if (!ok || el.value !== newVal) {
    content.value = newVal;
  }
  nextTick(() => {
    el.setSelectionRange(result.start, result.end);
    autoResize();
  });
}

function currentSelection() {
  const el = messageInput.value;
  const v = content.value;
  return el ? [v, el.selectionStart ?? v.length, el.selectionEnd ?? v.length] : [v, v.length, v.length];
}

function applyFormat(kind) {
  if (props.isProcessing) return;
  const [v, s, e] = currentSelection();
  const ops = {
    bold: () => wrapSelection(v, s, e, '**', '**', t('chat_md_bold')),
    italic: () => wrapSelection(v, s, e, '_', '_', t('chat_md_italic')),
    strike: () => wrapSelection(v, s, e, '~~', '~~', t('chat_md_strike')),
    code: () => wrapSelection(v, s, e, '`', '`', 'code'),
    codeblock: () => codeBlock(v, s, e),
    quote: () => prefixLines(v, s, e, '> '),
    list: () => prefixLines(v, s, e, '- '),
    olist: () => prefixLines(v, s, e, '', { numbered: true }),
    link: () => link(v, s, e),
  };
  if (ops[kind]) applyEdit(ops[kind]());
}

function insertEmoji(emoji) {
  if (props.isProcessing) return;
  const [v, s, e] = currentSelection();
  applyEdit(insertText(v, s, e, emoji));
}

// ── Input behaviour ──
const isMobile = computed(() => {
  if (typeof window === 'undefined') return false;
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
    || window.innerWidth < 1024;
});

function handleMicClick() {
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
    const el = messageInput.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 160) + 'px';
  });
}

function handleSubmit() {
  if (!content.value.trim() || props.disabled || props.isProcessing) return;
  const mode = !hasAnyAttachment.value ? 'documents' : (props.canAttachToDocs ? attachMode.value : 'chat');
  emit('send', content.value, attachedFiles.value, mode);
  content.value = '';
  attachedFiles.value = [];
  attachMode.value = 'chat';
  showEmoji.value = false;
  showPreview.value = false;
  if (messageInput.value) messageInput.value.style.height = 'auto';
  // On mobile we don't steal focus back (it would re-open the keyboard);
  // focus was kept anyway thanks to @pointerdown.prevent on the send button.
  if (!isMobile.value) focusInput();
}

function handleKeydown(event) {
  // Formatting shortcuts
  if ((event.ctrlKey || event.metaKey) && !event.altKey) {
    const map = { b: 'bold', i: 'italic', e: 'code' };
    const kind = map[event.key.toLowerCase()];
    if (kind && !event.shiftKey) {
      event.preventDefault();
      applyFormat(kind);
      return;
    }
  }

  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    if (isMobile.value) return; // newline on mobile; send button sends
    event.preventDefault();
    handleSubmit();
  }
}

function focusInput() {
  nextTick(() => { messageInput.value?.focus({ preventScroll: true }); });
}

function setContent(newContent) {
  content.value = newContent;
  nextTick(() => {
    autoResize();
    focusInput();
    const el = messageInput.value;
    if (el) el.setSelectionRange(el.value.length, el.value.length);
  });
}

function getContent() {
  return content.value;
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

defineExpose({ focusInput, setContent, getContent, insertRecognizedText });
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
