<template>
  <header :class="[
    'shadow-lg border-b backdrop-blur-sm transition-colors duration-300',
    isDark
      ? 'bg-gray-800 bg-opacity-95 border-gray-700'
      : 'bg-white bg-opacity-95 border-gray-200'
  ]">
    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center">
        <!-- Header with icon -->
        <div class="flex items-center space-x-4">
          <div
            class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center">
            <span class="text-white font-bold text-lg">{{ page.props.app_name.charAt(0).toUpperCase() }}</span>
          </div>
          <div>
            <h1 :class="[
              'text-xl sm:text-2xl lg:text-3xl font-bold',
              isDark ? 'text-white' : 'text-gray-900'
            ]">{{ title }}</h1>
            <p v-if="isAdmin" :class="[
              'text-xs sm:text-sm mt-1',
              isDark ? 'text-indigo-400' : 'text-indigo-600'
            ]">{{ $t('admin_panel') }}</p>
          </div>
        </div>

        <!-- Theme toggle + Mobile menu button -->
        <div class="flex items-center space-x-3">
          <button @click="toggleTheme" :class="[
            'p-2 rounded-xl transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            isDark
              ? 'bg-gray-700 text-yellow-400 hover:bg-gray-600 focus:ring-offset-gray-800'
              : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
          ]">
            <svg v-if="isDark" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                clip-rule="evenodd"></path>
            </svg>
            <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
            </svg>
          </button>

          <button @click="mobileMenuOpen = !mobileMenuOpen" :class="[
            'sm:hidden p-2 rounded-xl transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            isDark
              ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
              : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
          ]">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
              stroke-linecap="round" stroke-linejoin="round">
              <path v-if="!mobileMenuOpen" d="M4 6h16M4 12h16M4 18h16" />
              <path v-else d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Navigation (desktop) -->
        <nav class="hidden sm:flex items-center space-x-6">
          <div :class="[
            'flex items-center space-x-2 px-3 py-2 rounded-xl',
            isDark ? 'bg-gray-700' : 'bg-gray-100'
          ]">
            <div
              class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center">
              <span class="text-white text-sm font-bold">{{ $page.props.auth.user.name.charAt(0).toUpperCase() }}</span>
            </div>
            <span :class="[
              'text-sm font-medium',
              isDark ? 'text-gray-200' : 'text-gray-700'
            ]">{{ $page.props.auth.user.name }}</span>
          </div>

          <div class="flex items-center space-x-1">
            <Link :href="route('chat.index')" :class="navLinkClass">
              <span>{{ $t('chat') }}</span>
            </Link>

            <Link :href="route('admin.agents.index')" :class="navLinkClass">
              <span>{{ $t('agents') }}</span>
            </Link>

            <Link :href="route('profile.show')" :class="navLinkClass">
              <span>{{ $t('profile') }}</span>
            </Link>

            <!-- Dropdown Menu -->
            <div v-if="isAdmin" class="relative" ref="dropdownRef">
              <button @click="dropdownOpen = !dropdownOpen" :class="navLinkClass">
                <span>{{ $t('admin') }}</span>
                <svg :class="[
                  'w-4 h-4 transition-transform duration-200',
                  dropdownOpen ? 'rotate-180' : ''
                ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
              </button>

              <Teleport to="body">
                <Transition enter-active-class="transition ease-out duration-200"
                  enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                  leave-active-class="transition ease-in duration-150"
                  leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                  <div v-if="dropdownOpen" :style="dropdownStyle" :class="[
                    'fixed w-56 rounded-xl shadow-xl border backdrop-blur-sm',
                    isDark
                      ? 'bg-gray-800 bg-opacity-95 border-gray-700 shadow-black/50'
                      : 'bg-white bg-opacity-95 border-gray-200 shadow-gray-500/30'
                  ]" style="z-index: 9999;">
                    <div class="py-2">

                      <!-- Preset-aware links -->
                      <Link :href="memoryLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('memory') }}</span>
                      </Link>

                      <Link :href="vectorMemoryLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('vm_vector_memory') }}</span>
                      </Link>

                      <Link :href="skillsLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('skills') }}</span>
                      </Link>

                      <Link :href="workspaceLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('workspace') }}</span>
                      </Link>

                      <Link :href="goalsLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('goals') }}</span>
                      </Link>

                      <Link :href="agentTasksLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('agent_tasks') }}</span>
                      </Link>

                      <Link :href="journalLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('journal') }}</span>
                      </Link>

                      <Link :href="personLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('person_memory') }}</span>
                      </Link>

                      <Link :href="knownSourcesLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('known_sources') }}</span>
                      </Link>

                      <Link :href="route('admin.presets.index')" @click="dropdownOpen = false"
                        :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('presets') }}</span>
                      </Link>

                      <Link :href="pluginsLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('plugins') }}</span>
                      </Link>

                      <Link :href="capabilitiesLink" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('capabilities') }}</span>
                      </Link>

                      <Link :href="route('admin.engines.index')" @click="dropdownOpen = false"
                        :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('engines') }}</span>
                      </Link>

                      <Link v-if="$page.props.sandboxEnabled" :href="route('admin.sandboxes.index')"
                        @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('hypervisor') }}</span>
                      </Link>

                      <div :class="['border-t my-2', isDark ? 'border-gray-700' : 'border-gray-200']"></div>

                      <Link :href="route('admin.users.index')" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('users') }}</span>
                      </Link>

                      <Link :href="route('admin.settings')" @click="dropdownOpen = false" :class="dropdownLinkClass">
                        <span class="mr-3"></span>
                        <span>{{ $t('settings') }}</span>
                      </Link>

                    </div>
                  </div>
                </Transition>
              </Teleport>
            </div>

            <Link :href="route('logout')" method="post" as="button" :class="[
              'inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
              isDark
                ? 'text-red-400 hover:text-red-300 hover:bg-red-900 hover:bg-opacity-20 focus:ring-red-500 focus:ring-offset-gray-800'
                : 'text-red-600 hover:text-red-800 hover:bg-red-50 focus:ring-red-500'
            ]">
              <span>{{ $t('logout') }}</span>
            </Link>
          </div>
        </nav>
      </div>

      <!-- Navigation (mobile) -->
      <Transition enter-active-class="transition ease-out duration-200" enter-from-class="transform opacity-0 scale-95"
        enter-to-class="transform opacity-100 scale-100" leave-active-class="transition ease-in duration-150"
        leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
        <div v-if="mobileMenuOpen" :class="[
          'mt-6 sm:hidden space-y-3 p-4 rounded-2xl border',
          isDark
            ? 'bg-gray-800 bg-opacity-50 border-gray-700'
            : 'bg-gray-50 bg-opacity-50 border-gray-200'
        ]">
          <div :class="['flex items-center space-x-3 p-3 rounded-xl', isDark ? 'bg-gray-700' : 'bg-white']">
            <div
              class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
              <span class="text-white font-bold">{{ $page.props.auth.user.name.charAt(0).toUpperCase() }}</span>
            </div>
            <div>
              <p :class="['font-medium', isDark ? 'text-white' : 'text-gray-900']">{{ $page.props.auth.user.name }}</p>
              <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">{{ isAdmin ? $t('admin') : $t('user')
                }}</p>
            </div>
          </div>

          <div class="space-y-2">
            <Link :href="route('chat.index')" :class="mobileLinkClass" @click="mobileMenuOpen = false">
              <span class="text-lg"></span><span>{{ $t('chat') }}</span>
            </Link>

            <Link :href="route('profile.show')" :class="mobileLinkClass" @click="mobileMenuOpen = false">
              <span class="text-lg"></span><span>{{ $t('profile') }}</span>
            </Link>

            <!-- Mobile Admin Section -->
            <div v-if="isAdmin" class="space-y-2">
              <button @click="mobileAdminOpen = !mobileAdminOpen" :class="[
                'flex items-center justify-between w-full p-3 rounded-xl text-sm font-medium transition-all',
                isDark
                  ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
                  : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
              ]">
                <div class="flex items-center space-x-3">
                  <span class="text-lg"></span><span>{{ $t('admin') }}</span>
                </div>
                <svg :class="['w-4 h-4 transition-transform duration-200', mobileAdminOpen ? 'rotate-180' : '']"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
              </button>

              <Transition enter-active-class="transition ease-out duration-200"
                enter-from-class="transform opacity-0 scale-95 -translate-y-2"
                enter-to-class="transform opacity-100 scale-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="transform opacity-100 scale-100 translate-y-0"
                leave-to-class="transform opacity-0 scale-95 -translate-y-2">
                <div v-if="mobileAdminOpen" class="ml-6 space-y-2">
                  <Link :href="route('admin.presets.index')" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('presets') }}</span>
                  </Link>

                  <!-- Preset-aware links (mobile) -->
                  <Link :href="memoryLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('memory') }}</span>
                  </Link>

                  <Link :href="vectorMemoryLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('vm_vector_memory') }}</span>
                  </Link>

                  <Link :href="skillsLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('skills') }}</span>
                  </Link>

                  <Link :href="workspaceLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('workspace') }}</span>
                  </Link>

                  <Link :href="goalsLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('goals') }}</span>
                  </Link>

                  <Link :href="agentTasksLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('agent_tasks') }}</span>
                  </Link>

                  <Link :href="journalLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('journal') }}</span>
                  </Link>

                  <Link :href="personLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('person_memory') }}</span>
                  </Link>

                  <Link :href="knownSourcesLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('known_sources') }}</span>
                  </Link>

                  <Link :href="pluginsLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('plugins') }}</span>
                  </Link>

                  <Link :href="capabilitiesLink" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('capabilities') }}</span>
                  </Link>

                  <Link :href="route('admin.engines.index')" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('engines') }}</span>
                  </Link>

                  <Link v-if="$page.props.sandboxEnabled" :href="route('admin.sandboxes.index')"
                    :class="mobileSubLinkClass" @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('hypervisor') }}</span>
                  </Link>

                  <Link :href="route('admin.users.index')" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('users') }}</span>
                  </Link>

                  <Link :href="route('admin.settings')" :class="mobileSubLinkClass"
                    @click="mobileMenuOpen = false; mobileAdminOpen = false">
                    <span class="text-lg"></span><span>{{ $t('settings') }}</span>
                  </Link>
                </div>
              </Transition>
            </div>

            <Link :href="route('logout')" method="post" as="button" :class="[
              'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
              isDark
                ? 'text-red-400 hover:text-red-300 hover:bg-red-900 hover:bg-opacity-20'
                : 'text-red-600 hover:text-red-800 hover:bg-red-50'
            ]" @click="mobileMenuOpen = false">
              <span class="text-lg"></span><span>{{ $t('logout') }}</span>
            </Link>
          </div>
        </div>
      </Transition>
    </div>
  </header>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useSelectedPreset } from '@/Composables/useSelectedPreset';

const page = usePage();
const { routeWithPreset, routeWithPresetParam } = useSelectedPreset();

const props = defineProps({
  title: { type: String, required: true },
  isAdmin: { type: Boolean, default: false },
  // Agent ID to carry to agent-tasks link (optional)
  currentAgentId: { type: Number, default: null },
});

const mobileMenuOpen = ref(false);
const mobileAdminOpen = ref(false);
const dropdownOpen = ref(false);
const dropdownRef = ref(null);
const isDark = ref(false);

// Preset-aware computed links — read saved preset from localStorage
const memoryLink = computed(() => routeWithPreset(route('admin.memory.index')));
const vectorMemoryLink = computed(() => routeWithPreset(route('admin.vector-memory.index')));
const skillsLink = computed(() => routeWithPreset(route('admin.skills.index')));
const workspaceLink = computed(() => routeWithPreset(route('admin.workspace.index')));
const goalsLink = computed(() => routeWithPreset(route('admin.goals.index')));
const personLink = computed(() => routeWithPreset(route('admin.person-memory.index')));
const journalLink = computed(() => routeWithPreset(route('admin.journal.index')));
const pluginsLink = computed(() => routeWithPresetParam('admin.plugins.index'));
const capabilitiesLink = computed(() => routeWithPresetParam('admin.capabilities.index'));
const knownSourcesLink = computed(() => routeWithPreset(route('admin.known-sources.index')));

const agentTasksLink = computed(() => {
  const base = route('admin.agent-tasks.index');
  return props.currentAgentId ? `${base}?agent_id=${props.currentAgentId}` : base;
});

// Shared link class helpers
const navLinkClass = computed(() => [
  'inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
  isDark.value
    ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700 focus:ring-indigo-500 focus:ring-offset-gray-800'
    : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 focus:ring-indigo-500',
]);

const dropdownLinkClass = computed(() => [
  'flex items-center px-4 py-3 text-sm transition-colors hover:bg-opacity-50',
  isDark.value ? 'text-gray-200 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100',
]);

const mobileLinkClass = computed(() => [
  'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
  isDark.value
    ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
    : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50',
]);

const mobileSubLinkClass = computed(() => [
  'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
  isDark.value
    ? 'text-gray-300 hover:text-indigo-300 hover:bg-gray-700'
    : 'text-gray-600 hover:text-indigo-800 hover:bg-indigo-50',
]);

const dropdownStyle = computed(() => {
  if (!dropdownRef.value || !dropdownOpen.value) return {};
  const rect = dropdownRef.value.getBoundingClientRect();
  return {
    top: `${rect.bottom + 8}px`,
    right: `${window.innerWidth - rect.right}px`,
  };
});

const toggleTheme = () => {
  isDark.value = !isDark.value;
  localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');
  document.documentElement.classList.toggle('dark', isDark.value);
  window.dispatchEvent(new CustomEvent('theme-changed', { detail: { isDark: isDark.value } }));
};

const handleClickOutside = (event) => {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    dropdownOpen.value = false;
  }
};

onMounted(() => {
  const savedTheme = localStorage.getItem('chat-theme');
  if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    isDark.value = true;
    document.documentElement.classList.add('dark');
  }

  window.addEventListener('theme-changed', (e) => { isDark.value = e.detail.isDark; });
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>

<style scoped>
.overflow-y-auto::-webkit-scrollbar {
  width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background-color: rgba(156, 163, 175, .5);
  border-radius: 9999px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background-color: rgba(156, 163, 175, .7);
}

.backdrop-blur-sm {
  backdrop-filter: blur(4px);
}

@supports not (backdrop-filter: blur(4px)) {
  .backdrop-blur-sm {
    background-color: rgba(255, 255, 255, .95);
  }

  .dark .backdrop-blur-sm {
    background-color: rgba(31, 41, 55, .95);
  }
}

div[style*="z-index: 9999"] {
  position: fixed !important;
  z-index: 9999 !important;
}
</style>