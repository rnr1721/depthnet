<template>
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div :class="[
            'w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl',
            isDark ? 'bg-gray-800' : 'bg-white'
        ]" @click.stop>
            <!-- Header -->
            <div :class="[
                'sticky top-0 px-6 py-4 border-b flex items-center justify-between backdrop-blur-sm',
                isDark ? 'bg-gray-800 bg-opacity-95 border-gray-700' : 'bg-white bg-opacity-95 border-gray-200'
            ]">
                <div class="flex items-center space-x-4">
                    <div
                        class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center">
                        <span class="text-white font-bold text-xl">{{ appName.charAt(0).toUpperCase() }}</span>
                    </div>
                    <div>
                        <h2 :class="['text-2xl font-bold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ appName }}
                        </h2>
                        <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                            {{ credits.tagline }}
                        </p>
                    </div>
                </div>
                <button @click="$emit('close')" :class="[
                    'p-2 rounded-xl transition-all hover:scale-105',
                    isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600'
                ]">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Content -->
            <div class="p-6 space-y-8">
                <!-- Version & Description -->
                <div class="text-center">
                    <div :class="[
                        'inline-flex items-center space-x-2 px-4 py-2 rounded-full text-sm font-medium mb-4',
                        isDark ? 'bg-indigo-900 bg-opacity-50 text-indigo-200' : 'bg-indigo-100 text-indigo-800'
                    ]">
                        <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                        <span>{{ credits.release }}</span>
                    </div>
                    <p
                        :class="['text-lg leading-relaxed max-w-3xl mx-auto', isDark ? 'text-gray-300' : 'text-gray-700']">
                        {{ credits.mission }}
                    </p>
                </div>

                <!-- Features Grid -->
                <div>
                    <h3 :class="['text-xl font-bold mb-6 text-center', isDark ? 'text-white' : 'text-gray-900']">
                        Core Features
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="feature in credits.features" :key="feature.name" :class="[
                            'p-4 rounded-xl border transition-all hover:scale-105',
                            isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
                        ]">
                            <h4 :class="['font-semibold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ feature.name }}
                            </h4>
                            <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                {{ feature.description }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Technology Stack -->
                <div>
                    <h3 :class="['text-xl font-bold mb-6 text-center', isDark ? 'text-white' : 'text-gray-900']">
                        Technology Stack
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div v-for="(stack, category) in credits.technology" :key="category">
                            <h4 :class="['font-semibold mb-3 capitalize', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ category.replace('_', ' ') }}
                            </h4>
                            <div class="space-y-2">
                                <span v-for="tech in stack" :key="tech" :class="[
                                    'inline-block text-xs px-3 py-1 rounded-full mr-2 mb-2',
                                    isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-200 text-gray-700'
                                ]">
                                    {{ tech }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contributors -->
                <div>
                    <h3 :class="['text-xl font-bold mb-6 text-center', isDark ? 'text-white' : 'text-gray-900']">
                        Contributors
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div v-for="contributor in credits.contributors" :key="contributor.name" :class="[
                            'p-6 rounded-xl border',
                            isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200'
                        ]">
                            <div class="flex items-start space-x-4">
                                <div class="flex-1">
                                    <h4 :class="['font-bold', isDark ? 'text-white' : 'text-gray-900']">
                                        {{ contributor.name }}
                                    </h4>
                                    <p :class="['text-sm mb-3', isDark ? 'text-indigo-400' : 'text-indigo-600']">
                                        {{ contributor.role }}
                                    </p>
                                    <ul class="space-y-1">
                                        <li v-for="contribution in contributor.contributions" :key="contribution"
                                            :class="['text-sm flex items-center space-x-2', isDark ? 'text-gray-400' : 'text-gray-600']">
                                            <span class="w-1 h-1 bg-current rounded-full"></span>
                                            <span>{{ contribution }}</span>
                                        </li>
                                    </ul>
                                    <a v-if="contributor.github" :href="`https://github.com/${contributor.github}`"
                                        target="_blank" :class="[
                                            'inline-flex items-center space-x-1 text-sm mt-3 hover:underline',
                                            isDark ? 'text-blue-400' : 'text-blue-600'
                                        ]">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                            <path
                                                d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                                        </svg>
                                        <span>@{{ contributor.github }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Special Thanks -->
                <div v-if="credits.special_thanks?.length">
                    <h3 :class="['text-xl font-bold mb-6 text-center', isDark ? 'text-white' : 'text-gray-900']">
                        Special Thanks
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div v-for="thanks in credits.special_thanks" :key="thanks.name" :class="[
                            'p-4 rounded-xl border text-center',
                            isDark ? 'bg-gray-700 bg-opacity-30 border-gray-600' : 'bg-gray-50 border-gray-200'
                        ]">
                            <h4 :class="['font-semibold mb-2', isDark ? 'text-white' : 'text-gray-900']">
                                {{ thanks.name }}
                            </h4>
                            <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                                {{ thanks.description }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Philosophy -->
                <div :class="[
                    'p-6 rounded-xl border-l-4',
                    isDark ? 'bg-yellow-900 bg-opacity-20 border-yellow-400' : 'bg-yellow-50 border-yellow-400'
                ]">
                    <h3 :class="['font-bold mb-2', isDark ? 'text-yellow-300' : 'text-yellow-800']">
                        {{ credits.philosophy.title }}
                    </h3>
                    <p :class="['text-sm mb-2', isDark ? 'text-yellow-200' : 'text-yellow-700']">
                        {{ credits.philosophy.description }}
                    </p>
                    <p :class="['text-xs font-medium', isDark ? 'text-yellow-400' : 'text-yellow-600']">
                        {{ credits.philosophy.warning }}
                    </p>
                </div>

                <!-- Links & Actions -->
                <div class="flex flex-wrap gap-4 justify-center">
                    <a v-for="(url, name) in credits.links" :key="name" :href="url" target="_blank" :class="[
                        'inline-flex items-center space-x-2 px-6 py-3 rounded-xl font-medium transition-all transform hover:scale-105',
                        name === 'github'
                            ? (isDark ? 'bg-gray-700 text-white hover:bg-gray-600' : 'bg-gray-900 text-white hover:bg-gray-800')
                            : (isDark ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-indigo-600 text-white hover:bg-indigo-700')
                    ]">
                        <svg v-if="name === 'github'" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path
                                d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                        </svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        <span class="capitalize">{{ name.replace('_', ' ') }}</span>
                    </a>
                </div>

                <!-- Footer -->
                <div class="text-center border-t pt-6" :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                    <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-600']">
                        {{ credits.copyright }}
                    </p>
                    <p :class="['text-xs mt-2', isDark ? 'text-gray-500' : 'text-gray-500']">
                        Licensed under {{ credits.license }} • Built with ❤️ for humanity
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

defineProps({
    isDark: {
        type: Boolean,
        required: true
    },
    appName: {
        type: String,
        required: true
    }
});

defineEmits(['close']);

import creditsData from '@/../../resources/data/credits.json';

const credits = ref(creditsData);

</script>

<style scoped>
.overflow-y-auto::-webkit-scrollbar {
    width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background-color: rgba(156, 163, 175, 0.5);
    border-radius: 3px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background-color: rgba(156, 163, 175, 0.8);
}
</style>
