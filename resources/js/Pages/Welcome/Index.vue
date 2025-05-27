<template>
  <PageTitle :title="t('project_description')" />
  <div :class="[
    'min-h-screen relative overflow-hidden transition-colors duration-500',
    isDark ? 'bg-gray-900' : 'bg-gradient-to-br from-blue-50 via-white to-purple-50'
  ]">
    <!-- Animated Background -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <!-- Floating particles -->
      <div v-for="i in 20" :key="i" :class="[
        'absolute w-2 h-2 rounded-full opacity-30 animate-pulse',
        isDark ? 'bg-blue-400' : 'bg-blue-300'
      ]" :style="{
        left: Math.random() * 100 + '%',
        top: Math.random() * 100 + '%',
        animationDelay: Math.random() * 3 + 's',
        animationDuration: (Math.random() * 3 + 2) + 's'
      }"></div>

      <!-- Large background shapes -->
      <div :class="[
        'absolute -top-32 -right-32 w-96 h-96 rounded-full opacity-20 blur-3xl animate-pulse',
        isDark ? 'bg-gradient-to-br from-blue-500 to-purple-600' : 'bg-gradient-to-br from-blue-300 to-purple-400'
      ]" style="animation-duration: 4s;"></div>

      <div :class="[
        'absolute -bottom-32 -left-32 w-96 h-96 rounded-full opacity-20 blur-3xl animate-pulse',
        isDark ? 'bg-gradient-to-br from-emerald-500 to-blue-600' : 'bg-gradient-to-br from-emerald-300 to-blue-400'
      ]" style="animation-duration: 6s; animation-delay: 1s;"></div>

      <div :class="[
        'absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] rounded-full opacity-10 blur-3xl animate-pulse',
        isDark ? 'bg-gradient-to-br from-purple-500 to-pink-600' : 'bg-gradient-to-br from-purple-300 to-pink-400'
      ]" style="animation-duration: 8s; animation-delay: 2s;"></div>
    </div>

    <!-- Theme Toggle -->
    <button @click="toggleTheme" :class="[
      'fixed top-6 right-6 p-4 rounded-2xl transition-all duration-300 z-50',
      'hover:scale-110 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2',
      'backdrop-blur-sm border',
      isDark
        ? 'bg-gray-800 bg-opacity-90 border-gray-700 text-yellow-400 hover:bg-gray-700 focus:ring-yellow-500 focus:ring-offset-gray-900'
        : 'bg-white bg-opacity-90 border-gray-200 text-gray-600 hover:bg-gray-50 focus:ring-blue-500 shadow-lg'
    ]">
      <Transition enter-active-class="transition-all duration-300" enter-from-class="transform rotate-90 scale-0"
        enter-to-class="transform rotate-0 scale-100" leave-active-class="transition-all duration-300"
        leave-from-class="transform rotate-0 scale-100" leave-to-class="transform rotate-90 scale-0" mode="out-in">
        <svg v-if="isDark" key="sun" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd"
            d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
            clip-rule="evenodd"></path>
        </svg>
        <svg v-else key="moon" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
          <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
        </svg>
      </Transition>
    </button>

    <!-- Main Content -->
    <div class="relative z-10 min-h-screen flex flex-col justify-center items-center px-4 py-12">

      <!-- Hero Section -->
      <div class="text-center max-w-6xl mx-auto">

        <!-- Logo/Brand -->
        <div class="mb-8 flex justify-center">
          <div :class="[
            'relative group cursor-pointer transition-all duration-500',
            'hover:scale-110 active:scale-105'
          ]">
            <!-- Glow effect -->
            <div :class="[
              'absolute inset-0 rounded-3xl blur-xl opacity-75 group-hover:opacity-100 transition-opacity duration-500',
              isDark
                ? 'bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500'
                : 'bg-gradient-to-r from-blue-400 via-purple-400 to-pink-400'
            ]"></div>

            <!-- Logo container -->
            <div :class="[
              'relative w-24 h-24 sm:w-32 sm:h-32 rounded-3xl flex items-center justify-center',
              'backdrop-blur-sm border-2',
              isDark
                ? 'bg-gray-800 bg-opacity-90 border-gray-600'
                : 'bg-white bg-opacity-90 border-gray-200'
            ]">
              <div
                class="text-4xl sm:text-5xl font-bold bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
                {{ page.props.app_name.charAt(0).toUpperCase() }}
              </div>
            </div>
          </div>
        </div>

        <!-- Main Title with Animation -->
        <div class="mb-6">
          <h1 :class="[
            'text-6xl sm:text-7xl lg:text-8xl font-black mb-4 leading-tight',
            'bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 bg-clip-text text-transparent',
            'animate-pulse'
          ]" style="animation-duration: 3s;">
            {{ page.props.app_name }}
          </h1>

          <!-- Animated underline -->
          <div class="flex justify-center">
            <div :class="[
              'h-2 rounded-full animate-pulse',
              'bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500',
              'w-32 sm:w-48 lg:w-64'
            ]" style="animation-duration: 2s; animation-delay: 0.5s;"></div>
          </div>
        </div>

        <!-- Subtitle -->
        <p :class="[
          'text-xl sm:text-2xl lg:text-3xl mb-12 max-w-4xl mx-auto leading-relaxed',
          isDark ? 'text-gray-300' : 'text-gray-700'
        ]">
          <span class="font-semibold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
            {{ t('project_description') }}
          </span>
        </p>

        <!-- Feature Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-12 max-w-4xl mx-auto">
          <div v-for="(feature, index) in features" :key="index" :class="[
            'p-6 rounded-2xl backdrop-blur-sm border transition-all duration-300',
            'hover:scale-105 hover:shadow-xl cursor-pointer',
            isDark
              ? 'bg-gray-800 bg-opacity-60 border-gray-700 hover:bg-opacity-80'
              : 'bg-white bg-opacity-60 border-gray-200 hover:bg-opacity-80'
          ]" :style="{ animationDelay: (index * 0.2) + 's' }">
            <div class="text-4xl mb-4">{{ feature.icon }}</div>
            <h3 :class="[
              'text-lg font-bold mb-2',
              isDark ? 'text-white' : 'text-gray-900'
            ]">
              {{ feature.title }}
            </h3>
            <p :class="[
              'text-sm',
              isDark ? 'text-gray-400' : 'text-gray-600'
            ]">
              {{ feature.description }}
            </p>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-6 justify-center items-center">

          <!-- Authenticated User Buttons -->
          <div v-if="$page.props.auth.user" class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-4">
            <!-- Chat Button -->
            <Link :href="route('chat.index')" :class="[
              'group relative px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300',
              'transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2',
              'text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700',
              'shadow-lg hover:shadow-xl',
              isDark ? 'focus:ring-blue-500 focus:ring-offset-gray-900' : 'focus:ring-blue-500'
            ]">
            <!-- Button glow effect -->
            <div
              class="absolute inset-0 rounded-2xl bg-gradient-to-r from-blue-600 to-purple-600 opacity-0 group-hover:opacity-50 blur transition-opacity duration-300">
            </div>
            <span class="relative flex items-center">
              {{ t('chat') }}
              <svg class="w-5 h-5 ml-2 group-hover:translate-x-1 transition-transform duration-200" fill="none"
                stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6">
                </path>
              </svg>
            </span>
            </Link>

            <!-- Logout Button -->
            <Link :href="route('logout')" method="post" as="button" :class="[
              'group px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300',
              'transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2',
              'border-2',
              isDark
                ? 'border-gray-600 text-gray-300 hover:bg-gray-800 hover:border-gray-500 focus:ring-gray-500 focus:ring-offset-gray-900'
                : 'border-gray-300 text-gray-700 hover:bg-gray-50 hover:border-gray-400 focus:ring-gray-500 bg-white'
            ]">
            <span class="flex items-center">
              {{ t('logout') }}
            </span>
            </Link>
          </div>

          <!-- Guest User Buttons -->
          <div v-else class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-4">
            <!-- Login Button -->
            <Link :href="route('login')" :class="[
              'group relative px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300',
              'transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2',
              'text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700',
              'shadow-lg hover:shadow-xl',
              isDark ? 'focus:ring-blue-500 focus:ring-offset-gray-900' : 'focus:ring-blue-500'
            ]">
            <!-- Button glow effect -->
            <div
              class="absolute inset-0 rounded-2xl bg-gradient-to-r from-blue-600 to-purple-600 opacity-0 group-hover:opacity-50 blur transition-opacity duration-300">
            </div>
            <span class="flex items-center">{{ t('login') }}</span>
            </Link>

            <!-- Register Button -->
            <Link :href="route('register')" :class="[
              'group px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300',
              'transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2',
              'border-2',
              isDark
                ? 'border-gray-600 text-gray-300 hover:bg-gray-800 hover:border-gray-500 focus:ring-gray-500 focus:ring-offset-gray-900'
                : 'border-gray-300 text-gray-700 hover:bg-gray-50 hover:border-gray-400 focus:ring-gray-500 bg-white'
            ]">
            <span class="flex items-center">{{ t('register') }}</span>
            </Link>
          </div>
        </div>

        <!-- Stats Section -->
        <div v-if="$page.props.auth.user" class="mt-16 grid grid-cols-1 sm:grid-cols-3 gap-8 max-w-3xl mx-auto">
          <div v-for="(stat, index) in stats" :key="index" :class="[
            'text-center p-6 rounded-2xl backdrop-blur-sm border transition-all duration-300',
            'hover:scale-105',
            isDark
              ? 'bg-gray-800 bg-opacity-60 border-gray-700'
              : 'bg-white bg-opacity-60 border-gray-200'
          ]">
            <div :class="[
              'text-3xl font-bold mb-2',
              'bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent'
            ]">
              {{ stat.value }}
            </div>
            <div :class="[
              'text-sm font-medium',
              isDark ? 'text-gray-400' : 'text-gray-600'
            ]">
              {{ stat.label }}
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="mt-20 text-center">
        <p :class="[
          'text-sm flex items-center justify-center space-x-2',
          isDark ? 'text-gray-500' : 'text-gray-600'
        ]">
          <span class="text-red-500 animate-pulse">❤️</span>
          <span>{{ t('welcome_heart') }} {{ t('welcome_and') }} {{ t('welcome_rocket') }}</span>
          <span class="animate-bounce">🚀</span>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, onMounted } from 'vue';
import PageTitle from '@/Components/PageTitle.vue';

const page = usePage();
const { t } = useI18n();

// Theme management
const isDark = ref(false);

// Feature cards data
const features = [
  {
    icon: '🤖',
    title: t('welcome_feature1_title'),
    description: t('welcome_feature1_description')
  },
  {
    icon: '⚡',
    title: t('welcome_feature2_title'),
    description: t('welcome_feature2_description')
  },
  {
    icon: '🛡️',
    title: t('welcome_feature3_title'),
    description: t('welcome_feature3_description')
  }
];

// Stats data (for authenticated users)
const stats = [
  { value: page.props.current_model, label: t('welcome_current_model') },
  { value: page.props.users_count, label: t('welcome_users') },
  { value: page.props.messages_count, label: t('welcome_messages') }
];

const toggleTheme = () => {
  isDark.value = !isDark.value;
  localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');
  document.documentElement.classList.toggle('dark', isDark.value);

  // Dispatch event for other components
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
});
</script>