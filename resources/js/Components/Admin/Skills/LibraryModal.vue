<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="close"></div>
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                    <div :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-3xl sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                        role="dialog">

                        <!-- Header -->
                        <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                            <div class="flex items-center justify-between">
                                <div class="min-w-0">
                                    <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                        📄 {{ t('sk_library_title') }}</h3>
                                    <p
                                        :class="['text-xs mt-0.5 font-mono truncate', isDark ? 'text-gray-400' : 'text-gray-500']">
                                        {{ root }}</p>
                                </div>
                                <div class="flex items-center space-x-2 ml-4 flex-shrink-0">
                                    <button v-if="hasStale" @click="refreshAll" :disabled="busy"
                                        :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-50', isDark ? 'bg-amber-700 hover:bg-amber-600 text-white' : 'bg-amber-500 hover:bg-amber-600 text-white']">
                                        {{ t('sk_library_refresh_all') }}</button>
                                    <button @click="close"
                                        :class="['rounded-lg p-2 transition-colors', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Body -->
                        <div class="max-h-[60vh] overflow-y-auto">
                            <div v-if="loading"
                                :class="['px-6 py-12 text-center text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('sk_loading') }}</div>

                            <div v-else-if="!skills.length && !orphans.length"
                                :class="['px-6 py-10 text-center text-sm space-y-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                <p>{{ t('sk_library_empty') }}</p>
                                <p class="font-mono text-xs">{{ root }}/&lt;name&gt;/SKILL.md</p>
                            </div>

                            <div v-else class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                                <div v-for="s in skills" :key="s.name" class="px-6 py-4">
                                    <div class="flex items-start justify-between space-x-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span
                                                    :class="['font-mono font-semibold', isDark ? 'text-white' : 'text-gray-900']">{{
                                                        s.name }}</span>
                                                <span v-if="s.attached"
                                                    :class="['text-xs px-2 py-0.5 rounded font-mono', isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-800']">
                                                    #{{ s.attached }}</span>
                                                <span v-if="s.stale"
                                                    :class="['text-xs px-2 py-0.5 rounded', isDark ? 'bg-amber-900 text-amber-300' : 'bg-amber-100 text-amber-800']">
                                                    {{ t('sk_library_changed') }}</span>
                                                <span v-if="s.has_scripts"
                                                    :class="['text-xs px-2 py-0.5 rounded', isDark ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-600']">
                                                    ⚡ scripts</span>
                                                <span :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
                                                    {{ s.file_count }} {{ t('sk_library_files') }}</span>
                                            </div>
                                            <p :class="['text-xs mt-1 line-clamp-2', isDark ? 'text-gray-400' : 'text-gray-600']"
                                                :title="s.description">{{ s.description }}</p>
                                            <ul v-if="s.errors?.length" class="mt-1 space-y-0.5">
                                                <li v-for="(e, i) in s.errors" :key="'e' + i"
                                                    class="text-xs text-red-500">✖ {{ e }}</li>
                                            </ul>
                                            <ul v-if="s.warnings?.length" class="mt-1 space-y-0.5">
                                                <li v-for="(w, i) in s.warnings" :key="'w' + i"
                                                    :class="['text-xs', isDark ? 'text-amber-400' : 'text-amber-700']">⚠
                                                    {{ w }}</li>
                                            </ul>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <button v-if="!s.attached" @click="attach(s.name)"
                                                :disabled="busy || !s.valid"
                                                :title="!s.valid ? t('sk_library_invalid') : ''"
                                                :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-40 disabled:cursor-not-allowed', isDark ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white']">
                                                {{ t('sk_library_connect') }}</button>
                                            <button v-else-if="s.stale" @click="refresh(s.attached)" :disabled="busy"
                                                :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-50', isDark ? 'bg-amber-700 hover:bg-amber-600 text-white' : 'bg-amber-500 hover:bg-amber-600 text-white']">
                                                {{ t('sk_library_refresh') }}</button>
                                            <span v-else
                                                :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
                                                ✓ {{ t('sk_library_connected') }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Guest skills whose folder disappeared -->
                                <div v-if="orphans.length" class="px-6 py-4">
                                    <p
                                        :class="['text-xs font-medium mb-1', isDark ? 'text-gray-400' : 'text-gray-600']">
                                        {{ t('sk_library_orphans') }}</p>
                                    <p v-for="o in orphans" :key="o.number"
                                        :class="['text-xs font-mono', isDark ? 'text-gray-500' : 'text-gray-500']">
                                        #{{ o.number }} {{ o.name }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div
                            :class="['px-6 py-3 border-t text-xs', isDark ? 'border-gray-700 text-gray-500' : 'border-gray-200 text-gray-400']">
                            {{ t('sk_library_hint') }}
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
    isDark: Boolean,
});
const emit = defineEmits(['update:modelValue']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const loading = ref(false);
const busy = ref(false);
const root = ref('');
const skills = ref([]);
const orphans = ref([]);

const hasStale = computed(() => skills.value.some((s) => s.stale));

const load = async () => {
    if (!props.preset) return;
    loading.value = true;
    try {
        const res = await axios.get(route('admin.skills.library'), { params: { preset_id: props.preset.id } });
        root.value = res.data.root ?? '';
        skills.value = res.data.skills ?? [];
        orphans.value = res.data.orphans ?? [];
    } catch (e) {
        skills.value = [];
        orphans.value = [];
    } finally {
        loading.value = false;
    }
};

// preserveState keeps this modal open while Inertia refreshes the page props
// (skills list + flash message); then the library list is re-read.
const post = (name, data) => {
    busy.value = true;
    router.post(route(name), { preset_id: props.preset?.id, ...data }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => { busy.value = false; load(); },
    });
};

const attach = (name) => post('admin.skills.library.attach', { name });
const refresh = (skillNumber) => post('admin.skills.library.refresh', { skill_number: skillNumber });
const refreshAll = () => post('admin.skills.library.refresh', {});

const close = () => { show.value = false; };
const handleEscape = (e) => { if (e.key === 'Escape' && show.value) close(); };

watch(() => show.value, (visible) => {
    if (visible) {
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleEscape);
        load();
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleEscape);
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>