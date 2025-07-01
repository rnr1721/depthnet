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

      <!-- Preset selection -->
      <PresetSelector v-if="isAdmin" :selectedPresetId="selectedPresetId" :availablePresets="availablePresets"
        :currentPreset="currentPreset" :isDark="isDark"
        @update:selectedPresetId="$emit('update:selectedPresetId', $event)" @editPreset="$emit('editPreset')" />

      <!-- Model active toggle -->
      <ModelActiveToggle v-if="isAdmin" :isChatActive="isChatActive" :isDark="isDark"
        @update:isChatActive="$emit('update:isChatActive', $event)" />

      <!-- Theme toggle -->
      <ThemeToggle :isDark="isDark" @toggle="$emit('toggleTheme')" />

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
import PresetSelector from './PresetSelector.vue';
import ModelActiveToggle from './ModelActiveToggle.vue';
import ThemeToggle from './ThemeToggle.vue';
import ShowThinkingToggle from './ShowThinkingToggle.vue';
import ChatExport from './ChatExport.vue';

const { t } = useI18n();

const props = defineProps({
  mobileMenuOpen: Boolean,
  isDark: Boolean,
  appName: String,
  user: Object,
  isAdmin: Boolean,
  selectedPresetId: Number,
  availablePresets: Array,
  currentPreset: Object,
  isChatActive: Boolean,
  showThinking: Boolean,
  selectedExportFormat: String,
  includeThinking: Boolean,
  exportFormats: Array,
  isExporting: Boolean
});

defineEmits([
  'closeMobileMenu',
  'clearHistory',
  'update:selectedPresetId',
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
