<template>
    <PageTitle :title="t('settings')" />
    <div :class="[
        'min-h-screen transition-colors duration-300',
        isDark ? 'bg-gray-900' : 'bg-gray-50'
    ]">
        <AdminHeader :title="t('settings_system_settings')" :isAdmin="true" />

        <!-- Main content -->
        <main class="relative">
            <!-- Background decoration -->
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div :class="[
                    'absolute -top-40 -right-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-indigo-500' : 'bg-indigo-300'
                ]"></div>
                <div :class="[
                    'absolute -bottom-40 -left-40 w-80 h-80 rounded-full opacity-10 blur-3xl',
                    isDark ? 'bg-purple-500' : 'bg-purple-300'
                ]"></div>
            </div>

            <div class="relative max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <!-- Success/Error messages -->
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

                <Transition enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                    <div v-if="$page.props.flash.error" :class="[
                        'mb-6 p-4 rounded-xl border-l-4 backdrop-blur-sm',
                        isDark
                            ? 'bg-red-900 bg-opacity-50 border-red-400 text-red-200'
                            : 'bg-red-50 border-red-400 text-red-800'
                    ]">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ $page.props.flash.error }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Settings Groups -->
                <div v-for="(group, groupKey) in settings" :key="groupKey" :class="[
                    'mb-8 backdrop-blur-sm border shadow-xl rounded-2xl overflow-hidden transition-all',
                    isDark
                        ? 'bg-gray-800 bg-opacity-90 border-gray-700'
                        : 'bg-white bg-opacity-90 border-gray-200'
                ]">
                    <!-- Group Header -->
                    <div :class="[
                        'px-6 py-6 border-b',
                        isDark ? 'border-gray-700' : 'border-gray-200'
                    ]">
                        <div class="flex items-center space-x-4">
                            <div
                                class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        :d="group.config.icon_svg"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 :class="[
                                    'text-xl font-bold',
                                    isDark ? 'text-white' : 'text-gray-900'
                                ]">{{ t(group.config.label) }}</h2>
                                <p :class="[
                                    'text-sm mt-1',
                                    isDark ? 'text-gray-400' : 'text-gray-600'
                                ]">{{ t(group.config.description) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Group Fields -->
                    <div class="p-6 sm:p-8">
                        <form @submit.prevent="submit" class="space-y-8">
                            <!-- Field -->
                            <div v-for="field in group.fields" :key="field.key" class="space-y-4">
                                <div class="flex items-start space-x-3">
                                    <div v-if="field.config.icon_svg"
                                        class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center flex-shrink-0 mt-1">
                                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                :d="field.config.icon_svg"></path>
                                        </svg>
                                    </div>
                                    <div :class="field.config.icon_svg ? 'flex-1' : 'flex-1 ml-0'">
                                        <label :for="field.key" :class="[
                                            'block text-lg font-semibold mb-2',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">
                                            {{ t(field.config.label) }}
                                        </label>
                                        <p :class="[
                                            'text-sm mb-4 leading-relaxed',
                                            isDark ? 'text-gray-400' : 'text-gray-600'
                                        ]">
                                            {{ t(field.config.description) }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Textarea Field -->
                                <div v-if="field.config.type === 'textarea'" class="relative">
                                    <textarea :id="field.key" v-model="form[field.key]" :rows="field.config.rows || 6"
                                        :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all resize-none',
                                            'px-4 py-3 text-sm leading-relaxed',
                                            field.config.font_family === 'mono' ? 'font-mono' : '',
                                            form.errors[field.key]
                                                ? 'ring-red-500 focus:ring-red-500'
                                                : 'ring-gray-300 focus:ring-indigo-500',
                                            isDark
                                                ? (form.errors[field.key]
                                                    ? 'bg-red-900 bg-opacity-20 text-white placeholder-gray-400'
                                                    : 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600')
                                                : (form.errors[field.key]
                                                    ? 'bg-red-50 text-gray-900 placeholder-gray-500'
                                                    : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                        ]" :placeholder="t(field.config.placeholder || '')">
                                    </textarea>
                                    <div class="flex justify-between items-center mt-2">
                                        <div v-if="form.errors[field.key]" class="text-red-500 text-xs">
                                            {{ form.errors[field.key] }}
                                        </div>
                                        <div class="text-xs opacity-50 ml-auto">
                                            {{ form[field.key]?.length || 0 }} {{ t('settings_symbols') }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Input Field -->
                                <div v-else-if="field.config.type === 'input'" class="relative">
                                    <input :id="field.key" v-model="form[field.key]"
                                        :type="field.config.input_type || 'text'" :min="field.config.min"
                                        :max="field.config.max" :step="field.config.step" :class="[
                                            'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 transition-all',
                                            'px-4 py-3 text-sm',
                                            form.errors[field.key]
                                                ? 'ring-red-500 focus:ring-red-500'
                                                : 'ring-gray-300 focus:ring-indigo-500',
                                            isDark
                                                ? (form.errors[field.key]
                                                    ? 'bg-red-900 bg-opacity-20 text-white placeholder-gray-400'
                                                    : 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600')
                                                : (form.errors[field.key]
                                                    ? 'bg-red-50 text-gray-900 placeholder-gray-500'
                                                    : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300')
                                        ]" :placeholder="t(field.config.placeholder || '')" />
                                    <div v-if="form.errors[field.key]" class="text-red-500 text-xs mt-1">
                                        {{ form.errors[field.key] }}
                                    </div>
                                </div>

                                <!-- Select Field -->
                                <div v-else-if="field.config.type === 'select'" class="relative">
                                    <select :id="field.key" v-model="form[field.key]" :class="[
                                        'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all',
                                        'px-4 py-3 text-sm',
                                        isDark
                                            ? 'bg-gray-700 text-white ring-gray-600'
                                            : 'bg-gray-50 text-gray-900 ring-gray-300'
                                    ]">
                                        <option v-for="option in field.config.processed_options" :key="option.value"
                                            :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Checkbox Field -->
                                <div v-else-if="field.config.type === 'checkbox'" class="relative">
                                    <label :class="[
                                        'flex items-center space-x-3 cursor-pointer p-4 rounded-xl border transition-all',
                                        isDark
                                            ? 'border-gray-600 hover:border-gray-500'
                                            : 'border-gray-300 hover:border-gray-400'
                                    ]">
                                        <input :id="field.key" v-model="form[field.key]" type="checkbox" :class="[
                                            'w-5 h-5 rounded border-2 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                            'text-indigo-600 transition-colors',
                                            isDark ? 'focus:ring-offset-gray-800' : ''
                                        ]" />
                                        <span :class="[
                                            'text-sm font-medium',
                                            isDark ? 'text-white' : 'text-gray-900'
                                        ]">
                                            {{ t(field.config.label) }}
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <!-- Action buttons for each group -->
                            <div class="flex flex-col sm:flex-row sm:justify-end space-y-3 sm:space-y-0 sm:space-x-4 pt-6 border-t"
                                :class="isDark ? 'border-gray-700' : 'border-gray-200'">
                                <button type="button" @click="resetForm" :class="[
                                    'px-6 py-3 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2',
                                    isDark
                                        ? 'bg-gray-700 text-gray-300 hover:bg-gray-600 focus:ring-offset-gray-800'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]">
                                    {{ t('settings_reset') }}
                                </button>
                                <button type="submit" :disabled="form.processing" :class="[
                                    'px-8 py-3 rounded-xl font-medium transition-all transform focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                    'disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none',
                                    'enabled:hover:scale-105 enabled:active:scale-95',
                                    isDark
                                        ? 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-offset-gray-800'
                                        : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                                ]">
                                    <span v-if="form.processing" class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        {{ t('settings_saving') }}...
                                    </span>
                                    <span v-else class="flex items-center">
                                        {{ t('settings_save_settings') }}
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Additional info card -->
                <div :class="[
                    'mt-8 p-6 rounded-2xl border backdrop-blur-sm',
                    isDark
                        ? 'bg-gray-800 bg-opacity-50 border-gray-700'
                        : 'bg-white bg-opacity-50 border-gray-200'
                ]">
                    <div class="flex items-start space-x-3">
                        <div
                            class="w-8 h-8 bg-gradient-to-br from-amber-500 to-orange-600 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 :class="[
                                'font-semibold mb-2',
                                isDark ? 'text-white' : 'text-gray-900'
                            ]">{{ t('settings_tips') }}</h3>
                            <ul :class="[
                                'text-sm space-y-1',
                                isDark ? 'text-gray-400' : 'text-gray-600'
                            ]">
                                <li>• {{ t('settings_tip1') }}</li>
                                <li>• {{ t('settings_tip2') }}</li>
                                <li>• {{ t('settings_tip3') }}</li>
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
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminHeader from '@/Components/AdminHeader.vue';
import PageTitle from '@/Components/PageTitle.vue';

const { t } = useI18n();

const isDark = ref(false);

const props = defineProps({
    settings: Object
});

const formData = {};
Object.values(props.settings).forEach(group => {
    group.fields.forEach(field => {
        formData[field.key] = field.value;
    });
});

const form = useForm(formData);

const originalData = { ...formData };

const submit = () => {
    form.post(route('admin.save-options'));
};

const resetForm = () => {
    Object.keys(originalData).forEach(key => {
        form[key] = originalData[key];
    });
};

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    }
    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>