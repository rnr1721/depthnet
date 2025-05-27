<template>
    <!-- Modal Overlay -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
                <!-- Background overlay -->
                <div class="fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm transition-opacity"
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
                                ? 'bg-gray-800 border border-red-500/30'
                                : 'bg-white border border-red-300/30'
                        ]">
                            <!-- Modal Header -->
                            <div :class="[
                                'flex items-center justify-between px-6 py-5 border-b',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <div class="flex items-center space-x-3">
                                    <!-- Danger Icon -->
                                    <div
                                        class="w-12 h-12 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center animate-pulse">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.864-.833-2.464 0L5.268 18.5c-.77.833.192 2.5 1.732 2.5z">
                                            </path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 :class="[
                                            'text-lg font-bold text-red-600',
                                            isDark ? 'text-red-400' : 'text-red-600'
                                        ]">
                                            {{ t('modals_delete_user') }}
                                        </h3>
                                        <p :class="[
                                            'text-sm',
                                            isDark ? 'text-gray-400' : 'text-gray-600'
                                        ]">
                                            {{ t('modals_delete_irrev') }}
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
                            <div class="px-6 py-6">
                                <!-- User Info -->
                                <div v-if="user" :class="[
                                    'flex items-center space-x-4 p-4 rounded-xl border-2 border-dashed mb-6',
                                    isDark
                                        ? 'bg-red-900/20 border-red-600/30'
                                        : 'bg-red-50 border-red-300/50'
                                ]">
                                    <div :class="[
                                        'w-12 h-12 rounded-xl flex items-center justify-center text-white font-bold text-lg',
                                        user.is_admin
                                            ? 'bg-gradient-to-br from-yellow-500 to-orange-600'
                                            : 'bg-gradient-to-br from-blue-500 to-purple-600'
                                    ]">
                                        {{ user.name?.charAt(0).toUpperCase() || '?' }}
                                    </div>
                                    <div class="flex-1">
                                        <div :class="[
                                            'font-bold text-lg',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">
                                            {{ user.name }}
                                        </div>
                                        <div :class="[
                                            'text-sm',
                                            isDark ? 'text-gray-300' : 'text-gray-600'
                                        ]">
                                            {{ user.email }}
                                        </div>
                                        <div class="flex items-center space-x-2 mt-1">
                                            <span :class="[
                                                'inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold',
                                                user.is_admin
                                                    ? (isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800')
                                                    : (isDark ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-800')
                                            ]">
                                                {{ user.is_admin ? 'Администратор' : 'Пользователь' }}
                                            </span>
                                            <span :class="[
                                                'text-xs',
                                                isDark ? 'text-gray-400' : 'text-gray-500'
                                            ]">
                                                ID: {{ user.id }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Warning Text -->
                                <div :class="[
                                    'p-4 rounded-xl mb-6',
                                    isDark
                                        ? 'bg-gray-700 border border-gray-600'
                                        : 'bg-gray-50 border border-gray-200'
                                ]">
                                    <div class="flex items-start space-x-3">
                                        <div class="flex-shrink-0">
                                            <svg class="w-5 h-5 text-red-500 mt-0.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.864-.833-2.464 0L5.268 18.5c-.77.833.192 2.5 1.732 2.5z">
                                                </path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 :class="[
                                                'font-semibold text-sm mb-2',
                                                isDark ? 'text-red-400' : 'text-red-600'
                                            ]">
                                                {{ t('modals_delete_warning') }}
                                            </h4>
                                            <ul :class="[
                                                'text-sm space-y-1',
                                                isDark ? 'text-gray-300' : 'text-gray-700'
                                            ]">
                                                <li class="flex items-center">
                                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-2"></span>
                                                    {{ t('modals_delete_warning1') }}
                                                </li>
                                                <li class="flex items-center">
                                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-2"></span>
                                                    {{ t('modals_delete_warning2') }}
                                                </li>
                                                <li class="flex items-center">
                                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full mr-2"></span>
                                                    {{ t('modals_delete_warning3') }}
                                                </li>
                                            </ul>
                                            <p :class="[
                                                'text-xs mt-3 font-medium',
                                                isDark ? 'text-red-400' : 'text-red-600'
                                            ]">
                                                {{ t('modals_delete_irrev2') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Confirmation Input -->
                                <div class="mb-6">
                                    <label :class="[
                                        'block text-sm font-semibold mb-3',
                                        isDark ? 'text-gray-300' : 'text-gray-700'
                                    ]">
                                        {{ t('modals_delete_confirm') }}
                                    </label>
                                    <input v-model="confirmationText" type="text"
                                        :placeholder="`Введите: ${user?.name || ''}`" :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all px-4 py-3',
                                            'text-center font-mono',
                                            isConfirmationValid
                                                ? 'ring-green-500 focus:ring-green-500'
                                                : 'ring-red-500 focus:ring-red-500',
                                            isDark
                                                ? 'bg-gray-700 text-white placeholder-gray-400'
                                                : 'bg-gray-50 text-gray-900 placeholder-gray-500'
                                        ]" :disabled="loading" @keyup.enter="isConfirmationValid && submitDelete()" />
                                    <p :class="[
                                        'text-xs mt-2 text-center',
                                        isConfirmationValid
                                            ? (isDark ? 'text-green-400' : 'text-green-600')
                                            : (isDark ? 'text-red-400' : 'text-red-600')
                                    ]">
                                        {{ isConfirmationValid ? t('modals_delete_correct')
                                            : t('modals_delete_confirm_h') }}
                                    </p>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex space-x-3">
                                    <button type="button" @click="closeModal" :disabled="loading" :class="[
                                        'flex-1 px-4 py-3 rounded-xl font-semibold transition-all',
                                        'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                        loading ? 'opacity-50 cursor-not-allowed' : 'hover:scale-105',
                                        isDark
                                            ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-gray-500 focus:ring-offset-gray-800'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-500'
                                    ]">
                                        {{ t('modals_delete_cancel') }}
                                    </button>

                                    <button type="button" @click="submitDelete"
                                        :disabled="loading || !isConfirmationValid" :class="[
                                            'flex-1 px-4 py-3 rounded-xl font-semibold transition-all',
                                            'focus:outline-none focus:ring-2 focus:ring-offset-2',
                                            'bg-gradient-to-r from-red-600 to-red-700 text-white',
                                            loading || !isConfirmationValid
                                                ? 'opacity-50 cursor-not-allowed'
                                                : 'hover:from-red-700 hover:to-red-800 hover:scale-105 active:scale-95',
                                            isDark ? 'focus:ring-red-500 focus:ring-offset-gray-800' : 'focus:ring-red-500'
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
                                            {{ t('modals_delete_process') }}...
                                        </span>
                                        <span v-else>{{ t('modals_delete_correct') }}</span>
                                    </button>
                                </div>
                            </div>
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
const emit = defineEmits(['close', 'deleted']);

// Form data
const confirmationText = ref('');
const loading = ref(false);

// Computed
const isConfirmationValid = computed(() => {
    return confirmationText.value.trim() === props.user?.name?.trim();
});

// Methods
const closeModal = () => {
    if (!loading.value) {
        resetForm();
        emit('close');
    }
};

const resetForm = () => {
    confirmationText.value = '';
    loading.value = false;
};

const submitDelete = () => {
    if (!isConfirmationValid.value || loading.value || !props.user) return;

    loading.value = true;

    router.delete(`/admin/users/${props.user.id}`, {
        onSuccess: (page) => {
            resetForm();
            emit('deleted');
            emit('close');
        },
        onError: (errors) => {
            console.error('❌ Ошибка при удалении:', errors);
            alert(`❌ Не удалось удалить пользователя "${props.user.name}"`);
        },
        onFinish: () => {
            loading.value = false;
        }
    });
};

// Watch for show prop changes to reset form
watch(() => props.show, (newShow) => {
    if (!newShow) {
        resetForm();
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