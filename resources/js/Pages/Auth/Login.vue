<template>
    <PageTitle :title="t('login')" />
    <div :class="[
        'min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <!-- Background decoration -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div :class="[
                'absolute top-1/4 -left-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-blue-500' : 'bg-blue-300'
            ]"></div>
            <div :class="[
                'absolute bottom-1/4 -right-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-purple-500' : 'bg-purple-300'
            ]" style="animation-delay: 1s;"></div>
            <div :class="[
                'absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-96 h-96 rounded-full opacity-10 blur-3xl animate-pulse',
                isDark ? 'bg-emerald-500' : 'bg-emerald-300'
            ]" style="animation-delay: 2s;"></div>
        </div>

        <!-- Theme Toggle -->
        <button @click="toggleTheme" :class="[
            'fixed top-6 right-6 p-3 rounded-xl transition-all duration-300 z-50',
            'hover:scale-110 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2',
            isDark
                ? 'bg-gray-800 text-yellow-400 hover:bg-gray-700 focus:ring-yellow-500 focus:ring-offset-gray-900'
                : 'bg-white text-gray-600 hover:bg-gray-50 focus:ring-blue-500 shadow-lg'
        ]">
            <svg v-if="isDark" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                    clip-rule="evenodd"></path>
            </svg>
            <svg v-else class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
            </svg>
        </button>

        <!-- Main Login Card -->
        <div class="relative max-w-md w-full">

            <!-- Back Button -->
            <Link :href="route('home')" :class="[
                'mb-4 flex items-center text-sm font-medium transition-colors duration-200 hover:underline',
                isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-500'
            ]">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            {{ t('to_mainpage') }}
            </Link>

            <!-- Login Form Card -->
            <div :class="[
                'backdrop-blur-sm border shadow-2xl rounded-3xl overflow-hidden transition-all duration-300',
                'transform hover:scale-[1.02] hover:shadow-3xl',
                isDark
                    ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                    : 'bg-white bg-opacity-90 border-gray-200'
            ]">
                <!-- Header -->
                <div :class="[
                    'px-8 py-10 text-center border-b',
                    isDark ? 'border-gray-700' : 'border-gray-200'
                ]">
                    <!-- Logo/Icon -->
                    <div class="flex justify-center mb-6">
                        <div
                            class="w-20 h-20 bg-gradient-to-br from-blue-500 via-purple-500 to-pink-500 rounded-3xl flex items-center justify-center shadow-lg">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                </path>
                            </svg>
                        </div>
                    </div>

                    <!-- Title -->
                    <h2 :class="[
                        'text-3xl font-bold mb-2',
                        isDark ? 'text-white' : 'text-gray-900'
                    ]">
                        {{ t('login') }}
                    </h2>

                    <!-- Subtitle -->
                    <p :class="[
                        'text-sm',
                        isDark ? 'text-gray-400' : 'text-gray-600'
                    ]">
                        {{ t('login_welcome') }}
                    </p>

                    <!-- Register Link -->
                    <p :class="[
                        'mt-4 text-sm',
                        isDark ? 'text-gray-400' : 'text-gray-600'
                    ]">
                        {{ t('or') }}
                        <Link :href="route('register')" :class="[
                            'font-semibold transition-colors duration-200 hover:underline ml-1',
                            isDark ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-500'
                        ]">
                        {{ t('register_if_you_dont_have_account') }}
                        </Link>
                    </p>
                </div>

                <!-- Form -->
                <div class="p-8">
                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Email Field -->
                        <div class="space-y-2">
                            <div class="flex items-center space-x-3 mb-3">
                                <label for="email" :class="[
                                    'text-lg font-semibold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">
                                    {{ t('login_email') }}
                                </label>
                            </div>

                            <div class="relative">
                                <input id="email" v-model="form.email" type="email" autocomplete="email" required
                                    :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-blue-500 transition-all duration-200',
                                        'px-4 py-4 text-sm',
                                        form.errors.email
                                            ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                            : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                    ]" :placeholder="t('login_email_ph')">

                                <!-- Email validation icon -->
                                <div v-if="form.email && isValidEmail" class="absolute right-3 top-4">
                                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                </div>

                                <div v-if="form.errors.email" class="absolute right-3 top-4">
                                    <svg class="w-5 h-5" :class="isDark ? 'text-red-400' : 'text-red-500'"
                                        fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                            </div>

                            <div v-if="form.errors.email" :class="[
                                'text-sm mt-1 flex items-center space-x-2',
                                isDark ? 'text-red-400' : 'text-red-600'
                            ]">
                                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                        clip-rule="evenodd"></path>
                                </svg>
                                <span>{{ form.errors.email }}</span>
                            </div>
                        </div>

                        <!-- Password Field -->
                        <div class="space-y-2">
                            <div class="flex items-center space-x-3 mb-3">
                                <label for="password" :class="[
                                    'text-lg font-semibold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">
                                    {{ t('password') }}
                                </label>
                            </div>

                            <div class="relative">
                                <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'"
                                    autocomplete="current-password" required :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-purple-500 transition-all duration-200',
                                        'px-4 py-4 pr-12 text-sm',
                                        form.errors.password
                                            ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                            : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                    ]" :placeholder="t('password')">

                                <!-- Show/Hide Password Button -->
                                <button type="button" @click="showPassword = !showPassword"
                                    class="absolute right-3 top-4 focus:outline-none"
                                    :class="isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-500 hover:text-gray-700'">
                                    <svg v-if="showPassword" class="w-5 h-5" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21">
                                        </path>
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                        </path>
                                    </svg>
                                </button>
                            </div>

                            <div v-if="form.errors.password" :class="[
                                'text-sm mt-1 flex items-center space-x-2',
                                isDark ? 'text-red-400' : 'text-red-600'
                            ]">
                                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                        clip-rule="evenodd"></path>
                                </svg>
                                <span>{{ form.errors.password }}</span>
                            </div>
                        </div>

                        <!-- Remember Me & Forgot Password -->
                        <div class="flex items-center justify-between">
                            <label class="flex items-center">
                                <input type="checkbox" v-model="rememberMe" :class="[
                                    'rounded border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500',
                                    isDark ? 'bg-gray-700 border-gray-600 text-blue-600' : 'text-blue-600'
                                ]">
                                <span :class="[
                                    'ml-2 text-sm',
                                    isDark ? 'text-gray-300' : 'text-gray-700'
                                ]">
                                    {{ t('login_remember_me') }}
                                </span>
                            </label>

                            <Link :href="route('password.request')" :class="[
                                'text-sm font-medium transition-colors duration-200 hover:underline',
                                isDark ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-500'
                            ]">
                            {{ t('login_forgot_password') }}
                            </Link>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" :disabled="form.processing" :class="[
                            'w-full py-4 px-6 rounded-xl font-semibold text-white transition-all duration-200 transform',
                            'focus:outline-none focus:ring-2 focus:ring-offset-2',
                            'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                            'enabled:hover:scale-105 enabled:active:scale-95',
                            isDark
                                ? 'bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 focus:ring-blue-500 focus:ring-offset-gray-800'
                                : 'bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 focus:ring-blue-500 shadow-lg'
                        ]">
                            <span v-if="form.processing" class="flex items-center justify-center">
                                <svg class="w-5 h-5 mr-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                {{ t('login_begin') }}
                            </span>
                            <span v-else class="flex items-center justify-center">
                                {{ t('login_login') }}
                            </span>
                        </button>
                    </form>
                </div>

                <!-- Social Login -->
                <!--
                <div :class="[
                    'px-8 py-6 border-t',
                    isDark ? 'border-gray-700 bg-gray-800 bg-opacity-50' : 'border-gray-200 bg-gray-50'
                ]">
                    <div class="text-center">
                        <p :class="[
                            'text-sm mb-4',
                            isDark ? 'text-gray-400' : 'text-gray-600'
                        ]">
                            {{ t('login_login_via_sn') }}
                        </p>

                        <div class="flex space-x-3 justify-center">
                            <button :class="[
                                'p-3 rounded-xl transition-all duration-200 hover:scale-110 active:scale-95',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                isDark
                                    ? 'bg-gray-700 hover:bg-gray-600 focus:ring-red-500 focus:ring-offset-gray-800'
                                    : 'bg-white hover:bg-gray-50 focus:ring-red-500 shadow-md'
                            ]">
                                <svg class="w-5 h-5 text-red-500" viewBox="0 0 24 24">
                                    <path fill="currentColor"
                                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                    <path fill="currentColor"
                                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                    <path fill="currentColor"
                                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                    <path fill="currentColor"
                                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                                </svg>
                            </button>

                            <button :class="[
                                'p-3 rounded-xl transition-all duration-200 hover:scale-110 active:scale-95',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                isDark
                                    ? 'bg-gray-700 hover:bg-gray-600 focus:ring-gray-500 focus:ring-offset-gray-800'
                                    : 'bg-white hover:bg-gray-50 focus:ring-gray-500 shadow-md'
                            ]">
                                <svg class="w-5 h-5" :class="isDark ? 'text-white' : 'text-gray-900'"
                                    fill="currentColor" viewBox="0 0 24 24">
                                    <path
                                        d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
                -->
            </div>

            <!-- Footer Info -->
            <div class="mt-8 text-center">
                <p :class="[
                    'text-sm',
                    isDark ? 'text-gray-500' : 'text-gray-600'
                ]">
                    {{ t('login_footer_text') }} 🔒
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, computed, onMounted } from 'vue';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();

const isDark = ref(false);
const showPassword = ref(false);

const rememberMe = computed({
    get: () => form.remember,
    set: (value) => form.remember = value
});

const form = useForm({
    email: '',
    password: '',
    remember: false
});

const isValidEmail = computed(() => {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(form.email);
});

const submit = () => {
    form.post(route('login'));
};

const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', isDark.value);

    window.dispatchEvent(new CustomEvent('theme-changed', {
        detail: { isDark: isDark.value }
    }));
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
});
</script>