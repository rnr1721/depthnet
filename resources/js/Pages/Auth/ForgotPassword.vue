<template>
    <PageTitle :title="t('chat')" />
    <div :class="[
        'min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <!-- Background decoration -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div :class="[
                'absolute top-1/4 -left-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-orange-500' : 'bg-orange-300'
            ]"></div>
            <div :class="[
                'absolute bottom-1/4 -right-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-pink-500' : 'bg-pink-300'
            ]" style="animation-delay: 1s;"></div>
            <div :class="[
                'absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-96 h-96 rounded-full opacity-10 blur-3xl animate-pulse',
                isDark ? 'bg-cyan-500' : 'bg-cyan-300'
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

        <!-- Main Card -->
        <div class="relative max-w-md w-full">
            <!-- Back Button -->
            <Link :href="route('login')" :class="[
                'mb-4 flex items-center text-sm font-medium transition-colors duration-200 hover:underline',
                isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-500'
            ]">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            {{ t('to_login') }}
            </Link>

            <!-- Form Card -->
            <div :class="[
                'backdrop-blur-sm border shadow-2xl rounded-3xl overflow-hidden transition-all duration-300',
                'transform hover:scale-[1.02] hover:shadow-3xl',
                isDark
                    ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                    : 'bg-white bg-opacity-90 border-gray-200'
            ]">
                <!-- Success State -->
                <div v-if="emailSent" class="p-8 text-center">
                    <!-- Success Icon -->
                    <div class="flex justify-center mb-6">
                        <div
                            class="w-20 h-20 bg-gradient-to-br from-green-500 to-emerald-500 rounded-3xl flex items-center justify-center shadow-lg">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                    </div>

                    <h2 :class="['text-2xl font-bold mb-4', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('fp_mail_sent') }}! 📧
                    </h2>

                    <p :class="['text-sm mb-6 leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-600']">
                        {{ t('fp_instruct_hints') }}:
                        <span :class="['font-semibold block mt-2', isDark ? 'text-blue-400' : 'text-blue-600']">
                            {{ userEmail }}
                        </span>
                    </p>

                    <div :class="[
                        'p-4 rounded-xl mb-6',
                        isDark ? 'bg-blue-900 bg-opacity-30 border border-blue-700' : 'bg-blue-50 border border-blue-200'
                    ]">
                        <p :class="['text-sm', isDark ? 'text-blue-300' : 'text-blue-700']">
                            💡 <strong>{{ t('fp_tip') }}:</strong> {{ t('fp_tip_1') }}.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <button @click="resendEmail" :disabled="resendCooldown > 0" :class="[
                            'w-full py-3 px-6 rounded-xl font-semibold transition-all duration-200 transform',
                            'focus:outline-none focus:ring-2 focus:ring-offset-2',
                            'disabled:opacity-50 disabled:cursor-not-allowed',
                            'enabled:hover:scale-105 enabled:active:scale-95',
                            isDark
                                ? 'bg-gray-700 text-white hover:bg-gray-600 focus:ring-gray-500 focus:ring-offset-gray-800'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-500'
                        ]">
                            {{ resendCooldown > 0 ? `Resending via ${resendCooldown}с` : 'Send again'
                            }}
                        </button>

                        <Link :href="route('login')" :class="[
                            'block w-full py-3 px-6 rounded-xl font-semibold text-center transition-all duration-200 transform',
                            'focus:outline-none focus:ring-2 focus:ring-offset-2',
                            'hover:scale-105 active:scale-95',
                            isDark
                                ? 'bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white focus:ring-blue-500 focus:ring-offset-gray-800'
                                : 'bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white focus:ring-blue-500 shadow-lg'
                        ]">
                        {{ t('fp_return_to_login') }}
                        </Link>
                    </div>
                </div>

                <!-- Form State -->
                <div v-else>
                    <!-- Header -->
                    <div :class="[
                        'px-8 py-10 text-center border-b',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <!-- Logo/Icon -->
                        <div class="flex justify-center mb-6">
                            <div
                                class="w-20 h-20 bg-gradient-to-br from-orange-500 via-pink-500 to-red-500 rounded-3xl flex items-center justify-center shadow-lg">
                                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
                                    </path>
                                </svg>
                            </div>
                        </div>

                        <!-- Title -->
                        <h2 :class="['text-3xl font-bold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('fp_restore_password') }}
                        </h2>

                        <!-- Subtitle -->
                        <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('fp_instruct_hints2') }}
                        </p>
                    </div>

                    <!-- Form -->
                    <div class="p-8">
                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- Email Field -->
                            <div class="space-y-2">
                                <label for="email"
                                    :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('fp_email_address') }}
                                </label>

                                <div class="relative">
                                    <input id="email" v-model="form.email" type="email" autocomplete="email" required
                                        :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-orange-500 transition-all duration-200',
                                            'px-4 py-4 text-sm',
                                            form.errors.email
                                                ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                                : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                        ]" placeholder="example@domain.com">

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

                                <div v-if="form.errors.email"
                                    :class="['text-sm mt-1 flex items-center space-x-2', isDark ? 'text-red-400' : 'text-red-600']">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ form.errors.email }}</span>
                                </div>
                            </div>

                            <!-- Info Box -->
                            <div :class="[
                                'p-4 rounded-xl',
                                isDark ? 'bg-orange-900 bg-opacity-30 border border-orange-700' : 'bg-orange-50 border border-orange-200'
                            ]">
                                <div class="flex items-start">
                                    <svg :class="['w-5 h-5 mt-0.5 mr-3 flex-shrink-0', isDark ? 'text-orange-400' : 'text-orange-600']"
                                        fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <div :class="['text-sm', isDark ? 'text-orange-300' : 'text-orange-700']">
                                        <p class="font-medium mb-1">{{ t('fp_whats_next') }}:</p>
                                        <ul class="list-disc list-inside space-y-1 text-xs">
                                            <li>{{ t('fp_whats_next1') }}</li>
                                            <li>{{ t('fp_whats_next2') }}</li>
                                            <li>{{ t('fp_whats_next3') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" :disabled="form.processing" :class="[
                                'w-full py-4 px-6 rounded-xl font-semibold text-white transition-all duration-200 transform',
                                'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                'enabled:hover:scale-105 enabled:active:scale-95',
                                isDark
                                    ? 'bg-gradient-to-r from-orange-600 to-pink-600 hover:from-orange-700 hover:to-pink-700 focus:ring-orange-500 focus:ring-offset-gray-800'
                                    : 'bg-gradient-to-r from-orange-600 to-pink-600 hover:from-orange-700 hover:to-pink-700 focus:ring-orange-500 shadow-lg'
                            ]">
                                <span v-if="form.processing" class="flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                            stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                    {{ t('fp_sending') }}...
                                </span>
                                <span v-else class="flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M3 8l7.89 7.89a1 1 0 001.42 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                        </path>
                                    </svg>
                                    {{ t('fp_send_instructions') }}
                                </span>
                            </button>
                        </form>
                    </div>

                    <!-- Footer -->
                    <div :class="[
                        'px-8 py-6 border-t text-center',
                        isDark ? 'border-gray-700 bg-gray-800 bg-opacity-50' : 'border-gray-200 bg-gray-50'
                    ]">
                        <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('fp_remember_password') }}?
                            <Link :href="route('login')" :class="[
                                'font-semibold transition-colors duration-200 hover:underline ml-1',
                                isDark ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-500'
                            ]">
                            {{ t('fp_login') }}
                            </Link>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Footer Info -->
            <div class="mt-8 text-center">
                <p :class="['text-sm', isDark ? 'text-gray-500' : 'text-gray-600']">
                    {{ t('fp_footer_info') }} 🔐
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();
// Theme management
const isDark = ref(false);
const emailSent = ref(false);
const userEmail = ref('');
const resendCooldown = ref(0);

const form = useForm({
    email: ''
});

// Email validation
const isValidEmail = computed(() => {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(form.email);
});

const submit = () => {
    form.post(route('password.email'), {
        onSuccess: () => {
            emailSent.value = true;
            userEmail.value = form.email;
            startResendCooldown();
        }
    });
};

const resendEmail = () => {
    if (resendCooldown.value > 0) return;

    form.email = userEmail.value;
    form.post(route('password.email'), {
        onSuccess: () => {
            startResendCooldown();
        }
    });
};

const startResendCooldown = () => {
    resendCooldown.value = 60;
    const interval = setInterval(() => {
        resendCooldown.value--;
        if (resendCooldown.value <= 0) {
            clearInterval(interval);
        }
    }, 1000);
};

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
