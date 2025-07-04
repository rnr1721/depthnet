<template>
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
            <span class="text-white font-bold text-sm">{{ appName.charAt(0).toUpperCase() }}</span>
          </div>
          <a :href="route('home')" :class="[
            'font-bold text-xl',
            isDark ? 'text-white' : 'text-gray-900'
          ]">
            {{ appName }}
          </a>

          <!-- Theme toggle button (compact) -->
          <button @click="$emit('toggleTheme')" :class="[
            'p-1.5 rounded-lg transition-all hover:scale-110 focus:outline-none',
            isDark
              ? 'text-yellow-400 hover:bg-gray-700 hover:text-yellow-300'
              : 'text-gray-600 hover:bg-gray-100 hover:text-yellow-600'
          ]" :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'">
            <!-- Sun icon for dark mode -->
            <svg v-if="isDark" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                clip-rule="evenodd" />
            </svg>
            <!-- Moon icon for light mode -->
            <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
            </svg>
          </button>
        </div>

        <button @click="$emit('closeMobileMenu')"
          class="lg:hidden p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- User info -->
      <div class="mt-4 flex flex-wrap gap-2">
        <Link :href="route('profile.show')" :class="linkClass">
        {{ user.name }}
        </Link>
        <template v-if="isAdmin">
          <Link :href="route('admin.settings')" :class="linkClass">
          {{ t('chat_settings') }}
          </Link>
          <Link :href="route('admin.presets.index')" :class="linkClass">
          {{ t('chat_presets') }}
          </Link>
          <Link :href="route('admin.plugins.index')" :class="linkClass">
          {{ t('plugins') }}
          </Link>
          <Link :href="route('admin.memory.index')" :class="linkClass">
          {{ t('memory') }}
          </Link>
          <Link :href="route('admin.vector-memory.index')" :class="linkClass">
          {{ t('vm_vector_memory') }}
          </Link>
          <Link :href="route('admin.users.index')" :class="linkClass">
          {{ t('chat_users') }}
          </Link>
          <Link v-if="$page.props.sandboxEnabled" :href="route('admin.sandboxes.index')" :class="linkClass">
          {{ t('hypervisor') }}
          </Link>
        </template>
      </div>
    </div>

    <!-- Controls -->
    <div class="flex-1 overflow-y-auto p-4 space-y-4">
      <!-- Clear history -->
      <button v-if="isAdmin" @click="$emit('clearHistory')" :class="[
        'w-full px-4 py-3 rounded-xl font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2',
        isDark
          ? 'bg-red-600 hover:bg-red-700 text-white focus:ring-offset-gray-800'
          : 'bg-red-600 hover:bg-red-700 text-white'
      ]">
        {{ t('chat_clear_history') }}
      </button>

      <!-- Edit current preset -->
      <button v-if="isAdmin && currentPreset" @click="$emit('editPreset')" :class="[
        'w-full px-4 py-3 rounded-xl font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
        'flex items-center justify-center space-x-2',
        isDark
          ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
          : 'bg-indigo-600 hover:bg-indigo-700 text-white'
      ]">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
          </path>
        </svg>
        <span>{{ t('chat_edit_current_preset') || 'Edit Current Preset' }}</span>
      </button>

      <!-- Current preset info -->
      <div v-if="currentPreset" :class="[
        'p-3 rounded-xl border',
        isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
      ]">
        <div class="flex items-center space-x-2 mb-2">
          <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
          <span :class="[
            'font-medium text-sm',
            isDark ? 'text-gray-200' : 'text-gray-700'
          ]">
            {{ t('chat_current_preset') || 'Current Preset' }}
          </span>
        </div>
        <h4 :class="[
          'font-semibold mb-1',
          isDark ? 'text-white' : 'text-gray-900'
        ]">
          {{ currentPreset.name }}
        </h4>
        <div class="text-xs space-y-1">
          <div :class="isDark ? 'text-gray-400' : 'text-gray-600'">
            {{ t('chat_current_model') || 'Model' }}: {{ currentPreset.model || 'N/A' }}
          </div>
          <div :class="isDark ? 'text-gray-400' : 'text-gray-600'">
            Engine: {{ currentPreset.engine_display_name || currentPreset.engine_name }}
          </div>
        </div>
      </div>

      <!-- Model active toggle -->
      <ModelActiveToggle v-if="isAdmin" :isChatActive="isChatActive" :isDark="isDark"
        @update:isChatActive="$emit('update:isChatActive', $event)" />

      <!-- Show thinking toggle -->
      <ShowThinkingToggle :showThinking="showThinking" :isDark="isDark"
        @update:showThinking="$emit('update:showThinking', $event)" />

      <!-- Chat export -->
      <ChatExport v-if="isAdmin" :selectedExportFormat="selectedExportFormat" :includeThinking="includeThinking"
        :exportFormats="exportFormats" :isExporting="isExporting" :isDark="isDark"
        @update:selectedExportFormat="$emit('update:selectedExportFormat', $event)"
        @update:includeThinking="$emit('update:includeThinking', $event)" @export="$emit('exportChat')" />
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
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ModelActiveToggle from './ModelActiveToggle.vue';
import ShowThinkingToggle from './ShowThinkingToggle.vue';
import ChatExport from './ChatExport.vue';

const { t } = useI18n();

const props = defineProps({
  mobileMenuOpen: Boolean,
  isDark: Boolean,
  appName: String,
  user: Object,
  isAdmin: Boolean,
  isChatActive: Boolean,
  showThinking: Boolean,
  selectedExportFormat: String,
  includeThinking: Boolean,
  exportFormats: Array,
  isExporting: Boolean,
  currentPreset: Object
});

defineEmits([
  'closeMobileMenu',
  'clearHistory',
  'editPreset',
  'update:isChatActive',
  'toggleTheme',
  'update:showThinking',
  'update:selectedExportFormat',
  'update:includeThinking',
  'exportChat'
]);

const linkClass = computed(() => [
  'inline-block text-sm px-3 py-2 rounded-md transition-colors',
  props.isDark
    ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
    : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
]);
</script>
