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

    <!-- Sidebar -->
    <div :class="[
      'fixed inset-y-0 left-0 z-50 w-80 transform transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:inset-0',
      mobileMenuOpen ? 'translate-x-0' : '-translate-x-full',
      'flex flex-col shadow-xl lg:shadow-md',
      isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
    ]">
      <!-- Header -->
      <div :class="[
        'p-4 border-b flex-shrink-0',
        isDark ? 'border-gray-700' : 'border-gray-200'
      ]">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div
              class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center">
              <span class="text-white font-bold text-sm">{{ page.props.app_name.charAt(0).toUpperCase() }}</span>
            </div>
            <a :href="route('home')" :class="[
              'font-bold text-xl',
              isDark ? 'text-white' : 'text-gray-900'
            ]">
              {{ page.props.app_name }}
            </a>
          </div>
          <button @click="mobileMenuOpen = false"
            class="lg:hidden p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <!-- User info -->
        <div class="mt-4 space-y-2">
          <Link :href="route('profile.show')" :class="[
            'block text-sm px-3 py-2 rounded-md transition-colors',
            isDark
              ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
              : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
          ]">
          {{ user.name }}
          </Link>
          <Link v-if="isAdmin" :href="route('admin.settings')" :class="[
            'block text-sm px-3 py-2 rounded-md transition-colors',
            isDark
              ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
              : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
          ]">
          {{ t('chat_settings') }}
          </Link>
          <Link v-if="isAdmin" :href="route('admin.users.index')" :class="[
            'block text-sm px-3 py-2 rounded-md transition-colors',
            isDark
              ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
              : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
          ]">
          {{ t('chat_users') }}
          </Link>
        </div>
      </div>

      <!-- Controls -->
      <div class="flex-1 overflow-y-auto p-4 space-y-4">

        <!-- Clear history -->
        <button v-if="isAdmin" @click="confirmClearHistory" :class="[
          'w-full px-4 py-3 rounded-xl font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2',
          isDark
            ? 'bg-red-600 hover:bg-red-700 text-white focus:ring-offset-gray-800'
            : 'bg-red-600 hover:bg-red-700 text-white'
        ]">
          {{ t('chat_clear_history') }}
        </button>

        <!-- Model selection -->
        <div v-if="isAdmin" :class="[
          'p-4 rounded-xl border transition-colors',
          isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
        ]">
          <label :class="[
            'block text-sm font-medium mb-2',
            isDark ? 'text-gray-200' : 'text-gray-700'
          ]">{{ t('chat_model_selection') || 'Model' }}</label>
          <select v-model="selectedModel" :class="[
            'w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
            'px-3 py-2 text-sm',
            isDark
              ? 'bg-gray-600 text-white ring-gray-500'
              : 'bg-white text-gray-900 ring-gray-300'
          ]">
            <option v-for="model in availableModels" :key="model.name" :value="model.name">
              {{ model.displayName }}
            </option>
          </select>
        </div>

        <!-- Model active toggle -->
        <div v-if="isAdmin && !isSingleMode" :class="[
          'p-4 rounded-xl border transition-colors',
          isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
        ]">
          <label class="flex items-center justify-between cursor-pointer">
            <span :class="[
              'text-sm font-medium',
              isDark ? 'text-gray-200' : 'text-gray-700'
            ]">{{ t('chat_model_active') || 'Model active' }}</span>
            <input type="checkbox" v-model="isModelActive" class="sr-only">
            <div :class="[
              'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
              isModelActive ? 'bg-green-600' : (isDark ? 'bg-gray-600' : 'bg-gray-300')
            ]">
              <span :class="[
                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                isModelActive ? 'translate-x-6' : 'translate-x-1'
              ]"></span>
            </div>
          </label>
          <p :class="[
            'text-xs mt-1 opacity-75',
            isDark ? 'text-gray-400' : 'text-gray-600'
          ]">
            {{ isModelActive ? (t('chat_model_processing') || 'Loop active') : (t('chat_model_paused') ||
              'Loop stopped') }}
          </p>
        </div>

        <!-- Theme toggle -->
        <div :class="[
          'p-4 rounded-xl border transition-colors',
          isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
        ]">
          <div class="flex items-center justify-between">
            <span :class="[
              'text-sm font-medium',
              isDark ? 'text-gray-200' : 'text-gray-700'
            ]">{{ t('chat_dark_mode') }}</span>
            <button @click="toggleTheme" :class="[
              'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
              isDark ? 'bg-indigo-600' : 'bg-gray-300'
            ]">
              <span :class="[
                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                isDark ? 'translate-x-6' : 'translate-x-1'
              ]"></span>
            </button>
          </div>
        </div>

        <!-- Show thinking toggle -->
        <div :class="[
          'p-4 rounded-xl border transition-colors',
          isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
        ]">
          <label :class="[
            'flex items-center justify-between',
            isSingleMode ? 'cursor-not-allowed' : 'cursor-pointer'
          ]">
            <span :class="[
              'text-sm font-medium',
              isDark ? 'text-gray-200' : 'text-gray-700'
            ]">{{ t('chat_show_thiking') }}</span>
            <input type="checkbox" :disabled="isSingleMode" v-model="showThinking" class="sr-only">
            <div :class="[
              'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
              showThinking ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-300')
            ]">
              <span :class="[
                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                showThinking ? 'translate-x-6' : 'translate-x-1'
              ]"></span>
            </div>
          </label>
        </div>

        <!-- Chat export -->
        <div v-if="isAdmin" :class="[
          'p-4 rounded-xl border transition-colors',
          isDark ? 'bg-gray-700 border-gray-600' : 'bg-gray-50 border-gray-200'
        ]">
          <label :class="[
            'block text-sm font-medium mb-2',
            isDark ? 'text-gray-200' : 'text-gray-700'
          ]">{{ t('chat_export_format') || 'Dialog export' }}</label>

          <div class="space-y-3">
            <select v-model="selectedExportFormat" :class="[
              'w-full rounded-lg border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
              'px-3 py-2 text-sm',
              isDark
                ? 'bg-gray-600 text-white ring-gray-500'
                : 'bg-white text-gray-900 ring-gray-300'
            ]">
              <option value="">{{ t('chat_select_format') || 'Please choose format' }}</option>
              <option v-for="format in exportFormats" :key="format.name" :value="format.name">
                {{ format.displayName }} (.{{ format.extension }})
              </option>
            </select>

            <label class="flex items-center space-x-2 cursor-pointer">
              <input type="checkbox" v-model="includeThinking" :class="[
                'rounded border-gray-300 text-indigo-600 focus:ring-indigo-500'
              ]">
              <span :class="[
                'text-sm',
                isDark ? 'text-gray-200' : 'text-gray-700'
              ]">{{ t('chat_include_thinking') || 'Turn on AI think messages' }}</span>
            </label>

            <button @click="exportChat" :disabled="!selectedExportFormat || isExporting" :class="[
              'w-full px-4 py-2 rounded-lg font-medium transition-all',
              'disabled:opacity-50 disabled:cursor-not-allowed',
              isDark
                ? 'bg-indigo-600 hover:bg-indigo-700 text-white'
                : 'bg-indigo-600 hover:bg-indigo-700 text-white'
            ]">
              <svg v-if="isExporting" class="w-4 h-4 animate-spin inline mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
              </svg>
              {{ isExporting ? (t('chat_exporting') || 'Export...') : (t('chat_export') || 'Экспортировать') }}
            </button>
          </div>
        </div>

      </div>

      <!-- Logout -->
      <div :class="[
        'p-4 border-t flex-shrink-0',
        isDark ? 'border-gray-700' : 'border-gray-200'
      ]">
        <Link :href="route('logout')" method="post" as="button" :class="[
          'w-full px-4 py-3 rounded-xl font-medium transition-all',
          isDark
            ? 'text-gray-300 hover:text-white hover:bg-gray-700'
            : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100'
        ]">
        {{ t('chat_logout') }}
        </Link>
      </div>
    </div>

    <!-- Main chat area -->
    <div class="flex-1 flex flex-col overflow-hidden lg:ml-0">
      <!-- Mobile header -->
      <div :class="[
        'lg:hidden p-4 border-b flex items-center justify-between flex-shrink-0',
        isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
      ]">
        <button @click="mobileMenuOpen = true" :class="[
          'p-2 rounded-md transition-colors',
          isDark ? 'hover:bg-gray-700 text-gray-300' : 'hover:bg-gray-100 text-gray-600'
        ]">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
          </svg>
        </button>
        <span :class="[
          'font-bold text-lg',
          isDark ? 'text-white' : 'text-gray-900'
        ]">{{ page.props.app_name }}</span>
        <div class="w-10"></div>
      </div>

      <!-- Messages -->
      <div :class="[
        'flex-1 overflow-y-auto p-4 sm:p-6 space-y-4',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
      ]" ref="messagesContainer">
        <div v-if="filteredMessages.length === 0" :class="[
          'text-center my-8 p-8 rounded-2xl',
          isDark ? 'text-gray-400 bg-gray-800' : 'text-gray-500 bg-white'
        ]">
          <div class="text-4xl mb-4">💬</div>
          <p class="text-lg font-medium">{{ t('chat_please_write_message') }}</p>
          <p class="text-sm mt-2 opacity-75">{{ t('chat_start_dialog_with_ai') }}</p>
        </div>

        <div v-for="message in filteredMessages" :key="message.id" class="flex">
          <div :class="[
            'max-w-xs sm:max-w-md lg:max-w-2xl xl:max-w-3xl rounded-2xl px-4 py-3 shadow-sm transition-all hover:shadow-md relative group',
            messageClass(message),
            message.role === 'user' ? 'ml-auto' : 'mr-auto'
          ]">

            <!-- Delete message button -->
            <button @click="deleteMessage(message.id)" :class="[
              'absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity',
              'w-6 h-6 rounded-full flex items-center justify-center',
              'hover:bg-red-500 hover:text-white',
              isDark ? 'text-gray-400 hover:bg-red-600' : 'text-gray-500 hover:bg-red-500'
            ]" title="Delete message">
              <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>

            <div :class="[
              'text-xs mb-2 font-medium',
              getMessageLabelColor(message)
            ]">
              {{ messageRoleLabel(message) }}
            </div>
            <div class="message-content leading-relaxed" v-html="formatMessage(message.content)"></div>
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
      </div>

      <!-- Message input -->
      <div :class="[
        'p-4 border-t flex-shrink-0',
        isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
      ]">
        <form @submit.prevent="sendMessage" class="flex space-x-3">
          <div class="flex-1 relative">
            <textarea v-model="form.content" :disabled="form.processing || isProcessing"
              :placeholder="t('chat_input_ph')" :class="[
                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all resize-none',
                'px-4 py-3 text-sm leading-relaxed',
                isDark
                  ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                  : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
              ]" rows="1" @input="autoResize" @keydown.enter.exact.prevent="sendMessage" ref="messageInput"></textarea>
            <div v-if="isProcessing" class="absolute right-3 top-3">
              <div class="flex space-x-1">
                <div :class="[
                  'w-2 h-2 rounded-full animate-bounce',
                  isDark ? 'bg-indigo-400' : 'bg-indigo-500'
                ]" style="animation-delay: 0ms"></div>
                <div :class="[
                  'w-2 h-2 rounded-full animate-bounce',
                  isDark ? 'bg-indigo-400' : 'bg-indigo-500'
                ]" style="animation-delay: 150ms"></div>
                <div :class="[
                  'w-2 h-2 rounded-full animate-bounce',
                  isDark ? 'bg-indigo-400' : 'bg-indigo-500'
                ]" style="animation-delay: 300ms"></div>
              </div>
            </div>
          </div>
          <button type="submit" :disabled="form.processing || !form.content.trim() || isProcessing" :class="[
            'px-6 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
            'enabled:hover:scale-105 enabled:active:scale-95',
            isDark
              ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
              : 'bg-indigo-600 hover:bg-indigo-700 text-white'
          ]">
            <svg v-if="form.processing || isProcessing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
              </path>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
            </svg>
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import PageTitle from '@/Components/PageTitle.vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import axios from 'axios';

const { t } = useI18n();

const page = usePage();

const props = defineProps({
  messages: Array,
  user: Object,
  availableModels: Array,
  currentModel: String,
  modelActive: Boolean,
  exportFormats: Array,
  mode: String
});

// Theme management
const isDark = ref(false);

const toggleTheme = () => {
  isDark.value = !isDark.value;
  localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');

  // Update document class for global theme
  if (isDark.value) {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }
};

// Mobile menu
const mobileMenuOpen = ref(false);

const isAdmin = computed(() => {
  return props.user && props.user.is_admin;
});

const isSingleMode = computed(() => {
  return props.mode === 'single';
});

const messagesContainer = ref(null);
const messageInput = ref(null);
const showThinking = ref(props.mode === 'single');
const localMessages = ref([...props.messages]);
const isProcessing = ref(false);
const selectedModel = ref(props.currentModel);
const isModelActive = ref(props.modelActive);
const selectedExportFormat = ref('');
const includeThinking = ref(false);
const isExporting = ref(false);

let refreshInterval = null;

// Get only needed messages depending on the view mode
const filteredMessages = computed(() => {
  if (showThinking.value) {
    return localMessages.value;
  } else {
    return localMessages.value.filter(message => message.is_visible_to_user);
  }
});

const form = useForm({
  content: '',
});

function messageClass(message) {
  const baseClasses = 'backdrop-blur-sm';

  switch (message.role) {
    case 'user':
      return `${baseClasses} bg-gradient-to-r from-indigo-600 to-indigo-700 text-white border border-indigo-500`;
    case 'assistant':
      return `${baseClasses} ${isDark.value ? 'bg-gray-800 text-gray-100 border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'}`;
    case 'thinking':
      return `${baseClasses} ${isDark.value ? 'bg-gray-700 text-gray-300 border border-gray-600' : 'bg-gray-100 text-gray-700 border border-gray-300'}`;
    case 'speaking':
      return `${baseClasses} ${isDark.value ? 'bg-yellow-900 text-yellow-200 border border-yellow-800' : 'bg-yellow-50 text-yellow-900 border border-yellow-200'} italic`;
    case 'command':
      return `${baseClasses} ${isDark.value ? 'bg-green-900 text-green-200 border border-green-800' : 'bg-green-50 text-gray-800 border border-green-200'}`;
    default:
      return `${baseClasses} ${isDark.value ? 'bg-gray-800 text-gray-100 border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'}`;
  }
}

function getMessageLabelColor(message) {
  switch (message.role) {
    case 'user':
      return 'text-indigo-200';
    case 'assistant':
      return isDark.value ? 'text-indigo-400' : 'text-indigo-600';
    case 'thinking':
      return isDark.value ? 'text-gray-400' : 'text-gray-600';
    case 'speaking':
      return isDark.value ? 'text-yellow-400' : 'text-yellow-700';
    case 'command':
      return isDark.value ? 'text-green-400' : 'text-green-700';
    default:
      return isDark.value ? 'text-gray-400' : 'text-gray-600';
  }
}

function messageRoleLabel(message) {
  switch (message.role) {
    case 'user':
      return t('chat_user');
    case 'assistant':
      return page.props.app_name;
    case 'thinking':
      return t('chat_thinking');
    case 'speaking':
      return t('chat_speaking');
    case 'command':
      return t('chat_thinking');
    default:
      return t('chat_system');
  }
}

function formatTime(timestamp) {
  if (!timestamp) return '';
  const date = new Date(timestamp);
  return date.toLocaleTimeString('ru-RU', {
    hour: '2-digit',
    minute: '2-digit'
  });
}

function formatMessage(content) {
  // Highlight commands [plugin]content[/plugin] и [plugin method]content[/plugin]
  let formattedContent = content.replace(/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1\]/gs, (match, plugin, method, commandContent) => {
    const methodDisplay = method ? ` ${method}` : '';
    const pluginName = `${plugin}${methodDisplay}`;

    return `<div class="${isDark.value ? 'bg-blue-900 border-blue-700' : 'bg-blue-50 border-blue-200'} border p-3 rounded-md mt-2 mb-2">
      <div class="font-semibold ${isDark.value ? 'text-blue-300' : 'text-blue-700'} text-sm mb-1">
        ${pluginName}
      </div>
      <pre class="${isDark.value ? 'bg-gray-800 text-gray-100' : 'bg-gray-100 text-gray-800'} p-2 rounded text-sm overflow-x-auto"><code>${escapeHtml(commandContent.trim())}</code></pre>
    </div>`;
  });

  const html = marked.parse(formattedContent, {
    breaks: true,
    gfm: true
  });

  return DOMPurify.sanitize(html);
}

function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function autoResize() {
  nextTick(() => {
    if (messageInput.value) {
      messageInput.value.style.height = 'auto';
      messageInput.value.style.height = Math.min(messageInput.value.scrollHeight, 120) + 'px';
    }
  });
}

function scrollToBottom() {
  setTimeout(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
  }, 100);
}

// Refresh messages via API
function refreshMessages() {
  let lastId = 0;
  if (localMessages.value.length > 0) {
    lastId = Math.max(...localMessages.value.map(msg => msg.id));
  }

  axios.get(route('chat.new-messages', { lastId }))
    .then(response => {
      if (response.data.length > 0) {
        const newMessages = response.data;

        newMessages.forEach(newMsg => {
          const existingIndex = localMessages.value.findIndex(msg => msg.id === newMsg.id);

          if (existingIndex >= 0) {
            localMessages.value[existingIndex] = newMsg;
          } else {
            localMessages.value.push(newMsg);
          }
        });

        if (newMessages.some(msg => msg.role === 'assistant' && msg.is_visible_to_user)) {
          isProcessing.value = false;
        }

        scrollToBottom();
      }
    })
    .catch(error => {
      console.error('Error fetching new messages:', error);
    });
}

function sendMessage() {
  if (!form.content.trim() || form.processing || isProcessing.value) return;

  isProcessing.value = true;
  mobileMenuOpen.value = false;

  form.post(route('chat.message'), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      if (messageInput.value) {
        messageInput.value.style.height = 'auto';
      }
      isProcessing.value = false;
      startFrequentRefresh();
      nextTick(() => {
        if (messageInput.value) {
          messageInput.value.focus();
        }
      });
    },
    onError: () => {
      isProcessing.value = false;
      nextTick(() => {
        if (messageInput.value) {
          messageInput.value.focus();
        }
      });
    }
  });
}

function confirmClearHistory() {
  if (confirm(t('chat_delete_all_confirm'))) {
    router.post(route('chat.clear'));
  }
}

function startFrequentRefresh() {
  if (refreshInterval) {
    clearInterval(refreshInterval);
  }

  refreshMessages();

  refreshInterval = setInterval(() => {
    refreshMessages();

    if (!isProcessing.value) {
      stopFrequentRefresh();
    }
  }, 3000);
}

function stopFrequentRefresh() {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }

  refreshInterval = setInterval(refreshMessages, 10000);
}

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

function updateModelSettings() {

  if (!isAdmin.value) {
    console.warn('Only admins can update model settings');
    return;
  }

  router.post(route('chat.model-settings'), {
    model_default: selectedModel.value,
    model_active: isSingleMode.value ? true : isModelActive.value
  }, {
    preserveScroll: true,
    onSuccess: () => {
      // Saved successfully
    },
    onError: (errors) => {
      console.error('Error updating model settings:', errors);
    }
  });
}

watch([selectedModel, isModelActive], () => {
  if (isAdmin.value) {
    updateModelSettings();
  }
});

async function exportChat() {
  if (!selectedExportFormat.value || isExporting.value) return;

  isExporting.value = true;

  try {
    const response = await axios.post(route('chat.export'), {
      format: selectedExportFormat.value,
      include_thinking: includeThinking.value
    }, {
      responseType: 'blob'
    });

    const contentDisposition = response.headers['content-disposition'];
    let filename = 'chat_export.txt';
    if (contentDisposition) {
      const filenameMatch = contentDisposition.match(/filename="([^"]+)"/);
      if (filenameMatch) {
        filename = filenameMatch[1];
      }
    }

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);

  } catch (error) {
    console.error('Export error:', error);
  } finally {
    isExporting.value = false;
  }
}

onMounted(() => {
  const savedTheme = localStorage.getItem('chat-theme');
  if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    isDark.value = true;
    document.documentElement.classList.add('dark');
  }

  localMessages.value = [...props.messages];
  scrollToBottom();
  refreshInterval = setInterval(refreshMessages, 10000);
});

watch(() => props.messages, (newMessages) => {
  localMessages.value = [...newMessages];
}, { deep: true });

watch(() => filteredMessages.value.length, scrollToBottom);

watch(() => props.currentModel, (newValue) => {
  selectedModel.value = newValue;
});

watch(() => props.modelActive, (newValue) => {
  isModelActive.value = newValue;
});

onBeforeUnmount(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }
});
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

/* Scrollbar styling */
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