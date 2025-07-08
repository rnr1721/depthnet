<template>
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto" @click.self="closeModal">
        <div class="flex items-center justify-center min-h-full p-4 text-center">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"></div>

            <!-- Modal panel -->
            <div :class="[
                'relative transform overflow-hidden rounded-xl shadow-xl transition-all sm:max-w-lg sm:w-full',
                'p-6 text-left',
                isDark ? 'bg-gray-800 text-white' : 'bg-white text-gray-900'
            ]">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <h3 :class="[
                        'text-lg font-semibold',
                        isDark ? 'text-white' : 'text-gray-900'
                    ]">
                        {{ t('chat_clear_history') }}
                    </h3>
                    <button @click="closeModal" :class="[
                        'p-2 rounded-lg transition-colors',
                        isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600'
                    ]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Content -->
                <div class="mb-6">
                    <p :class="[
                        'text-sm mb-4',
                        isDark ? 'text-gray-300' : 'text-gray-600'
                    ]">
                        {{ t('chat_clear_history_description') || 'Select what you want to clear:' }}
                    </p>

                    <!-- Options -->
                    <div class="space-y-3">
                        <!-- Clear messages -->
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" v-model="clearMessages" :class="[
                                'w-4 h-4 rounded border-2 transition-colors',
                                'focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                isDark
                                    ? 'bg-gray-700 border-gray-600 text-indigo-600 focus:ring-offset-gray-800'
                                    : 'bg-white border-gray-300 text-indigo-600'
                            ]">
                            <span :class="[
                                'text-sm font-medium',
                                isDark ? 'text-gray-200' : 'text-gray-700'
                            ]">
                                {{ t('chat_clear_messages') || 'Clear chat messages' }}
                            </span>
                        </label>

                        <!-- Clear memory -->
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" v-model="clearMemory" :class="[
                                'w-4 h-4 rounded border-2 transition-colors',
                                'focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                isDark
                                    ? 'bg-gray-700 border-gray-600 text-indigo-600 focus:ring-offset-gray-800'
                                    : 'bg-white border-gray-300 text-indigo-600'
                            ]">
                            <span :class="[
                                'text-sm font-medium',
                                isDark ? 'text-gray-200' : 'text-gray-700'
                            ]">
                                {{ t('chat_clear_memory') || 'Clear memory' }}
                            </span>
                        </label>

                        <!-- Clear vector memory -->
                        <label class="flex items-center space-x-3 cursor-pointer">
                            <input type="checkbox" v-model="clearVectorMemory" :class="[
                                'w-4 h-4 rounded border-2 transition-colors',
                                'focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                isDark
                                    ? 'bg-gray-700 border-gray-600 text-indigo-600 focus:ring-offset-gray-800'
                                    : 'bg-white border-gray-300 text-indigo-600'
                            ]">
                            <span :class="[
                                'text-sm font-medium',
                                isDark ? 'text-gray-200' : 'text-gray-700'
                            ]">
                                {{ t('chat_clear_vector_memory') || 'Clear vector memory' }}
                            </span>
                        </label>
                    </div>

                    <!-- Warning -->
                    <div v-if="hasAnySelected" :class="[
                        'mt-4 p-3 rounded-lg border',
                        isDark ? 'bg-red-900 bg-opacity-20 border-red-700' : 'bg-red-50 border-red-200'
                    ]">
                        <div class="flex items-start space-x-2">
                            <svg class="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 15c-.77.833.192 2.5 1.732 2.5z">
                                </path>
                            </svg>
                            <p :class="[
                                'text-xs',
                                isDark ? 'text-red-300' : 'text-red-700'
                            ]">
                                {{ t('chat_clear_warning') || 'This action cannot be undone.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-3">
                    <button @click="closeModal" :class="[
                        'px-4 py-2 rounded-lg font-medium transition-colors',
                        isDark
                            ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                            : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100'
                    ]">
                        {{ t('chat_cancel') || 'Cancel' }}
                    </button>

                    <button @click="confirmClear" :disabled="!hasAnySelected || isClearing" :class="[
                        'px-4 py-2 rounded-lg font-medium transition-all',
                        'disabled:opacity-50 disabled:cursor-not-allowed',
                        'focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2',
                        hasAnySelected && !isClearing
                            ? 'bg-red-600 hover:bg-red-700 text-white'
                            : 'bg-gray-400 text-gray-200',
                        isDark ? 'focus:ring-offset-gray-800' : ''
                    ]">
                        <span v-if="isClearing" class="flex items-center space-x-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <span>{{ t('chat_clearing') || 'Clearing...' }}</span>
                        </span>
                        <span v-else>
                            {{ t('chat_clear_selected') || 'Clear Selected' }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    show: Boolean,
    isDark: Boolean,
    isClearing: Boolean
});

const emit = defineEmits(['close', 'confirm']);

const clearMessages = ref(true);
const clearMemory = ref(true);
const clearVectorMemory = ref(true);

const hasAnySelected = computed(() =>
    clearMessages.value || clearMemory.value || clearVectorMemory.value
);

/**
 * Close modal and reset state
 */
function closeModal() {
    emit('close');
    // Reset to default state
    setTimeout(() => {
        clearMessages.value = true;
        clearMemory.value = true;
        clearVectorMemory.value = true;
    }, 300);
}

/**
 * Confirm clearing with selected options
 */
function confirmClear() {
    if (!hasAnySelected.value) return;

    emit('confirm', {
        clearMessages: clearMessages.value,
        clearMemory: clearMemory.value,
        clearVectorMemory: clearVectorMemory.value
    });
}

// ESC key
function handleKeydown(event) {
    if (event.key === 'Escape' && props.show) {
        closeModal();
    }
}

watch(() => props.show, (newValue) => {
    if (newValue) {
        document.addEventListener('keydown', handleKeydown);
    } else {
        document.removeEventListener('keydown', handleKeydown);
    }
});
</script>
