<template>
  <div class="flex">
    <div :class="[
      'max-w-xs sm:max-w-md lg:max-w-2xl xl:max-w-3xl rounded-2xl px-4 py-3 shadow-sm transition-all hover:shadow-md relative group',
      messageClass,
      message.role === 'user' ? 'ml-auto' : 'mr-auto'
    ]">

      <!-- Action buttons: delete + speak (shown on hover) -->
      <div :class="[
        'absolute top-2 right-2 flex items-center gap-1',
        'opacity-0 group-hover:opacity-100 transition-opacity touch-visible'
      ]">

        <!-- Speak button (only for suitable roles and if TTS is available) -->
        <button v-if="hasTTS && isSpeakable" @click="$emit('speak', message)"
          :title="isCurrentlySpeaking ? t('chat_tts_stop') : t('chat_tts_speak')" :class="[
            'w-6 h-6 rounded-full flex items-center justify-center transition-all',
            isCurrentlySpeaking
              ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
              : (isDark ? 'text-gray-400 hover:bg-indigo-600 hover:text-white' : 'text-gray-500 hover:bg-indigo-500 hover:text-white')
          ]">
          <!-- Icon: if currently playing, stop -->
          <svg v-if="isCurrentlySpeaking" class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
            <rect x="6" y="6" width="12" height="12" rx="1.5" />
          </svg>
          <!-- Icon: speaker -->
          <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.536 8.464a5 5 0 010 7.072M12 6v12m0 0L8 14H5a1 1 0 01-1-1v-2a1 1 0 011-1h3l4-4z" />
          </svg>
        </button>

        <!-- Copy source button (only for command role) -->
        <button v-if="showCopySource" @click.stop="copyCommand(message.content, message.id)" :class="[
          'w-6 h-6 rounded-full flex items-center justify-center transition-all',
          isDark ? 'text-gray-400 hover:bg-green-600 hover:text-white' : 'text-gray-500 hover:bg-green-500 hover:text-white'
        ]" :title="t('chat_copy_source') || 'Copy raw content'">
          <!-- Check mark after copying -->
          <svg v-if="copiedSourceId === message.id" class="w-3.5 h-3.5 text-green-400" fill="none" stroke="currentColor"
            viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <!-- Document icon (raw content) -->
          <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
        </button>

        <!-- Delete button -->
        <button @click="$emit('delete')" :class="[
          'w-6 h-6 rounded-full flex items-center justify-center',
          'hover:bg-red-500 hover:text-white',
          isDark ? 'text-gray-400 hover:bg-red-600' : 'text-gray-500 hover:bg-red-500'
        ]" :title="t('chat_delete_message')">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Message role label -->
      <div :class="['text-xs mb-2 font-medium', messageLabelColor]">
        {{ messageRoleLabel }}
      </div>

      <!-- Message content -->
      <!-- Pool message (multiple sources) -->
      <div v-if="poolSources" class="flex flex-col gap-2.5">
        <div v-for="(src, idx) in poolSources" :key="idx">
          <div class="text-xs text-indigo-200 opacity-75 mb-1">
            {{ src.source }}
          </div>
          <div class="text-sm leading-relaxed whitespace-pre-wrap">{{ src.content }}</div>
          {{ formatSourceTime(src.timestamp) }}
          <span v-if="src.ago" :title="t('chat_pool_ago_hint')" class="cursor-help">
            ({{ src.ago }})
          </span>
        </div>
      </div>

      <!-- Regular message content -->
      <div v-else class="message-content leading-relaxed" v-html="formattedContent"></div>

      <!-- Commands -->
      <div v-for="command in extractedCommands" :key="command.id" :class="[
        'border rounded-md mt-2 mb-2',
        isDark ? 'bg-blue-900 border-blue-700' : 'bg-blue-50 border-blue-200'
      ]">
        <div @click="toggleCommand(command.id)" :class="[
          'font-semibold text-sm p-3 pb-2 flex items-center cursor-pointer transition-all duration-200 rounded-t-md',
          isDark ? 'text-blue-300' : 'text-blue-700 hover:text-blue-800'
        ]">
          <span class="mr-2 transition-transform duration-300 ease-out"
            :class="{ 'rotate-90': commandStates[command.id] }">▶</span>
          <span class="mr-2">{{ command.icon }}</span>
          <span class="flex-1">{{ command.name }}</span>

          <!-- Copy button -->
          <button @click.stop="copyCommand(command.content)" :class="[
            'w-6 h-6 rounded flex items-center justify-center transition-all ml-2',
            isDark ? 'text-blue-400 hover:bg-blue-700 hover:text-white' : 'text-blue-500 hover:bg-blue-200 hover:text-blue-800'
          ]" title="Copy">
            <!-- Check mark after copying -->
            <svg v-if="copiedCommandId === command.content" class="w-3.5 h-3.5 text-green-400" fill="none"
              stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <!-- Copy icon -->
            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </button>
        </div>
        <div :class="[
          'overflow-hidden transition-all duration-500 ease-in-out',
          commandStates[command.id] ? 'opacity-100' : 'max-h-0 opacity-0'
        ]">
          <div class="px-3 pb-3 transform transition-transform duration-300"
            :class="commandStates[command.id] ? 'translate-y-0' : '-translate-y-2'">
            <pre @click="selectAll" :class="[
              'p-2 rounded text-sm overflow-x-auto cursor-text select-all',
              isDark ? 'bg-gray-800 text-gray-100' : 'bg-gray-100 text-gray-800'
            ]"><code>{{ command.content }}</code></pre>
          </div>
        </div>
      </div>

      <!-- Tool Calls (new format) -->
      <div v-for="toolCall in extractedToolCalls" :key="toolCall.id" :class="[
        'border rounded-md mt-2 mb-2',
        isDark ? 'bg-blue-900 border-blue-700' : 'bg-purple-50 border-purple-200'
      ]">
        <div @click="toggleCommand(toolCall.id)" :class="[
          'font-semibold text-sm p-3 pb-2 flex items-center cursor-pointer transition-all duration-200 rounded-t-md',
          isDark ? 'text-blue-300' : 'text-blue-700 hover:text-blue-800'
        ]">
          <span class="mr-2 transition-transform duration-300 ease-out"
            :class="{ 'rotate-90': commandStates[toolCall.id] }">▶</span>
          <span class="mr-2">{{ toolCall.icon }}</span>
          <span class="flex-1">{{ toolCall.displayName }}</span>

          <!-- Copy arguments button -->
          <button @click.stop="copyCommand(JSON.stringify(toolCall.rawArguments, null, 2))" :class="[
            'w-6 h-6 rounded flex items-center justify-center transition-all ml-2',
            isDark ? 'text-blue-400 hover:bg-blue-700 hover:text-white' : 'text-blue-500 hover:bg-blue-200 hover:text-blue-800'
          ]" title="Copy arguments">
            <svg v-if="copiedCommandId === JSON.stringify(toolCall.rawArguments, null, 2)"
              class="w-3.5 h-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </button>
        </div>
        <div :class="[
          'overflow-hidden transition-all duration-500 ease-in-out',
          commandStates[toolCall.id] ? 'opacity-100' : 'max-h-0 opacity-0'
        ]">
          <div class="px-3 pb-3 transform transition-transform duration-300"
            :class="commandStates[toolCall.id] ? 'translate-y-0' : '-translate-y-2'">
            <pre @click="selectAll" :class="[
              'p-2 rounded text-sm overflow-x-auto cursor-text select-all',
              isDark ? 'bg-gray-800 text-gray-100' : 'bg-gray-100 text-gray-800'
            ]"><code>{{ toolCall.content }}</code></pre>
          </div>
        </div>
      </div>

      <!-- Show/hide results -->
      <button v-if="hasCommandResults" @click="isResultsExpanded = !isResultsExpanded" :class="[
        'text-sm mt-2 mb-2 px-2 py-1 rounded transition-colors flex items-center',
        isDark ? 'text-blue-400 hover:text-blue-300 hover:bg-gray-700' : 'text-blue-600 hover:text-blue-800 hover:bg-blue-50'
      ]">
        <span class="mr-1 transition-transform duration-200" :class="{ 'rotate-90': isResultsExpanded }">▶</span>
        {{ isResultsExpanded ? t('chat_agent_results_show') : t('chat_agent_results_hide') }} {{ t('chat_agent_results')
        }}
      </button>

      <div v-if="hasCommandResults" :class="[
        'overflow-hidden transition-all duration-300 ease-out',
        isResultsExpanded ? 'opacity-100' : 'max-h-0 opacity-0'
      ]">
        <div v-if="commandResults" v-html="resultsHtml"></div>
      </div>

      <!-- Timestamp -->
      <div :class="[
        'text-xs mt-2 text-right opacity-75',
        message.role === 'user'
          ? 'text-indigo-200'
          : (isDark ? 'text-gray-400' : 'text-gray-500')
      ]">
        {{ formatTime(message.created_at) }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import DOMPurify from 'dompurify';
import { marked } from 'marked';

const { t } = useI18n();

const props = defineProps({
  message: Object,
  isDark: Boolean,
  appName: String,
  showAgentResults: Boolean,
  showCommandResults: Boolean,
  // TTS
  hasTTS: { type: Boolean, default: false },
  speakingMessageId: { type: [Number, String, null], default: null },
  presetName: { type: String, default: 'Agent' },
});

const isResultsExpanded = ref(props.showAgentResults);
const commandStates = reactive({});

const copiedSourceId = ref(null);

// Copy command content to clipboard with fallback for older browsers
async function copyCommand(content, sourceId = null) {
  try {
    await navigator.clipboard.writeText(content);
    copiedCommandId.value = content;
    if (sourceId) {
      copiedSourceId.value = sourceId;
      setTimeout(() => copiedSourceId.value = null, 1500);
    }
    setTimeout(() => copiedCommandId.value = null, 1500);
  } catch {
    const el = document.createElement('textarea');
    el.value = content;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
  }
}

const copiedCommandId = ref(null);

defineEmits(['delete', 'speak']);

const pluginIcons = {
  // Memory & Knowledge
  'vectormemory': '🧠',
  'memory': '📝',
  'journal': '📔',
  'person': '👤',
  'workspace': '📋',
  'skill': '📚',

  // Actions
  'run': '▶️',
  'shell': '💻',
  'browser': '🌐',
  'telegram': '📱',

  // State & Control
  'heart': '❤️',
  'being': '✨',
  'goal': '🎯',
  'rhythm': '⏰',

  // Communication
  'agent': '🤖',

  // Search & Retrieval
  'rag': '🔍',

  // Tasks
  'task': '✅',

  // Mood
  'mood': '😊',

  // MCP
  'mcp': '🔌',
};

// ─── TTS helpers ─────────────────────────────────────────────────────────────

/** Message is suitable for speech synthesis (role + no command results) */
const isSpeakable = computed(() => {
  const { role, content } = props.message;
  if (role === 'thinking') return true;
  if (['system', 'assistant', 'speaking'].includes(role)) {
    return !content.includes('<system_output_results>');
  }
  return false;
});

/** Now this message is being spoken */
const isCurrentlySpeaking = computed(() =>
  props.speakingMessageId !== null &&
  props.speakingMessageId === props.message.id
);

// ─── Computed ─────────────────────────────────────────────────────────────────

const hasCommandResults = computed(() =>
  props.message.content.includes('<system_output_results>')
);

const commandResults = computed(() => {
  const marker = '<system_output_results>';
  if (!props.message.content.includes(marker)) return '';
  const lastIndex = props.message.content.lastIndexOf(marker);
  return lastIndex !== -1
    ? props.message.content.substring(lastIndex + marker.length).trim()
    : '';
});

/**
 * Parses pool message — JSON with sources array.
 * Returns array of sources or null if plain text.
 */
const poolSources = computed(() => {
  if (props.message.role !== 'user') return null;
  const raw = props.message.content?.trim();
  if (!raw || !raw.startsWith('{')) return null;
  try {
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed.sources) && parsed.sources.length > 0) {
      return parsed.sources;
    }
  } catch {
    // not JSON — render as usual
  }
  return null;
});

function formatSourceTime(timestamp) {
  if (!timestamp) return '';
  try {
    return new Date(timestamp).toLocaleTimeString('ru-RU', {
      hour: '2-digit', minute: '2-digit'
    });
  } catch {
    return '';
  }
}

const resultsHtml = computed(() => {
  if (!commandResults.value) return '';
  return DOMPurify.sanitize(`
    <div class="${props.isDark ? 'p-2 bg-slate-800 border-slate-600' : 'bg-gray-50 border-gray-300'} border-t-2 mt-4 pt-4">
      <div class="font-semibold ${props.isDark ? 'text-green-400' : 'text-green-700'} text-sm mb-2 flex items-center">
        <span class="mr-2">🤖</span> Command Results:
      </div>
      <pre class="${props.isDark ? 'bg-gray-900 text-gray-100' : 'bg-white text-gray-800'} p-3 rounded text-sm overflow-x-auto whitespace-pre-wrap"><code>${escapeHtml(commandResults.value)}</code></pre>
    </div>
  `);
});

const messageClass = computed(() => {
  const baseClasses = 'backdrop-blur-sm';
  switch (props.message.role) {
    case 'user': return `${baseClasses} bg-gradient-to-r from-indigo-600 to-indigo-700 text-white border border-indigo-500`;
    case 'assistant': return `${baseClasses} ${props.isDark ? 'bg-gray-800 text-gray-100 border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'}`;
    case 'thinking': return `${baseClasses} ${props.isDark ? 'bg-gray-700 text-gray-300 border border-gray-600' : 'bg-gray-100 text-gray-700 border border-gray-300'}`;
    case 'speaking': return `${baseClasses} ${props.isDark ? 'bg-yellow-900 text-yellow-200 border border-yellow-800' : 'bg-yellow-50 text-yellow-900 border border-yellow-200'} italic`;
    case 'command': return `${baseClasses} ${props.isDark ? 'bg-green-900 text-green-200 border border-green-800' : 'bg-green-50 text-gray-800 border border-green-200'}`;
    default: return `${baseClasses} ${props.isDark ? 'bg-gray-800 text-gray-100 border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'}`;
  }
});

const messageLabelColor = computed(() => {
  switch (props.message.role) {
    case 'user': return 'text-indigo-200';
    case 'assistant': return props.isDark ? 'text-indigo-400' : 'text-indigo-600';
    case 'thinking': return props.isDark ? 'text-gray-400' : 'text-gray-600';
    case 'speaking': return props.isDark ? 'text-yellow-400' : 'text-yellow-700';
    case 'command': return props.isDark ? 'text-green-400' : 'text-green-700';
    default: return props.isDark ? 'text-gray-400' : 'text-gray-600';
  }
});

const messageRoleLabel = computed(() => {
  const name = props.presetName || props.appName || 'Agent';
  switch (props.message.role) {
    case 'user': return '💬 ' + t('chat_input');
    case 'result': return '🇩 ' + props.appName;
    case 'thinking': return '💭 ' + name + ' ' + t('chat_thinking');
    case 'speaking': return '💬 ' + name + ' ' + t('chat_speaking');
    case 'command': return '⚡ ' + name + ' ' + t('chat_act');
    default: return '⚙️ ' + t('chat_system');
  }
});

const showCopySource = computed(() => {
  return props.message.role === 'command' || props.message.role === 'result' || props.message.role === 'system';
});

const extractedCommands = computed(() => {
  const commands = [];
  let commandCounter = 0;
  const content = props.message.content;

  const commandResultsMarker = '<system_output_results>';
  let userContent = content;
  if (content.includes(commandResultsMarker)) {
    const lastIndex = content.lastIndexOf(commandResultsMarker);
    if (lastIndex !== -1) userContent = content.substring(0, lastIndex).trim();
  }

  const commandRegex = /\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1(?:\s+[a-z][a-z0-9_]*)?\]/gs;
  let match;
  while ((match = commandRegex.exec(userContent)) !== null) {
    const [, plugin, method, commandContent] = match;

    if (plugin === 'agent' && method === 'speak') {
      continue;
    }

    const methodDisplay = method ? ` ${method}` : '';
    const pluginName = `${plugin}${methodDisplay}`;
    const commandId = `cmd_${props.message.id}_${commandCounter++}`;
    if (!(commandId in commandStates)) commandStates[commandId] = props.showCommandResults;
    commands.push({ id: commandId, name: pluginName, content: commandContent.trim(), icon: getPluginIcon(plugin, method) });
  }
  return commands;
});

const extractedToolCalls = computed(() => {
  const content = props.message.content;
  if (!content) return [];

  // Find JSON containing "tool_calls" — either array or object with tool_calls key
  let toolCallsData = null;

  // Try to find {...} or [...] containing "tool_calls"
  const jsonMatches = content.match(/(\{[^{}]*"tool_calls"\s*:\s*\[[^\]]*\][^{}]*\}|\[[^\]]*"type"\s*:\s*"function"[^\]]*\])/g);
  if (!jsonMatches) return [];

  for (const candidate of jsonMatches) {
    try {
      const parsed = JSON.parse(candidate);
      // Normalize to array of calls
      if (Array.isArray(parsed)) {
        // Anthropic-like format: array of objects with name, input fields
        if (parsed[0]?.type === 'tool_use') {
          toolCallsData = parsed.map(tc => ({
            id: tc.id,
            name: tc.name,
            arguments: tc.input
          }));
        }
        // OpenAI bare array
        else if (parsed[0]?.type === 'function') {
          toolCallsData = parsed.map(tc => ({
            id: tc.id,
            name: tc.function.name,
            arguments: JSON.parse(tc.function.arguments || '{}')
          }));
        }
      } else if (parsed.tool_calls) {
        // OpenAI wrapped: {tool_calls: [...]}
        toolCallsData = parsed.tool_calls.map(tc => ({
          id: tc.id,
          name: tc.function.name,
          arguments: JSON.parse(tc.function.arguments || '{}')
        }));
      }
      if (toolCallsData) break;
    } catch (e) {
      // invalid JSON, skip
    }
  }

  if (!toolCallsData) return [];

  // Фильтруем: убираем agent speak
  const filteredCalls = toolCallsData.filter(tc => {
    const name = tc.name?.toLowerCase();
    const method = tc.arguments?.method?.toLowerCase();
    // Скрываем agent speak, но оставляем agent handoff, pause, resume и т.д.
    return !(name === 'agent' && method === 'speak');
  });

  if (filteredCalls.length === 0) return [];

  // Convert to a format suitable for display
  return filteredCalls.map((tc, idx) => {
    const method = tc.arguments?.method || '';
    const argsContent = (() => {
      const { method, ...rest } = tc.arguments || {};
      if (Object.keys(rest).length === 1 && rest.content) {
        return rest.content;
      }
      return JSON.stringify(rest, null, 2);
    })();

    const toolCallId = `tc_${props.message.id}_${idx}`;

    if (!(toolCallId in commandStates)) {
      commandStates[toolCallId] = props.showCommandResults;
    }

    return {
      id: toolCallId,
      plugin: tc.name,
      method: method,
      displayName: method ? `${tc.name} ${method}` : tc.name,
      content: argsContent,
      rawArguments: tc.arguments,
      icon: getPluginIcon(tc.name, method)
    };
  });
});

const formattedContent = computed(() => {
  let content = props.message.content;

  content = content.replace(/\{(?:[^{}]*"tool_calls"\s*:\s*\[[^\]]*\][^{}]*)\}/g, '');
  content = content.replace(/\[\s*\{[^]]*"type"\s*:\s*"function"[^]]*\}\s*\]/g, '');

  const commandResultsMarker = '<system_output_results>';
  let userContent = content;

  if (content.includes(commandResultsMarker)) {
    const lastIndex = content.lastIndexOf(commandResultsMarker);
    if (lastIndex !== -1) userContent = content.substring(0, lastIndex).trim();
  }

  userContent = userContent.replace(/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1(?:\s+[a-z][a-z0-9_]*)?\]/gs, '');
  userContent = userContent.replace(/<system_output_results>/g, '___FAKE_AGENT_MARKER___');
  userContent = userContent.replace(/</g, '&lt;').replace(/>/g, '&gt;');
  userContent = userContent.replace(
    /___FAKE_AGENT_MARKER___/g,
    `<span class="${props.isDark ? 'bg-red-900 text-red-300 border-red-700' : 'bg-red-100 text-red-700 border-red-300'} border px-2 py-1 rounded text-sm font-mono" title="Fake agent output marker from model">
      <span class="mr-1">⚠️</span>&lt;system_output_results&gt;
    </span>`
  );
  userContent = userContent.replace(
    /\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](?![^[]*\[\/\1(?:\s+[a-z][a-z0-9_]*)?\])/g,
    (match, plugin, method) => {
      const methodDisplay = method ? ` ${method}` : '';
      const pluginName = `${plugin}${methodDisplay}`;
      return `<span class="${props.isDark ? 'bg-red-900 text-red-300 border-red-700' : 'bg-red-100 text-red-700 border-red-300'} border px-2 py-1 rounded text-sm font-mono" title="Unclosed command tag">
        <span class="mr-1">⚠️</span>[${pluginName}]
      </span>`;
    }
  );
  userContent = userContent.replace(
    /<system_output_results>/g,
    `<span class="${props.isDark ? 'bg-red-900 text-red-300 border-red-700' : 'bg-red-100 text-red-700 border-red-300'} border px-2 py-1 rounded text-sm font-mono" title="Fake agent output marker from model">
      <span class="mr-1">⚠️</span>&lt;system_output_results&gt;
    </span>`
  );

  const userHtml = marked.parse(userContent, { breaks: true, gfm: true });
  return DOMPurify.sanitize(userHtml);
});

// ─── Methods ──────────────────────────────────────────────────────────────────

function toggleCommand(commandId) {
  commandStates[commandId] = !commandStates[commandId];
}

function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatTime(timestamp) {
  if (!timestamp) return '';
  return new Date(timestamp).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function selectAll(event) {
  const el = event.target.closest('pre');
  if (!el) return;
  const range = document.createRange();
  range.selectNodeContents(el);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
}

function getPluginIcon(pluginName, method = null) {
  // Extract the base plugin name (without method)
  const baseName = pluginName.toLowerCase().split(' ')[0];

  // Special handling for agent methods
  if (baseName === 'agent') {
    const methodLower = method?.toLowerCase();
    if (methodLower === 'speak') return '💬';
    if (methodLower === 'handoff') return '🔄';
    if (methodLower === 'pause') return '⏸️';
    if (methodLower === 'resume') return '▶️';
    return '🤖';
  }

  // Return the icon from the mapping or a default one
  return pluginIcons[baseName] || '🛠️';
}
</script>

<style>
.message-content pre {
  background-color: #f3f4f6;
  border-radius: 0.5rem;
  padding: 1rem;
  overflow-x: auto;
  margin: 0.5rem 0;
}

.dark .message-content pre {
  background-color: #1f2937;
}

.message-content code {
  font-family: monospace;
  font-size: 0.875rem;
  background-color: rgba(0, 0, 0, 0.1);
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
}

.dark .message-content code {
  background-color: rgba(255, 255, 255, 0.1);
}

.message-content pre code {
  background-color: transparent;
  padding: 0;
}

.message-content h1,
.message-content h2,
.message-content h3 {
  font-weight: bold;
  margin-top: 1rem;
  margin-bottom: 0.5rem;
}

.message-content h1 {
  font-size: 1.25rem;
}

.message-content h2 {
  font-size: 1.125rem;
}

.message-content h3 {
  font-size: 1rem;
}

.message-content ul,
.message-content ol {
  margin-left: 1rem;
  margin-bottom: 0.5rem;
}

.message-content li {
  margin-bottom: 0.25rem;
}

.message-content blockquote {
  border-left: 4px solid #d1d5db;
  padding-left: 1rem;
  font-style: italic;
  margin: 0.5rem 0;
}

.dark .message-content blockquote {
  border-left-color: #4b5563;
}

@media (hover: none) {
  .touch-visible {
    opacity: 1 !important;
  }
}
</style>