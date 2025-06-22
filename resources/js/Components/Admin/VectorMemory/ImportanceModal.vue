<template>
    <!-- Modal Overlay with Teleport and higher z-index -->
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <!-- Background overlay with better styling -->
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity" @click="close"
                        aria-hidden="true"></div>

                    <!-- Helper element for centering -->
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                    <!-- Modal panel -->
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <div v-if="show" :class="[
                            'relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle',
                            isDark ? 'bg-gray-800' : 'bg-white'
                        ]" role="dialog" aria-modal="true" aria-labelledby="modal-title">

                            <!-- Header -->
                            <div :class="[
                                'px-6 py-4 border-b',
                                isDark ? 'border-gray-700' : 'border-gray-200'
                            ]">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div
                                            class="w-8 h-8 bg-gradient-to-br from-orange-500 to-red-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z">
                                                </path>
                                            </svg>
                                        </div>
                                        <h3 id="modal-title" :class="[
                                            'text-lg font-semibold',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">
                                            {{ t('vm_adjust_importance') }}
                                        </h3>
                                    </div>
                                    <button @click="close" :class="[
                                        'rounded-lg p-2 transition-colors hover:bg-opacity-75 focus:outline-none focus:ring-2 focus:ring-orange-500',
                                        isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600'
                                    ]" aria-label="Close modal">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Form -->
                            <form @submit.prevent="submit">
                                <div class="px-6 py-4">
                                    <!-- Memory Preview -->
                                    <div v-if="memory" :class="[
                                        'p-4 rounded-xl border mb-4',
                                        isDark ? 'border-gray-600 bg-gray-700 bg-opacity-50' : 'border-gray-300 bg-gray-50'
                                    ]">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <span :class="[
                                                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                                memory.importance >= 2.0 ? (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800') :
                                                memory.importance >= 1.5 ? (isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800') :
                                                (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                            ]">
                                                ★ {{ memory.importance }}
                                            </span>
                                            <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                                {{ memory.vector_size }} {{ t('vm_features') }}
                                            </span>
                                        </div>
                                        <p :class="[
                                            'text-sm leading-relaxed',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">{{ truncateContent(memory.content, 150) }}</p>
                                    </div>

                                    <!-- Importance Slider -->
                                    <div class="mb-6">
                                        <label :class="[
                                            'block text-sm font-medium mb-3',
                                            isDark ? 'text-gray-300' : 'text-gray-700'
                                        ]">
                                            {{ t('vm_importance_level') }}
                                        </label>
                                        
                                        <!-- Current value display -->
                                        <div class="flex items-center justify-center mb-4">
                                            <div :class="[
                                                'text-3xl font-bold px-4 py-2 rounded-xl',
                                                form.importance >= 2.0 ? (isDark ? 'text-red-400 bg-red-900 bg-opacity-30' : 'text-red-600 bg-red-100') :
                                                form.importance >= 1.5 ? (isDark ? 'text-yellow-400 bg-yellow-900 bg-opacity-30' : 'text-yellow-600 bg-yellow-100') :
                                                (isDark ? 'text-green-400 bg-green-900 bg-opacity-30' : 'text-green-600 bg-green-100')
                                            ]">
                                                ★ {{ form.importance }}
                                            </div>
                                        </div>

                                        <!-- Slider -->
                                        <div class="relative">
                                            <input 
                                                v-model.number="form.importance" 
                                                type="range" 
                                                min="0.1" 
                                                max="5.0" 
                                                step="0.1"
                                                :class="[
                                                    'w-full h-2 rounded-lg appearance-none cursor-pointer',
                                                    isDark ? 'bg-gray-700' : 'bg-gray-200'
                                                ]"
                                                :style="{ background: sliderBackground }"
                                            />
                                            
                                            <!-- Slider labels -->
                                            <div class="flex justify-between text-xs mt-2" :class="isDark ? 'text-gray-400' : 'text-gray-600'">
                                                <span>0.1</span>
                                                <span>1.0</span>
                                                <span>2.0</span>
                                                <span>3.0</span>
                                                <span>5.0</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Importance levels explanation -->
                                    <div :class="[
                                        'space-y-2 text-xs',
                                        isDark ? 'text-gray-400' : 'text-gray-600'
                                    ]">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                                            <span><strong>0.1 - 1.4:</strong> {{ t('vm_low_importance') }}</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                                            <span><strong>1.5 - 1.9:</strong> {{ t('vm_medium_importance') }}</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                                            <span><strong>2.0 - 5.0:</strong> {{ t('vm_high_importance') }}</span>
                                        </div>
                                    </div>

                                    <!-- Error display -->
                                    <div v-if="form.errors.importance" class="text-red-500 text-xs mt-2">
                                        {{ form.errors.importance }}
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div :class="[
                                    'px-6 py-4 border-t flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-3 space-y-3 space-y-reverse sm:space-y-0',
                                    isDark ? 'border-gray-700' : 'border-gray-200'
                                ]">
                                    <button type="button" @click="close" :class="[
                                        'w-full sm:w-auto px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                        isDark
                                            ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                    ]">
                                        {{ t('vm_cancel') }}
                                    </button>
                                    <button type="submit" :disabled="form.processing" :class="[
                                        'w-full sm:w-auto px-6 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2',
                                        'disabled:opacity-50 disabled:cursor-not-allowed',
                                        isDark
                                            ? 'bg-orange-600 hover:bg-orange-700 text-white focus:ring-offset-gray-800'
                                            : 'bg-orange-600 hover:bg-orange-700 text-white'
                                    ]">
                                        <span v-if="form.processing" class="flex items-center justify-center">
                                            <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            {{ t('vm_updating') }}...
                                        </span>
                                        <span v-else>{{ t('vm_update_importance') }}</span>
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
import { ref, computed, watch, onUnmounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    memory: Object,
    preset: Object,
});

const emit = defineEmits(['update:modelValue', 'success']);

const isDark = ref(false);

const show = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const form = useForm({
    preset_id: null,
    importance: 1.0,
});

// Computed property for slider background gradient
const sliderBackground = computed(() => {
    const percentage = ((form.importance - 0.1) / 4.9) * 100;
    if (isDark.value) {
        return `linear-gradient(to right, #10b981 0%, #f59e0b ${percentage}%, #374151 ${percentage}%, #374151 100%)`;
    } else {
        return `linear-gradient(to right, #10b981 0%, #f59e0b ${percentage}%, #e5e7eb ${percentage}%, #e5e7eb 100%)`;
    }
});

/**
 * Handle escape key to close modal
 */
const handleEscape = (event) => {
    if (event.key === 'Escape' && show.value) {
        close();
    }
};

/**
 * Prevent body scroll when modal is open
 */
const toggleBodyScroll = (disable) => {
    if (disable) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
};

const close = () => {
    show.value = false;
    form.reset();
    form.clearErrors();
    toggleBodyScroll(false);
};

const submit = () => {
    form.preset_id = props.preset?.id;

    form.put(route('admin.vector-memory.update-importance', props.memory?.id), {
        onSuccess: () => {
            close();
            emit('success');
        }
    });
};

const truncateContent = (content, length) => {
    if (!content) return '';
    if (content.length <= length) return content;
    return content.substring(0, length) + '...';
};

// Watch for memory changes
watch(() => props.memory, (newMemory) => {
    if (newMemory) {
        form.importance = newMemory.importance || 1.0;
        form.preset_id = props.preset?.id;
    }
}, { immediate: true });

// Watch modal state changes
watch(() => show.value, (isVisible) => {
    if (isVisible) {
        const savedTheme = localStorage.getItem('chat-theme');
        isDark.value = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
        toggleBodyScroll(true);

        // Focus management and escape key handling
        document.addEventListener('keydown', handleEscape);
    } else {
        toggleBodyScroll(false);
        document.removeEventListener('keydown', handleEscape);
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    toggleBodyScroll(false);
});
</script>

<style scoped>
/* Custom slider styling */
input[type="range"]::-webkit-slider-thumb {
    appearance: none;
    height: 20px;
    width: 20px;
    border-radius: 50%;
    background: #f97316;
    cursor: pointer;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

input[type="range"]::-moz-range-thumb {
    height: 20px;
    width: 20px;
    border-radius: 50%;
    background: #f97316;
    cursor: pointer;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}
</style>