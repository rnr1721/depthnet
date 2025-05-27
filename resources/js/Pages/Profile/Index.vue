<template>
    <PageTitle :title="t('profile')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('profile_profile_settings')" :isAdmin="isAdmin" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-blue-500' : 'bg-blue-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-emerald-500' : 'bg-emerald-300'
                ]"></div>
            </div>

            <div class="relative max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Success message -->
                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.success" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark
                            ? 'bg-green-900 bg-opacity-50 border-green-400 text-green-200'
                            : 'bg-green-50 border-green-400 text-green-800'
                    ]">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.success }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Profile Information Form -->
                <div :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all mb-8',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- Header -->
                    <div :class="[
                        'px-6 py-8 border-b',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div class="flex items-center space-x-4">
                            <div
                                class="w-16 h-16 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl flex items-center justify-center">
                                <span class="text-white text-2xl font-bold">{{ user.name.charAt(0).toUpperCase()
                                    }}</span>
                            </div>
                            <div>
                                <h2 :class="[
                                    'text-2xl font-bold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">{{ t('profile_profile_info') }}</h2>
                                <p :class="[
                                    'text-sm mt-1',
                                    isDark ? 'text-gray-400' : 'text-gray-600'
                                ]">{{ t('profile_managing_basic_profile_info') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Form content -->
                    <div class="p-6 sm:p-8">
                        <form @submit.prevent="submitProfileForm" class="space-y-6">
                            <!-- Name field -->
                            <div class="space-y-2">
                                <div class="flex items-center space-x-3">
                                    <label for="name" :class="[
                                        'text-lg font-semibold',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('profile_name') }}
                                    </label>
                                </div>

                                <div class="relative">
                                    <input id="name" v-model="profileForm.name" type="text" :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
                                        'px-4 py-3 text-sm',
                                        profileForm.errors.name
                                            ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                            : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                    ]" :placeholder="t('profile_enter_name')">
                                    <div v-if="profileForm.errors.name" :class="[
                                        'absolute right-3 top-3',
                                        isDark ? 'text-red-400' : 'text-red-500'
                                    ]">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd"
                                                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                </div>
                                <div v-if="profileForm.errors.name" :class="[
                                    'text-sm mt-1 flex items-center space-x-2',
                                    isDark ? 'text-red-400' : 'text-red-600'
                                ]">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ profileForm.errors.name }}</span>
                                </div>
                            </div>

                            <!-- Email field -->
                            <div class="space-y-2">
                                <div class="flex items-center space-x-3">
                                    <label for="email" :class="[
                                        'text-lg font-semibold',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('profile_email') }}
                                    </label>
                                </div>

                                <div class="relative">
                                    <input id="email" v-model="profileForm.email" type="email" :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
                                        'px-4 py-3 text-sm',
                                        profileForm.errors.email
                                            ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                            : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                    ]" :placeholder="t('profile_enter_email')">
                                    <div v-if="profileForm.errors.email" :class="[
                                        'absolute right-3 top-3',
                                        isDark ? 'text-red-400' : 'text-red-500'
                                    ]">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd"
                                                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                </div>
                                <div v-if="profileForm.errors.email" :class="[
                                    'text-sm mt-1 flex items-center space-x-2',
                                    isDark ? 'text-red-400' : 'text-red-600'
                                ]">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ profileForm.errors.email }}</span>
                                </div>
                            </div>

                            <!-- Action buttons -->
                            <div class="flex flex-col sm:flex-row sm:justify-end space-y-3 sm:space-y-0 sm:space-x-4 pt-6 border-t"
                                :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                                <button type="button" @click="resetProfileForm" :class="[
                                    'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                    isDark
                                        ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]">
                                    {{ t('profile_cancel') }}
                                </button>
                                <button type="submit" :disabled="profileForm.processing" :class="[
                                    'px-8 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                    'enabled:hover:scale-105 enabled:active:scale-95',
                                    isDark
                                        ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
                                        : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                                ]">
                                    <span v-if="profileForm.processing" class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('profile_begin_store') }}
                                    </span>
                                    <span v-else class="flex items-center">
                                        {{ t('profile_save_changes') }}
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Password change form -->
                <div :class="[
                    'backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- Header -->
                    <div :class="[
                        'px-6 py-8 border-b',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div class="flex items-center space-x-4">
                            <div
                                class="w-12 h-12 bg-gradient-to-br from-red-500 to-pink-600 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                    </path>
                                </svg>
                            </div>
                            <div>
                                <h2 :class="[
                                    'text-2xl font-bold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">{{ t('profile_change_password') }}</h2>
                                <p :class="[
                                    'text-sm mt-1',
                                    isDark ? 'text-gray-400' : 'text-gray-600'
                                ]">{{ t('profile_change_password_description') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Form content -->
                    <div class="p-6 sm:p-8">
                        <form @submit.prevent="submitPasswordForm" class="space-y-6">
                            <!-- Current password -->
                            <div class="space-y-2">
                                <div class="flex items-center space-x-3">
                                    <label for="current_password" :class="[
                                        'text-lg font-semibold',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('profile_current_password') }}
                                    </label>
                                </div>

                                <div class="relative">
                                    <input id="current_password" v-model="passwordForm.current_password" type="password"
                                        :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
                                            'px-4 py-3 text-sm',
                                            passwordForm.errors.current_password
                                                ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                                : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                        ]" :placeholder="t('profile_current_password')">
                                </div>
                                <div v-if="passwordForm.errors.current_password" :class="[
                                    'text-sm mt-1 flex items-center space-x-2',
                                    isDark ? 'text-red-400' : 'text-red-600'
                                ]">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ passwordForm.errors.current_password }}</span>
                                </div>
                            </div>

                            <!-- New password -->
                            <div class="space-y-2">
                                <div class="flex items-center space-x-3">
                                    <label for="password" :class="[
                                        'text-lg font-semibold',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('profile_new_password') }}
                                    </label>
                                </div>

                                <div class="relative">
                                    <input id="password" v-model="passwordForm.password" type="password" :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
                                        'px-4 py-3 text-sm',
                                        passwordForm.errors.password
                                            ? (isDark ? 'ring-red-600 bg-red-900 bg-opacity-20 text-red-200' : 'ring-red-500 bg-red-50 text-red-900')
                                            : (isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                    ]" :placeholder="t('profile_new_password')">
                                </div>
                                <div v-if="passwordForm.errors.password" :class="[
                                    'text-sm mt-1 flex items-center space-x-2',
                                    isDark ? 'text-red-400' : 'text-red-600'
                                ]">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ passwordForm.errors.password }}</span>
                                </div>
                            </div>

                            <!-- Confirm password -->
                            <div class="space-y-2">
                                <div class="flex items-center space-x-3">
                                    <label for="password_confirmation" :class="[
                                        'text-lg font-semibold',
                                        isDark ? 'text-white' : 'text-gray-900'
                                    ]">
                                        {{ t('profile_confirm_password') }}
                                    </label>
                                </div>

                                <div class="relative">
                                    <input id="password_confirmation" v-model="passwordForm.password_confirmation"
                                        type="password" :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
                                            'px-4 py-3 text-sm',
                                            isDark
                                                ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                                                : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
                                        ]" :placeholder="t('profile_confirm_password')">
                                </div>
                            </div>

                            <!-- Password strength indicator -->
                            <div v-if="passwordForm.password" :class="[
                                'p-4 rounded-xl border',
                                isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
                            ]">
                                <div class="flex items-center space-x-2 mb-2">
                                    <svg class="w-4 h-4" :class="passwordStrength.color" fill="currentColor"
                                        viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd"></path>
                                    </svg>
                                    <span :class="['text-sm font-medium', passwordStrength.color]">
                                        {{ t('profile_password_strength') }}: {{ passwordStrength.text }}
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div :class="[
                                        'h-2 rounded-full transition-all duration-300',
                                        passwordStrength.color.replace('text-', 'bg-')
                                    ]" :style="{ width: passwordStrength.percentage + '%' }"></div>
                                </div>
                            </div>

                            <!-- Action buttons -->
                            <div class="flex flex-col sm:flex-row sm:justify-end space-y-3 sm:space-y-0 sm:space-x-4 pt-6 border-t"
                                :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                                <button type="button" @click="resetPasswordForm" :class="[
                                    'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                    isDark
                                        ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]">
                                    {{ t('profile_password_clear') }}
                                </button>
                                <button type="submit" :disabled="passwordForm.processing" :class="[
                                    'px-8 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2',
                                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                    'enabled:hover:scale-105 enabled:active:scale-95',
                                    isDark
                                        ? 'bg-red-600 hover:bg-red-700 text-white focus:ring-offset-gray-800'
                                        : 'bg-red-600 hover:bg-red-700 text-white'
                                ]">
                                    <span v-if="passwordForm.processing" class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('profile_password_changing') }}
                                    </span>
                                    <span v-else class="flex items-center">
                                        {{ t('profile_change_password') }}
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Security tips card -->
                <div :class="[
                    'mt-8 p-6 rounded-2xl border backdrop-blur-sm',
                    isDark
                        ? 'bg-gray-800 bg-opacity-50 border-gray-700'
                        : 'bg-white bg-opacity-50 border-gray-200'
                ]">
                    <div class="flex items-start space-x-3">
                        <div
                            class="w-8 h-8 bg-gradient-to-br from-yellow-500 to-orange-600 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                                </path>
                            </svg>
                        </div>
                        <div>
                            <h3 :class="[
                                'font-semibold mb-2',
                                isDark ? 'text-white' : 'text-gray-900'
                            ]">{{ t('profile_security_tips') }}</h3>
                            <ul :class="[
                                'text-sm space-y-1',
                                isDark ? 'text-gray-400' : 'text-gray-600'
                            ]">
                                <li>• {{ t('profile_security_tip_1') }}</li>
                                <li>• {{ t('profile_security_tip_2') }}</li>
                                <li>• {{ t('profile_security_tip_3') }}</li>
                                <li>• {{ t('profile_security_tip_4') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import PageTitle from '@/Components/PageTitle.vue';
import AdminHeader from '@/Components/AdminHeader.vue';

const { t } = useI18n();

// Theme management
const isDark = ref(false);

const props = defineProps({
    user: Object,
});

const isAdmin = computed(() => {
    return props.user && props.user.is_admin;
});

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

// Password strength calculator
const passwordStrength = computed(() => {
    const password = passwordForm.password;
    if (!password) return { percentage: 0, text: 'Не указан', color: 'text-gray-400' };

    let score = 0;

    // Length check
    if (password.length >= 8) score += 25;
    if (password.length >= 12) score += 15;

    // Character variety
    if (/[a-z]/.test(password)) score += 15;
    if (/[A-Z]/.test(password)) score += 15;
    if (/[0-9]/.test(password)) score += 15;
    if (/[^A-Za-z0-9]/.test(password)) score += 15;

    if (score < 30) return { percentage: score, text: 'Слабый', color: 'text-red-500' };
    if (score < 60) return { percentage: score, text: 'Средний', color: 'text-yellow-500' };
    if (score < 90) return { percentage: score, text: 'Хороший', color: 'text-blue-500' };
    return { percentage: score, text: 'Отличный', color: 'text-green-500' };
});

const originalProfileData = {
    name: props.user.name,
    email: props.user.email,
};

const submitProfileForm = () => {
    profileForm.put(route('profile.update'));
};

const submitPasswordForm = () => {
    passwordForm.put(route('profile.password'), {
        onSuccess: () => {
            passwordForm.reset();
        }
    });
};

const resetProfileForm = () => {
    profileForm.name = originalProfileData.name;
    profileForm.email = originalProfileData.email;
    profileForm.clearErrors();
};

const resetPasswordForm = () => {
    passwordForm.reset();
    passwordForm.clearErrors();
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