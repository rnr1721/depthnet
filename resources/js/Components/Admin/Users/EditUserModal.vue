<template>
    <!-- Modal Overlay -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
                <!-- Background overlay -->
                <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity"
                    @click="closeModal"></div>

                <!-- Modal container -->
                <div class="flex min-h-full items-center justify-center p-4">
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 scale-100"
                        leave-to-class="transform opacity-0 scale-95">
                        <div v-if="show" :class="[
                            'relative transform rounded-2xl shadow-2xl transition-all w-full max-w-md',
                            isDark
                                ? 'bg-gray-800 border border-gray-700'
                                : 'bg-white border border-gray-200'
                        ]">
                            <!-- Modal Header -->
                            <div :class="[
                                'flex items-center justify-between px-6 py-4 border-b',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <div class="flex items-center space-x-3">
                                    <div :class="[
                                        'w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold',
                                        user?.is_admin
                                            ? 'bg-gradient-to-br from-yellow-500 to-orange-600'
                                            : 'bg-gradient-to-br from-blue-500 to-purple-600'
                                    ]">
                                        {{ user?.name?.charAt(0).toUpperCase() || '?' }}
                                    </div>
                                    <div>
                                        <h3 :class="[
                                            'text-lg font-semibold',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">
                                            {{ t('modals_edit_user') }}
                                        </h3>
                                        <p :class="[
                                            'text-sm',
                                            isDark ? 'text-gray-400' : 'text-gray-600'
                                        ]">
                                            ID: {{ user?.id }}
                                        </p>
                                    </div>
                                </div>

                                <button @click="closeModal" :class="[
                                    'p-2 rounded-lg transition-colors hover:bg-opacity-80',
                                    isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-500'
                                ]">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>

                            <!-- Modal Body -->
                            <form @submit.prevent="submitForm" class="px-6 py-6 space-y-4">
                                <!-- Name Field -->
                                <div>
                                    <label :class="[
                                        'block text-sm font-medium mb-2',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">
                                        {{ t('modals_user_name') }}
                                    </label>
                                    <input v-model="form.name" type="text" :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3',
                                        errors.name
                                            ? 'ring-red-500 focus:ring-red-500'
                                            : 'focus:ring-blue-500',
                                        isDark
                                            ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                                            : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
                                    ]" :placeholder="t('modals_user_name_ph')" :disabled="loading" />
                                    <p v-if="errors.name" class="mt-1 text-sm text-red-500">
                                        {{ errors.name }}
                                    </p>
                                </div>

                                <!-- Email Field -->
                                <div>
                                    <label :class="[
                                        'block text-sm font-medium mb-2',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">
                                        {{ t('modals_user_email') }}
                                    </label>
                                    <input v-model="form.email" type="email" :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3',
                                        errors.email
                                            ? 'ring-red-500 focus:ring-red-500'
                                            : 'focus:ring-blue-500',
                                        isDark
                                            ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                                            : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
                                    ]" :placeholder="t('modals_user_email_ph')" :disabled="loading" />
                                    <p v-if="errors.email" class="mt-1 text-sm text-red-500">
                                        {{ errors.email }}
                                    </p>
                                </div>

                                <!-- Password Section -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label :class="[
                                            'text-sm font-medium',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            {{ t('modals_new_password') }}
                                        </label>
                                        <button type="button" @click="showPasswordSection = !showPasswordSection"
                                            :class="[
                                                'text-xs px-2 py-1 rounded-lg transition-colors',
                                                showPasswordSection
                                                    ? (isDark ? 'bg-red-900 text-red-300' : 'bg-red-100 text-red-700')
                                                    : (isDark ? 'bg-blue-900 text-blue-300' : 'bg-blue-100 text-blue-700')
                                            ]">
                                            {{ showPasswordSection ? '❌ ' + t('modals_cancel') : '🔄 ' + t('modals_password_change') }}
                                        </button>
                                    </div>

                                    <Transition enter-active-class="transition-all duration-300 ease-out"
                                        enter-from-class="opacity-0 max-h-0" enter-to-class="opacity-100 max-h-96"
                                        leave-active-class="transition-all duration-200 ease-in"
                                        leave-from-class="opacity-100 max-h-96" leave-to-class="opacity-0 max-h-0">
                                        <div v-if="showPasswordSection" class="space-y-4 overflow-hidden">
                                            <!-- New Password -->
                                            <div class="relative">
                                                <input v-model="form.password"
                                                    :type="showPassword ? 'text' : 'password'" :class="[
                                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3 pr-12',
                                                        errors.password
                                                            ? 'ring-red-500 focus:ring-red-500'
                                                            : 'focus:ring-blue-500',
                                                        isDark
                                                            ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                                                            : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
                                                    ]" :placeholder="t('modal_new_password_ph')"
                                                    :disabled="loading" />
                                                <button type="button" @click="showPassword = !showPassword" :class="[
                                                    'absolute inset-y-0 right-0 flex items-center pr-3',
                                                    isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-500 hover:text-gray-700'
                                                ]">
                                                    <svg v-if="showPassword" class="w-5 h-5" fill="none"
                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21">
                                                        </path>
                                                    </svg>
                                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z">
                                                        </path>
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                                        </path>
                                                    </svg>
                                                </button>
                                            </div>
                                            <p v-if="errors.password" class="text-sm text-red-500">
                                                {{ errors.password }}
                                            </p>

                                            <!-- Confirm Password -->
                                            <div>
                                                <input v-model="form.password_confirmation" type="password" :class="[
                                                    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3',
                                                    errors.password_confirmation
                                                        ? 'ring-red-500 focus:ring-red-500'
                                                        : 'focus:ring-blue-500',
                                                    isDark
                                                        ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600'
                                                        : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300'
                                                ]" :placeholder="t('modals_new_password_confirm')" :disabled="loading" />
                                                <p v-if="errors.password_confirmation"
                                                    class="mt-1 text-sm text-red-500">
                                                    {{ errors.password_confirmation }}
                                                </p>
                                            </div>
                                        </div>
                                    </Transition>
                                </div>

                                <!-- Admin Role Checkbox -->
                                <div class="flex items-center space-x-3">
                                    <div class="flex items-center h-5">
                                        <input v-model="form.is_admin" type="checkbox" :class="[
                                            'w-4 h-4 rounded border-2 focus:ring-2 focus:ring-blue-500 transition-all',
                                            isDark
                                                ? 'bg-gray-700 border-gray-600 text-blue-600'
                                                : 'bg-white border-gray-300 text-blue-600'
                                        ]" :disabled="loading" />
                                    </div>
                                    <label :class="[
                                        'text-sm font-medium',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">
                                        {{ t('modals_admin_rights') }}
                                    </label>
                                </div>

                                <!-- User Info -->
                                <div :class="[
                                    'p-4 rounded-xl border-l-4',
                                    isDark
                                        ? 'bg-gray-700 bg-opacity-50 border-blue-400'
                                        : 'bg-blue-50 border-blue-400'
                                ]">
                                    <div :class="[
                                        'text-sm',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">
                                        📅 <strong>{{ t('modals_created') }}:</strong> {{ formatDate(user?.created_at) }}<br>
                                        🔄 <strong>{{ t('modals_updated') }}:</strong> {{ formatDate(user?.updated_at) }}
                                    </div>
                                </div>

                                <!-- Form Buttons -->
                                <div class="flex space-x-3 pt-4">
                                    <button type="button" @click="closeModal" :disabled="loading" :class="[
                                        'flex-1 px-4 py-3 rounded-xl font-semibold transition-all',
                                        'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                        loading ? 'opacity-50 cursor-not-allowed' : 'hover:scale-105',
                                        isDark
                                            ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-gray-500 focus:ring-offset-gray-800'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-500'
                                    ]">
                                        {{ t('modals_cancel') }}
                                    </button>

                                    <button type="submit" :disabled="loading || !isFormValid" :class="[
                                        'flex-1 px-4 py-3 rounded-xl font-semibold transition-all',
                                        'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                        'bg-gradient-to-r from-emerald-600 to-blue-600 text-white',
                                        loading || !isFormValid
                                            ? 'opacity-50 cursor-not-allowed'
                                            : 'hover:from-emerald-700 hover:to-blue-700 hover:scale-105 active:scale-95',
                                        isDark ? 'focus:ring-emerald-500 focus:ring-offset-gray-800' : 'focus:ring-emerald-500'
                                    ]">
                                        <span v-if="loading" class="flex items-center justify-center">
                                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none"
                                                viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            {{ t('modals_storing') }}
                                        </span>
                                        <span v-else>{{ t('modals_store') }}</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Props
const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    isDark: {
        type: Boolean,
        default: false
    },
    user: {
        type: Object,
        default: null
    }
});

// Emits
const emit = defineEmits(['close', 'updated']);

// Form data
const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false
});

// UI state
const loading = ref(false);
const showPassword = ref(false);
const showPasswordSection = ref(false);
const errors = ref({});

// Computed
const isFormValid = computed(() => {
    const baseValid = form.value.name.trim() && form.value.email.trim();

    if (showPasswordSection.value) {
        return baseValid &&
            form.value.password.length >= 8 &&
            form.value.password === form.value.password_confirmation;
    }

    return baseValid;
});

// Methods
const formatDate = (dateString) => {
    if (!dateString) return 'Неизвестно';
    return new Date(dateString).toLocaleDateString('ru-RU', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const closeModal = () => {
    if (!loading.value) {
        resetForm();
        emit('close');
    }
};

const resetForm = () => {
    form.value = {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        is_admin: false
    };
    errors.value = {};
    showPassword.value = false;
    showPasswordSection.value = false;
};

const populateForm = () => {
    if (props.user) {
        form.value = {
            name: props.user.name || '',
            email: props.user.email || '',
            password: '',
            password_confirmation: '',
            is_admin: props.user.is_admin || false
        };
    }
};

const submitForm = () => {
    if (!isFormValid.value || loading.value || !props.user) return;

    loading.value = true;
    errors.value = {};

    // Prepare form data
    const formData = {
        name: form.value.name,
        email: form.value.email,
        is_admin: form.value.is_admin,
        _method: 'PUT'
    };

    // Add password fields only if password section is shown and password is provided
    if (showPasswordSection.value && form.value.password) {
        formData.password = form.value.password;
        formData.password_confirmation = form.value.password_confirmation;
    }

    router.post(`/admin/users/${props.user.id}`, formData, {
        onSuccess: (page) => {
            resetForm();
            emit('updated');
        },
        onError: (pageErrors) => {
            errors.value = pageErrors;
        },
        onFinish: () => {
            loading.value = false;
        }
    });
};

// Watch for show prop changes to populate form
watch(() => props.show, (newShow) => {
    if (newShow && props.user) {
        populateForm();
    } else if (!newShow) {
        resetForm();
    }
});

// Watch for user prop changes
watch(() => props.user, (newUser) => {
    if (newUser && props.show) {
        populateForm();
    }
});

// Handle escape key
const handleKeydown = (event) => {
    if (event.key === 'Escape' && props.show && !loading.value) {
        closeModal();
    }
};

// Add/remove event listener
watch(() => props.show, (newShow) => {
    if (newShow) {
        document.addEventListener('keydown', handleKeydown);
        document.body.style.overflow = 'hidden';
    } else {
        document.removeEventListener('keydown', handleKeydown);
        document.body.style.overflow = '';
    }
});
</script>