<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm transition-opacity"
                        @click="close"></div>
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <div v-if="show"
                            :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                            role="dialog">

                            <!-- Header -->
                            <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div
                                            class="w-8 h-8 bg-gradient-to-br from-violet-500 to-purple-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 10V5a2 2 0 012-2z">
                                                </path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3
                                                :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ t('pm_aliases') }}</h3>
                                            <p :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                                person?.primary }}</p>
                                        </div>
                                    </div>
                                    <button @click="close"
                                        :class="['rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-violet-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="px-6 py-4 space-y-4">

                                <!-- Primary name (readonly) -->
                                <div>
                                    <label
                                        :class="['block text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                            t('pm_primary_name') }}</label>
                                    <div
                                        :class="['px-3 py-2 rounded-lg text-sm font-medium', isDark ? 'bg-gray-700 text-white' : 'bg-gray-100 text-gray-900']">
                                        {{ person?.primary }}
                                    </div>
                                </div>

                                <!-- Current aliases -->
                                <div>
                                    <label
                                        :class="['block text-xs font-medium mb-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ t('pm_aliases_list') }}
                                    </label>

                                    <div v-if="person?.aliases?.length > 0" class="space-y-1">
                                        <div v-for="alias in person.aliases" :key="alias"
                                            :class="['flex items-center justify-between px-3 py-2 rounded-lg group', isDark ? 'bg-gray-700' : 'bg-gray-50']">
                                            <span :class="['text-sm', isDark ? 'text-gray-200' : 'text-gray-800']">{{
                                                alias }}</span>
                                            <button @click="removeAlias(alias)" :disabled="removing === alias"
                                                :class="['p-1 rounded transition-colors opacity-0 group-hover:opacity-100 focus:opacity-100 focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400' : 'hover:bg-red-100 text-red-500']"
                                                :title="t('pm_remove_alias')">
                                                <svg v-if="removing === alias" class="w-3.5 h-3.5 animate-spin"
                                                    fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                        stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <p v-else :class="['text-sm', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('pm_no_aliases') }}</p>
                                </div>

                                <!-- Add alias -->
                                <div>
                                    <label
                                        :class="['block text-xs font-medium mb-2', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                            t('pm_add_alias') }}</label>
                                    <div class="flex gap-2">
                                        <input v-model="newAlias" type="text" :placeholder="t('pm_alias_placeholder')"
                                            @keydown.enter="addAlias"
                                            :class="['flex-1 rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-violet-500 transition-all px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white placeholder-gray-400 ring-gray-600' : 'bg-gray-50 text-gray-900 placeholder-gray-500 ring-gray-300']" />
                                        <button @click="addAlias" :disabled="!newAlias.trim() || adding"
                                            :class="['px-4 py-2 rounded-xl font-medium text-sm transition-all focus:outline-none focus:ring-2 focus:ring-violet-500 disabled:opacity-50 disabled:cursor-not-allowed', isDark ? 'bg-violet-600 hover:bg-violet-700 text-white' : 'bg-violet-600 hover:bg-violet-700 text-white']">
                                            <svg v-if="adding" class="w-4 h-4 animate-spin" fill="none"
                                                viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 4v16m8-8H4"></path>
                                            </svg>
                                        </button>
                                    </div>
                                    <p v-if="aliasError" class="mt-1 text-xs text-red-500">{{ aliasError }}</p>
                                    <p :class="['mt-1 text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">{{
                                        t('pm_alias_hint') }}</p>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div
                                :class="['px-6 py-4 border-t flex justify-end', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button @click="close"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ t('pm_close') }}
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
    // person: { name, primary, aliases, facts }
    // facts[0].id is used as the reference fact_id for alias operations
    person: Object,
    isDark: Boolean,
});
const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const newAlias = ref('');
const aliasError = ref('');
const adding = ref(false);
const removing = ref(null);

// First fact ID of this person — used as reference for alias operations
const refFactId = computed(() => props.person?.facts?.[0]?.id ?? null);

const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

const close = () => {
    show.value = false;
    newAlias.value = '';
    aliasError.value = '';
    document.body.style.overflow = '';
};

const addAlias = () => {
    const alias = newAlias.value.trim();
    if (!alias || !refFactId.value) return;

    aliasError.value = '';
    adding.value = true;

    router.post(route('admin.person-memory.add-alias'), {
        preset_id: props.preset?.id,
        fact_id: refFactId.value,
        alias,
    }, {
        onSuccess: () => {
            newAlias.value = '';
            emit('success');
        },
        onError: (errors) => {
            aliasError.value = Object.values(errors)[0] ?? t('pm_alias_error');
        },
        onFinish: () => { adding.value = false; },
        preserveScroll: true,
    });
};

const removeAlias = (alias) => {
    if (!refFactId.value) return;
    removing.value = alias;

    router.post(route('admin.person-memory.remove-alias'), {
        preset_id: props.preset?.id,
        fact_id: refFactId.value,
        alias,
    }, {
        onSuccess: () => { emit('success'); },
        onFinish: () => { removing.value = null; },
        preserveScroll: true,
    });
};

watch(() => show.value, (v) => {
    if (v) {
        newAlias.value = '';
        aliasError.value = '';
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleEscape);
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleEscape);
    }
});

onUnmounted(() => { document.removeEventListener('keydown', handleEscape); document.body.style.overflow = ''; });
</script>