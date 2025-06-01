<template>
    <div class="flex flex-col h-full">
        <!-- Header -->
        <div :class="[
            'p-4 border-b flex-shrink-0',
            isDark ? 'border-gray-600 bg-gray-700' : 'border-gray-200 bg-gray-50'
        ]">
            <div class="flex items-center space-x-2">
                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                    <circle cx="10" cy="10" r="3"></circle>
                </svg>
                <h3 :class="[
                    'font-semibold text-sm',
                    isDark ? 'text-gray-200' : 'text-gray-800'
                ]">
                    {{ t('chat_users') || 'Users Available' }}
                </h3>
                <span :class="[
                    'text-xs px-2 py-1 rounded-full',
                    isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-200 text-gray-600'
                ]">
                    {{ users.length }}
                </span>
            </div>
        </div>

        <!-- Users list -->
        <div class="flex-1 overflow-y-auto p-3 space-y-2">
            <button v-for="user in users" :key="user.id" @click="$emit('mentionUser', user.name)" :class="[
                'w-full text-left p-3 rounded-lg transition-all duration-200 group',
                'hover:scale-105 transform',
                isDark
                    ? 'hover:bg-gray-600 active:bg-gray-500'
                    : 'hover:bg-gray-100 active:bg-gray-200'
            ]">
                <div class="flex items-center space-x-3">
                    <!-- Avatar/Status -->
                    <div class="relative">
                        <div :class="[
                            'w-8 h-8 rounded-full flex items-center justify-center font-medium text-sm',
                            user.is_admin
                                ? 'bg-gradient-to-br from-purple-500 to-indigo-600 text-white'
                                : 'bg-gradient-to-br from-blue-500 to-cyan-600 text-white'
                        ]">
                            {{ user.name.charAt(0).toUpperCase() }}
                        </div>
                        <!-- Online indicator -->
                        <!--
              <div class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 border-2 border-current rounded-full" 
                   :class="isDark ? 'border-gray-800' : 'border-white'">
              </div>
                -->
                    </div>

                    <!-- User info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center space-x-2">
                            <span :class="[
                                'font-medium text-sm truncate',
                                isDark ? 'text-gray-200' : 'text-gray-900'
                            ]">
                                {{ user.name }}
                            </span>
                            <span v-if="user.is_admin" :class="[
                                'text-xs px-2 py-0.5 rounded-full font-medium',
                                'bg-gradient-to-r from-purple-500 to-indigo-600 text-white'
                            ]">
                                {{ t('chat_admin') }}
                            </span>
                        </div>
                        <!-- Optional: Last seen or status -->
                        <div :class="[
                            'text-xs mt-0.5 opacity-75',
                            isDark ? 'text-gray-400' : 'text-gray-500'
                        ]">
                        </div>
                    </div>

                    <!-- Hover indicator -->
                    <div :class="[
                        'opacity-0 group-hover:opacity-100 transition-opacity',
                        'w-5 h-5 rounded-full flex items-center justify-center',
                        isDark ? 'bg-gray-500' : 'bg-gray-300'
                    ]">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                    </div>
                </div>
            </button>
        </div>

        <!-- Footer -->
        <div :class="[
            'p-4 text-center',
            isDark ? 'text-gray-400' : 'text-gray-500'
        ]">
            <div class="text-2xl mb-2"></div>
            <p class="text-sm"><a target="_blank" href="https://github.com/rnr1721/depthnet">{{ t('project_home') }}</a>
            </p>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    users: {
        type: Array,
        required: true
    },
    isDark: {
        type: Boolean,
        required: true
    }
});

defineEmits(['mentionUser']);
</script>

<style scoped>
/* Custom scrollbar for users list */
.overflow-y-auto::-webkit-scrollbar {
    width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background-color: rgba(156, 163, 175, 0.5);
    border-radius: 2px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background-color: rgba(156, 163, 175, 0.8);
}
</style>