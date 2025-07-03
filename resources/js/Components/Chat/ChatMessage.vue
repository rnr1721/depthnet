<template>
  <div class="flex">
    <div :class="[
      'max-w-xs sm:max-w-md lg:max-w-2xl xl:max-w-3xl rounded-2xl px-4 py-3 shadow-sm transition-all hover:shadow-md relative group',
      messageClass,
      message.role === 'user' ? 'ml-auto' : 'mr-auto'
    ]">
      <!-- Delete message button -->
      <button @click="$emit('delete')" :class="[
        'absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity',
        'w-6 h-6 rounded-full flex items-center justify-center',
        'hover:bg-red-500 hover:text-white',
        isDark ? 'text-gray-400 hover:bg-red-600' : 'text-gray-500 hover:bg-red-500'
      ]" title="Delete message">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
      </button>

      <!-- Message role label -->
      <div :class="[
        'text-xs mb-2 font-medium',
        messageLabelColor
      ]">
        {{ messageRoleLabel }}
      </div>

      <!-- Message content (without commands) -->
      <div class="message-content leading-relaxed" v-html="formattedContent"></div>

      <!-- Commands as separate Vue components -->
      <div v-for="command in extractedCommands" :key="command.id" :class="[
        'border rounded-md mt-2 mb-2',
        isDark ? 'bg-blue-900 border-blue-700' : 'bg-blue-50 border-blue-200'
      ]">
        <div @click="toggleCommand(command.id)" :class="[
          'font-semibold text-sm p-3 pb-2 flex items-center cursor-pointer transition-all duration-200 rounded-t-md',
          isDark ? 'text-blue-300 hover:text-blue-200 hover:bg-blue-800' : 'text-blue-700 hover:text-blue-800 hover:bg-blue-100'
        ]">
          <span class="mr-2 transition-transform duration-300 ease-out"
            :class="{ 'rotate-90': commandStates[command.id] }">▶</span>
          <span class="mr-2">⚡</span>{{ command.name }}
        </div>
        <div :class="[
          'overflow-hidden transition-all duration-500 ease-in-out',
          commandStates[command.id] ? 'opacity-100' : 'max-h-0 opacity-0'
        ]">
          <div class="px-3 pb-3 transform transition-transform duration-300"
            :class="commandStates[command.id] ? 'translate-y-0' : '-translate-y-2'">
            <pre :class="[
              'p-2 rounded text-sm overflow-x-auto',
              isDark ? 'bg-gray-800 text-gray-100' : 'bg-gray-100 text-gray-800'
            ]"><code>{{ command.content }}</code></pre>
          </div>
        </div>
      </div>

      <!-- Show/hide button (if there are results) -->
      <button v-if="hasCommandResults" @click="isResultsExpanded = !isResultsExpanded" :class="[
        'text-sm mt-2 mb-2 px-2 py-1 rounded transition-colors flex items-center',
        isDark ? 'text-blue-400 hover:text-blue-300 hover:bg-gray-700' : 'text-blue-600 hover:text-blue-800 hover:bg-blue-50'
      ]">
        <span class="mr-1 transition-transform duration-200" :class="{ 'rotate-90': isResultsExpanded }">▶</span>
        {{ isResultsExpanded ? t('chat_agent_results_show') : t('chat_agent_results_hide') }} {{ t('chat_agent_results')
        }}
      </button>

      <!-- Animated container for results -->
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
  showCommandResults: Boolean
});

const isResultsExpanded = ref(props.showAgentResults);
// Reactiv object for storing command states
const commandStates = reactive({});

defineEmits(['delete']);

const hasCommandResults = computed(() => {
  return props.message.content.includes('<agent_output_results>');
});

const commandResults = computed(() => {
  const marker = '<agent_output_results>';
  if (!props.message.content.includes(marker)) return '';

  const lastIndex = props.message.content.lastIndexOf(marker);
  if (lastIndex !== -1) {
    return props.message.content.substring(lastIndex + marker.length).trim();
  }
  return '';
});

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
    case 'user':
      return `${baseClasses} bg-gradient-to-r from-indigo-600 to-indigo-700 text-white border border-indigo-500`;
    case 'assistant':
      return `${baseClasses} ${props.isDark ? 'bg-gray-800 text-gray-100 border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'}`;
    case 'thinking':
      return `${baseClasses} ${props.isDark ? 'bg-gray-700 text-gray-300 border border-gray-600' : 'bg-gray-100 text-gray-700 border border-gray-300'}`;
    case 'speaking':
      return `${baseClasses} ${props.isDark ? 'bg-yellow-900 text-yellow-200 border border-yellow-800' : 'bg-yellow-50 text-yellow-900 border border-yellow-200'} italic`;
    case 'command':
      return `${baseClasses} ${props.isDark ? 'bg-green-900 text-green-200 border border-green-800' : 'bg-green-50 text-gray-800 border border-green-200'}`;
    default:
      return `${baseClasses} ${props.isDark ? 'bg-gray-800 text-gray-100 border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'}`;
  }
});

const messageLabelColor = computed(() => {
  switch (props.message.role) {
    case 'user':
      return 'text-indigo-200';
    case 'assistant':
      return props.isDark ? 'text-indigo-400' : 'text-indigo-600';
    case 'thinking':
      return props.isDark ? 'text-gray-400' : 'text-gray-600';
    case 'speaking':
      return props.isDark ? 'text-yellow-400' : 'text-yellow-700';
    case 'command':
      return props.isDark ? 'text-green-400' : 'text-green-700';
    default:
      return props.isDark ? 'text-gray-400' : 'text-gray-600';
  }
});

const messageRoleLabel = computed(() => {
  switch (props.message.role) {
    case 'user':
      return t('chat_user');
    case 'assistant':
      return props.appName;
    case 'thinking':
      return t('chat_thinking');
    case 'speaking':
      return t('chat_speaking');
    case 'command':
      return t('chat_thinking');
    default:
      return t('chat_system');
  }
});

const extractedCommands = computed(() => {
  const commands = [];
  let commandCounter = 0;
  const content = props.message.content;

  // Remove command results - cut by LAST occurrence of marker
  const commandResultsMarker = '<agent_output_results>';
  let userContent = content;

  if (content.includes(commandResultsMarker)) {
    const lastIndex = content.lastIndexOf(commandResultsMarker);
    if (lastIndex !== -1) {
      userContent = content.substring(0, lastIndex).trim();
    }
  }

  // Extract commands
  const commandRegex = /\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1\]/gs;
  let match;

  while ((match = commandRegex.exec(userContent)) !== null) {
    const [, plugin, method, commandContent] = match;
    const methodDisplay = method ? ` ${method}` : '';
    const pluginName = `${plugin}${methodDisplay}`;
    const commandId = `cmd_${props.message.id}_${commandCounter++}`;

    // Initialize command state
    if (!(commandId in commandStates)) {
      commandStates[commandId] = props.showCommandResults;
    }

    commands.push({
      id: commandId,
      name: pluginName,
      content: commandContent.trim()
    });
  }

  return commands;
});

const formattedContent = computed(() => {
  let content = props.message.content;
  const commandResultsMarker = '<agent_output_results>';
  let userContent = content;

  // Remove command results from main content - last marker!
  if (content.includes(commandResultsMarker)) {
    const lastIndex = content.lastIndexOf(commandResultsMarker);
    if (lastIndex !== -1) {
      userContent = content.substring(0, lastIndex).trim();
    }
  }

  // Remove commands from content (they are now displayed separately)
  userContent = userContent.replace(
    /\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1\]/gs,
    ''
  );

  // FIRST: Replace fake agent markers with placeholder
  userContent = userContent.replace(
    /<agent_output_results>/g,
    '___FAKE_AGENT_MARKER___'
  );

  // THEN: Escape HTML tags so they don't render as HTML (except markdown)
  userContent = userContent.replace(/</g, '&lt;').replace(/>/g, '&gt;');

  // FINALLY: Replace placeholder with styled HTML
  userContent = userContent.replace(
    /___FAKE_AGENT_MARKER___/g,
    `<span class="${props.isDark ? 'bg-red-900 text-red-300 border-red-700' : 'bg-red-100 text-red-700 border-red-300'} border px-2 py-1 rounded text-sm font-mono" title="Fake agent output marker from model">
      <span class="mr-1">⚠️</span>&lt;agent_output_results&gt;
    </span>`
  );

  // Process unclosed commands (leave as is)
  userContent = userContent.replace(
    /\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](?![^[]*\[\/\1\])/g,
    (match, plugin, method) => {
      const methodDisplay = method ? ` ${method}` : '';
      const pluginName = `${plugin}${methodDisplay}`;

      return `<span class="${props.isDark ? 'bg-red-900 text-red-300 border-red-700' : 'bg-red-100 text-red-700 border-red-300'} border px-2 py-1 rounded text-sm font-mono" title="Unclosed command tag">
        <span class="mr-1">⚠️</span>[${pluginName}]
      </span>`;
    }
  );

  // Highlight all remaining <agent_output_results> tags in red (these are fake ones from the model)
  userContent = userContent.replace(
    /<agent_output_results>/g,
    `<span class="${props.isDark ? 'bg-red-900 text-red-300 border-red-700' : 'bg-red-100 text-red-700 border-red-300'} border px-2 py-1 rounded text-sm font-mono" title="Fake agent output marker from model">
      <span class="mr-1">⚠️</span>&lt;agent_output_results&gt;
    </span>`
  );

  const userHtml = marked.parse(userContent, {
    breaks: true,
    gfm: true
  });

  return DOMPurify.sanitize(userHtml);
});

/**
 * Toggles command state
 */
function toggleCommand(commandId) {
  commandStates[commandId] = !commandStates[commandId];
}

function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function formatTime(timestamp) {
  if (!timestamp) return '';
  const date = new Date(timestamp);
  return date.toLocaleTimeString('ru-RU', {
    hour: '2-digit',
    minute: '2-digit'
  });
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
</style>
