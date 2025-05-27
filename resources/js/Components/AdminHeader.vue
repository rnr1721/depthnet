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
          <!-- Theme toggle -->
          <button @click="toggleTheme" :class="[
            'p-2 rounded-xl transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
            isDark
              ? 'bg-gray-700 text-yellow-400 hover:bg-gray-600 focus:ring-offset-gray-800'
              : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
          ]" :title="isDark ? 'Переключить на светлую тему' : 'Переключить на темную тему'">
            <svg v-if="isDark" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                clip-rule="evenodd"></path>
            </svg>
            <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
            </svg>
          </button>

          <!-- Mobile menu button -->
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
            <Link :href="route('chat.index')" :class="[
              'inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700 focus:ring-indigo-500 focus:ring-offset-gray-800'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 focus:ring-indigo-500'
            ]">
            <span>{{ $t('chat') }}</span>
            </Link>

            <Link :href="route('profile.show')" :class="[
              'inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700 focus:ring-indigo-500 focus:ring-offset-gray-800'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 focus:ring-indigo-500'
            ]">
            <span>{{ $t('profile') }}</span>
            </Link>

            <Link v-if="isAdmin" :href="route('admin.settings')" :class="[
              'inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700 focus:ring-indigo-500 focus:ring-offset-gray-800'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 focus:ring-indigo-500'
            ]">
            <span>{{ $t('settings') }}</span>
            </Link>

            <Link v-if="isAdmin" :href="route('admin.users.index')" :class="[
              'inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-medium transition-all transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700 focus:ring-indigo-500 focus:ring-offset-gray-800'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 focus:ring-indigo-500'
            ]">
            <span>{{ $t('users') }}</span>
            </Link>

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
          <!-- User info mobile -->
          <div :class="[
            'flex items-center space-x-3 p-3 rounded-xl',
            isDark ? 'bg-gray-700' : 'bg-white'
          ]">
            <div
              class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
              <span class="text-white font-bold">{{ $page.props.auth.user.name.charAt(0).toUpperCase() }}</span>
            </div>
            <div>
              <p :class="[
                'font-medium',
                isDark ? 'text-white' : 'text-gray-900'
              ]">{{ $page.props.auth.user.name }}</p>
              <p :class="[
                'text-sm',
                isDark ? 'text-gray-400' : 'text-gray-500'
              ]">{{ isAdmin ? t('admin') : t('user') }}</p>
            </div>
          </div>

          <!-- Navigation links mobile -->
          <div class="space-y-2">
            <Link :href="route('chat.index')" :class="[
              'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
            ]" @click="mobileMenuOpen = false">
            <span class="text-lg">💬</span>
            <span>{{ $t('chat') }}</span>
            </Link>

            <Link :href="route('profile.show')" :class="[
              'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
            ]" @click="mobileMenuOpen = false">
            <span class="text-lg">👤</span>
            <span>{{ $t('profile') }}</span>
            </Link>

            <Link v-if="isAdmin" :href="route('admin.settings')" :class="[
              'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
              isDark
                ? 'text-indigo-400 hover:text-indigo-300 hover:bg-gray-700'
                : 'text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50'
            ]" @click="mobileMenuOpen = false">
            <span class="text-lg">⚙️</span>
            <span>{{ $t('settings') }}</span>
            </Link>

            <Link :href="route('logout')" method="post" as="button" :class="[
              'flex items-center space-x-3 w-full p-3 rounded-xl text-sm font-medium transition-all',
              isDark
                ? 'text-red-400 hover:text-red-300 hover:bg-red-900 hover:bg-opacity-20'
                : 'text-red-600 hover:text-red-800 hover:bg-red-50'
            ]" @click="mobileMenuOpen = false">
            <span class="text-lg">🚪</span>
            <span>{{ $t('logout') }}</span>
            </Link>
          </div>
        </div>
      </Transition>
    </div>
  </header>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage();

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  isAdmin: {
    type: Boolean,
    default: false
  }
})

const mobileMenuOpen = ref(false)
const isDark = ref(false)

const toggleTheme = () => {
  isDark.value = !isDark.value;
  localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');

  // Update document class for global theme
  if (isDark.value) {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }

  // Dispatch custom event for other components to listen
  window.dispatchEvent(new CustomEvent('theme-changed', {
    detail: { isDark: isDark.value }
  }));
};

onMounted(() => {
  // Load theme from localStorage
  const savedTheme = localStorage.getItem('chat-theme');
  if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    isDark.value = true;
    document.documentElement.classList.add('dark');
  }

  // Listen for theme changes from other components
  window.addEventListener('theme-changed', (event) => {
    isDark.value = event.detail.isDark;
  });
});
</script>

<style scoped>
/* Custom scrollbar for mobile menu */
.overflow-y-auto::-webkit-scrollbar {
  width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background-color: rgba(156, 163, 175, 0.5);
  border-radius: 9999px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background-color: rgba(156, 163, 175, 0.7);
}

/* Backdrop blur fallback */
.backdrop-blur-sm {
  backdrop-filter: blur(4px);
}

@supports not (backdrop-filter: blur(4px)) {
  .backdrop-blur-sm {
    background-color: rgba(255, 255, 255, 0.95);
  }

  .dark .backdrop-blur-sm {
    background-color: rgba(31, 41, 55, 0.95);
  }
}

/* Focus styles for better accessibility */
.focus\:outline-none:focus {
  outline: 2px solid transparent;
  outline-offset: 2px;
}

/* Transition improvements */
.transition-all {
  transition-property: all;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 200ms;
}

/* Hover effects */
.hover\:scale-105:hover {
  transform: scale(1.05);
}

/* Button active states */
.active\:scale-95:active {
  transform: scale(0.95);
}
</style>