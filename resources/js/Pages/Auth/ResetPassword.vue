<template>
    <div :class="[
        'min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <!-- Background decoration -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div :class="[
                'absolute top-1/4 -left-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-emerald-500' : 'bg-emerald-300'
            ]"></div>
            <div :class="[
                'absolute bottom-1/4 -right-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-teal-500' : 'bg-teal-300'
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
            <!-- Success State -->
            <div v-if="passwordReset" class="text-center">
                <div :class="[
                    'backdrop-blur-sm border shadow-2xl rounded-3xl overflow-hidden transition-all duration-300 p-8',
                    'transform hover:scale-[1.02] hover:shadow-3xl',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- Success Icon -->
                    <div class="flex justify-center mb-6">
                        <div
                            class="w-20 h-20 bg-gradient-to-br from-green-500 to-emerald-500 rounded-3xl flex items-center justify-center shadow-lg">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>

                    <h2 :class="['text-2xl font-bold mb-4', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('rp_success_changed') }}! 🎉
                    </h2>

                    <p :class="['text-sm mb-6 leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-600']">
                        {{ t('rp_success_final') }}.
                    </p>

                    <Link :href="route('login')" :class="[
                        'inline-flex items-center justify-center w-full py-4 px-6 rounded-xl font-semibold text-white transition-all duration-200 transform',
                        'focus:outline-none focus:ring-2 focus:ring-offset-2',
                        'hover:scale-105 active:scale-95',
                        isDark
                            ? 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 focus:ring-emerald-500 focus:ring-offset-gray-800'
                            : 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 focus:ring-emerald-500 shadow-lg'
                    ]">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1">
                        </path>
                    </svg>
                    {{ t('rp_login_account') }}
                    </Link>
                </div>
            </div>

            <!-- Form State -->
            <div v-else>
                <!-- Back Button -->
                <Link :href="route('login')" :class="[
                    'mb-4 flex items-center text-sm font-medium transition-colors duration-200 hover:underline',
                    isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-500'
                ]">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                {{ t('rp_back_to_login') }}
                </Link>

                <!-- Form Card -->
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
                                class="w-20 h-20 bg-gradient-to-br from-emerald-500 via-teal-500 to-cyan-500 rounded-3xl flex items-center justify-center shadow-lg">
                                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                    </path>
                                </svg>
                            </div>
                        </div>

                        <!-- Title -->
                        <h2 :class="['text-3xl font-bold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('rp_new_password') }}
                        </h2>

                        <!-- Subtitle -->
                        <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ t('rp_new_password_subtitle') }}
                        </p>
                    </div>

                    <!-- Form -->
                    <div class="p-8">
                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- Email Display -->
                            <div class="space-y-2">
                                <label :class="['text-sm font-medium', isDark ? 'text-gray-300' : 'text-gray-700']">
                                    {{ t('rp_email_address') }}
                                </label>
                                <div :class="[
                                    'w-full rounded-xl px-4 py-3 text-sm border',
                                    isDark ? 'bg-gray-700 text-gray-300 border-gray-600' : 'bg-gray-100 text-gray-600 border-gray-300'
                                ]">
                                    {{ email }}
                                </div>
                            </div>

                            <!-- Password Field -->
                            <div class="space-y-2">
                                <label for="password"
                                    :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('rp_new_password') }}
                                </label>

                                <div class="relative">
                                    <input id="password" v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required
                                        :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all duration-200',
                                            'px-4 py-4 pr-12 text-sm',
                                            form.errors.password
                                                ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                                : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                        ]" :placeholder="t('rp_enter_new_pass')">

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
                                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                            </path>
                                        </svg>
                                    </button>
                                </div>

                                <!-- Password Strength Indicator -->
                                <div v-if="form.password" class="mt-2">
                                    <div class="flex items-center space-x-2">
                                        <div class="flex-1 bg-gray-200 rounded-full h-2">
                                            <div :class="[
                                                'h-2 rounded-full transition-all duration-300',
                                                passwordStrength.color
                                            ]" :style="{ width: passwordStrength.width }"></div>
                                        </div>
                                        <span :class="['text-xs font-medium', passwordStrength.textColor]">
                                            {{ passwordStrength.text }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="form.errors.password"
                                    :class="['text-sm mt-1 flex items-center space-x-2', isDark ? 'text-red-400' : 'text-red-600']">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ form.errors.password }}</span>
                                </div>
                            </div>

                            <!-- Confirm Password Field -->
                            <div class="space-y-2">
                                <label for="password_confirmation"
                                    :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ t('rp_confirm_password') }}
                                </label>

                                <div class="relative">
                                    <input id="password_confirmation" v-model="form.password_confirmation"
                                        :type="showConfirmPassword ? 'text' : 'password'" autocomplete="new-password"
                                        required :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all duration-200',
                                            'px-4 py-4 pr-12 text-sm',
                                            !passwordsMatch && form.password_confirmation
                                                ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                                : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                        ]" :placeholder="t('rp_repeat_new_pass')">

                                    <!-- Show/Hide Password Button -->
                                    <button type="button" @click="showConfirmPassword = !showConfirmPassword"
                                        class="absolute right-3 top-4 focus:outline-none"
                                        :class="isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-500 hover:text-gray-700'">
                                        <svg v-if="showConfirmPassword" class="w-5 h-5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21">
                                            </path>
                                        </svg>
                                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                            </path>
                                        </svg>
                                    </button>
                                </div>

                                <!-- Password Match Indicator -->
                                <div v-if="form.password_confirmation" class="flex items-center space-x-2 text-sm">
                                    <svg v-if="passwordsMatch" class="w-4 h-4 text-green-500" fill="currentColor"
                                        viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <svg v-else class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span :class="passwordsMatch ? 'text-green-600' : 'text-red-600'">
                                        {{ passwordsMatch ? t('rp_passwords_match') : t('rp_passwords_not_match') }}
                                    </span>
                                </div>
                            </div>

                            <!-- Password Requirements -->
                            <div :class="[
                                'p-4 rounded-xl',
                                isDark ? 'bg-emerald-900 bg-opacity-30 border border-emerald-700' : 'bg-emerald-50 border border-emerald-200'
                            ]">
                                <p
                                    :class="['text-sm font-medium mb-2', isDark ? 'text-emerald-300' : 'text-emerald-700']">
                                    {{ t('rp_password_reqs') }}:
                                </p>
                                <ul class="space-y-1 text-xs">
                                    <li
                                        :class="['flex items-center space-x-2', passwordRequirements.length ? 'text-green-600' : (isDark ? 'text-gray-400' : 'text-gray-600')]">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path v-if="passwordRequirements.length" fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                clip-rule="evenodd"></path>
                                            <circle v-else cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"
                                                fill="none"></circle>
                                        </svg>
                                        <span>{{ t('rp_min_length') }}</span>
                                    </li>
                                    <li
                                        :class="['flex items-center space-x-2', passwordRequirements.hasUppercase ? 'text-green-600' : (isDark ? 'text-gray-400' : 'text-gray-600')]">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path v-if="passwordRequirements.hasUppercase" fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                clip-rule="evenodd"></path>
                                            <circle v-else cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"
                                                fill="none"></circle>
                                        </svg>
                                        <span>{{ t('rp_capital_symbol') }}</span>
                                    </li>
                                    <li
                                        :class="['flex items-center space-x-2', passwordRequirements.hasNumber ? 'text-green-600' : (isDark ? 'text-gray-400' : 'text-gray-600')]">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path v-if="passwordRequirements.hasNumber" fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                clip-rule="evenodd"></path>
                                            <circle v-else cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"
                                                fill="none"></circle>
                                        </svg>
                                        <span>{{ t('rp_number') }}</span>
                                    </li>
                                </ul>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" :disabled="form.processing || !passwordsMatch || !isPasswordValid"
                                :class="[
                                    'w-full py-4 px-6 rounded-xl font-semibold text-white transition-all duration-200 transform',
                                    'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                    'enabled:hover:scale-105 enabled:active:scale-95',
                                    isDark
                                        ? 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 focus:ring-emerald-500 focus:ring-offset-gray-800'
                                        : 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 focus:ring-emerald-500 shadow-lg'
                                ]">
                                <span v-if="form.processing" class="flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                            stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                    {{ t('rp_saving') }}...
                                </span>
                                <span v-else class="flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3-3m0 0l-3 3m3-3v12">
                                        </path>
                                    </svg>
                                    {{ t('rp_change_password') }}
                                </span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Footer Info -->
            <div class="mt-8 text-center">
                <p :class="['text-sm', isDark ? 'text-gray-500' : 'text-gray-600']">
                    {{ t('rp_footer_info') }} 🔐
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Props from backend
const props = defineProps({
    token: String,
    email: String,
});

// Theme management
const isDark = ref(false);
const showPassword = ref(false);
const showConfirmPassword = ref(false);
const passwordReset = ref(false);

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

// Password validation
const passwordRequirements = computed(() => ({
    length: form.password.length >= 8,
    hasUppercase: /[A-Z]/.test(form.password),
    hasNumber: /\d/.test(form.password),
}));

const isPasswordValid = computed(() => {
    return passwordRequirements.value.length &&
        passwordRequirements.value.hasUppercase &&
        passwordRequirements.value.hasNumber;
});

const passwordsMatch = computed(() => {
    return form.password && form.password_confirmation && form.password === form.password_confirmation;
});

const passwordStrength = computed(() => {
    const password = form.password;
    let score = 0;

    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/\d/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    if (score <= 2) {
        return {
            width: '25%',
            color: 'bg-red-500',
            textColor: 'text-red-600',
            text: t('rp_passwrd_weak')
        };
    } else if (score <= 3) {
        return {
            width: '50%',
            color: 'bg-yellow-500',
            textColor: 'text-yellow-600',
            text: t('rp_passwrd_average')
        };
    } else if (score <= 4) {
        return {
            width: '75%',
            color: 'bg-blue-500',
            textColor: 'text-blue-600',
            text: t('rp_passwrd_strong')
        };
    } else {
        return {
            width: '100%',
            color: 'bg-green-500',
            textColor: 'text-green-600',
            text: t('rp_passwrd_perfect')
        };
    }
});

const submit = () => {
    form.post(route('password.update'), {
        onSuccess: () => {
            passwordReset.value = true;
        }
    });
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