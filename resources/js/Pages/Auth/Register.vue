<template>
    <PageTitle :title="t('register')" />
    <div :class="[
        'min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <!-- Background decoration -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div :class="[
                'absolute top-1/4 -left-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-green-500' : 'bg-green-300'
            ]"></div>
            <div :class="[
                'absolute bottom-1/4 -right-32 w-72 h-72 rounded-full opacity-20 blur-3xl animate-pulse',
                isDark ? 'bg-blue-500' : 'bg-blue-300'
            ]" style="animation-delay: 1s;"></div>
        </div>

        <!-- Theme Toggle -->
        <button @click="toggleTheme" :class="[
            'fixed top-6 right-6 p-3 rounded-xl transition-all duration-300 z-50 hover:scale-110',
            isDark ? 'bg-gray-800 text-yellow-400 hover:bg-gray-700' : 'bg-white text-gray-600 hover:bg-gray-50 shadow-lg'
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

        <!-- Register Card -->
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

            <div :class="[
                'backdrop-blur-sm border shadow-2xl rounded-3xl overflow-hidden transition-all duration-300',
                'transform hover:scale-[1.02]',
                isDark ? 'bg-gray-800 bg-opacity-90 border-gray-700' : 'bg-white bg-opacity-90 border-gray-200'
            ]">
                <!-- Header -->
                <div :class="['px-8 py-8 text-center border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                    <div
                        class="w-16 h-16 bg-gradient-to-br from-green-500 to-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z">
                            </path>
                        </svg>
                    </div>
                    <h2 :class="['text-3xl font-bold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('register_in') }} {{ page.props.app_name }}
                    </h2>
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ t('or') }}
                        <Link :href="route('login')"
                            :class="['font-semibold ml-1 hover:underline', isDark ? 'text-blue-400' : 'text-blue-600']">
                        {{ t('login_if_you_have_account') }}
                        </Link>
                    </p>
                </div>

                <!-- Form -->
                <div class="p-8">
                    <form @submit.prevent="submit" class="space-y-5">
                        <!-- Name -->
                        <div class="space-y-2">
                            <div class="flex items-center space-x-3">
                                <label :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{ t('name')
                                    }}</label>
                            </div>
                            <input v-model="form.name" type="text" required :class="[
                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-purple-500 transition-all px-4 py-3',
                                form.errors.name
                                    ? (isDark ? 'ring-red-600 bg-red-900/20 text-red-200' : 'ring-red-500 bg-red-50')
                                    : (isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 ring-gray-300')
                            ]" :placeholder="t('name')" />
                            <div v-if="form.errors.name" :class="['text-sm', isDark ? 'text-red-400' : 'text-red-600']">
                                {{ form.errors.name }}</div>
                        </div>

                        <!-- Email -->
                        <div class="space-y-2">
                            <div class="flex items-center space-x-3">
                                <label :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{ t('email')
                                    }}</label>
                            </div>
                            <input v-model="form.email" type="email" required :class="[
                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-blue-500 transition-all px-4 py-3',
                                form.errors.email
                                    ? (isDark ? 'ring-red-600 bg-red-900/20 text-red-200' : 'ring-red-500 bg-red-50')
                                    : (isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 ring-gray-300')
                            ]" :placeholder="t('email')" />
                            <div v-if="form.errors.email"
                                :class="['text-sm', isDark ? 'text-red-400' : 'text-red-600']">{{ form.errors.email }}
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="space-y-2">
                            <div class="flex items-center space-x-3">
                                <label :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    t('password') }}</label>
                            </div>
                            <input v-model="form.password" type="password" required :class="[
                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-green-500 transition-all px-4 py-3',
                                form.errors.password
                                    ? (isDark ? 'ring-red-600 bg-red-900/20 text-red-200' : 'ring-red-500 bg-red-50')
                                    : (isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 ring-gray-300')
                            ]" :placeholder="t('password')" />
                            <div v-if="form.errors.password"
                                :class="['text-sm', isDark ? 'text-red-400' : 'text-red-600']">{{ form.errors.password
                                }}</div>
                        </div>

                        <!-- Password Confirmation -->
                        <div class="space-y-2">
                            <div class="flex items-center space-x-3">
                                <label :class="['font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                                    t('password_confirmation') }}</label>
                            </div>
                            <input v-model="form.password_confirmation" type="password" required :class="[
                                'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-orange-500 transition-all px-4 py-3',
                                isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 ring-gray-300'
                            ]" :placeholder="t('password_confirmation')" />
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" :disabled="form.processing" :class="[
                            'w-full py-4 px-6 rounded-xl font-semibold text-white transition-all duration-200 transform',
                            'focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50',
                            'enabled:hover:scale-105 enabled:active:scale-95',
                            'bg-gradient-to-r from-green-600 to-blue-600 hover:from-green-700 hover:to-blue-700',
                            isDark ? 'focus:ring-green-500 focus:ring-offset-gray-800' : 'focus:ring-green-500 shadow-lg'
                        ]">
                            <span v-if="form.processing" class="flex items-center justify-center">
                                <svg class="w-5 h-5 mr-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                {{ t('register_creating_account') }}
                            </span>
                            <span v-else>{{ t('register') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, onMounted } from 'vue';
import PageTitle from '@/Components/PageTitle.vue';

const page = usePage();

const { t } = useI18n();
const isDark = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: ''
});

const submit = () => {
    form.post(route('register'));
};

const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', isDark.value);
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { isDark: isDark.value } }));
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
});
</script>