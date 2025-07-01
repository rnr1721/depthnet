<template>
    <div :class="[
        'lg:hidden p-4 border-b flex items-center justify-between flex-shrink-0',
        isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
    ]">
        <button @click="$emit('openMenu')" :class="[
            'p-2 rounded-md transition-colors',
            isDark ? 'hover:bg-gray-700 text-gray-300' : 'hover:bg-gray-100 text-gray-600'
        ]">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16">
                </path>
            </svg>
        </button>

        <span :class="[
            'font-bold text-lg',
            isDark ? 'text-white' : 'text-gray-900'
        ]">{{ appName }}</span>

        <button @click="$emit('openUsers')" :class="[
            'p-2 rounded-md transition-colors',
            isDark ? 'hover:bg-gray-700 text-gray-300' : 'hover:bg-gray-100 text-gray-600'
        ]">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
        </button>
    </div>
</template>

<script setup>
defineProps({
    isDark: Boolean,
    appName: String
});

defineEmits(['openMenu', 'openUsers']);
</script>
