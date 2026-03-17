<template>
    <Teleport to="body">
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[9999] overflow-y-auto">
                <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="close"></div>
                    <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                    <Transition enter-active-class="transition ease-out duration-300"
                        enter-from-class="transform opacity-0 translate-y-4 sm:scale-95"
                        enter-to-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="transform opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="transform opacity-0 translate-y-4 sm:scale-95">
                        <div v-if="show"
                            :class="['relative inline-block transform overflow-hidden rounded-2xl text-left align-bottom shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle', isDark ? 'bg-gray-800' : 'bg-white']"
                            role="dialog">

                            <!-- Header -->
                            <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3 min-w-0">
                                        <span
                                            :class="['font-mono text-sm font-bold px-2.5 py-1 rounded-lg flex-shrink-0', isDark ? 'bg-emerald-900 text-emerald-300' : 'bg-emerald-100 text-emerald-800']">#{{
                                            skill?.number }}</span>
                                        <div class="min-w-0">
                                            <h3
                                                :class="['text-lg font-semibold truncate', isDark ? 'text-white' : 'text-gray-900']">
                                                {{ skill?.title }}</h3>
                                            <p v-if="skill?.description"
                                                :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                                skill.description }}</p>
                                        </div>
                                    </div>
                                    <button @click="close"
                                        :class="['ml-4 flex-shrink-0 rounded-lg p-2 transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500', isDark ? 'hover:bg-gray-700 text-gray-400' : 'hover:bg-gray-100 text-gray-600']">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Loading -->
                            <div v-if="loading" class="px-6 py-12 text-center">
                                <svg class="w-8 h-8 mx-auto animate-spin"
                                    :class="isDark ? 'text-emerald-400' : 'text-emerald-600'" fill="none"
                                    viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                            </div>

                            <!-- Items list -->
                            <div v-else class="max-h-[60vh] overflow-y-auto">
                                <div v-if="items.length === 0"
                                    :class="['px-6 py-10 text-center text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                                    {{ t('sk_no_items') }}
                                </div>
                                <div v-else class="divide-y" :class="isDark ? 'divide-gray-700' : 'divide-gray-200'">
                                    <div v-for="item in items" :key="item.number"
                                        :class="['p-5 group', isDark ? 'hover:bg-gray-750' : 'hover:bg-gray-50']">
                                        <!-- View mode -->
                                        <div v-if="editingItem?.number !== item.number"
                                            class="flex items-start justify-between space-x-4">
                                            <div class="flex items-start space-x-3 flex-1 min-w-0">
                                                <span
                                                    :class="['font-mono text-xs font-bold mt-0.5 flex-shrink-0', isDark ? 'text-emerald-400' : 'text-emerald-600']">{{
                                                    skill?.number }}.{{ item.number }}</span>
                                                <p
                                                    :class="['text-sm leading-relaxed', isDark ? 'text-gray-300' : 'text-gray-700']">
                                                    {{ item.content }}</p>
                                            </div>
                                            <div
                                                class="flex items-center space-x-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <button @click="startEdit(item)"
                                                    :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-gray-700 text-gray-400 hover:text-gray-200' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-700']"
                                                    :title="t('sk_edit')">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                                        </path>
                                                    </svg>
                                                </button>
                                                <button @click="deleteItem(item.number)"
                                                    :class="['p-1.5 rounded-lg transition-colors focus:outline-none', isDark ? 'hover:bg-red-900 text-red-400 hover:text-red-300' : 'hover:bg-red-100 text-red-500 hover:text-red-700']"
                                                    :title="t('sk_delete')">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                                        </path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Edit mode -->
                                        <div v-else class="space-y-3">
                                            <div class="flex items-center space-x-2">
                                                <span
                                                    :class="['font-mono text-xs font-bold', isDark ? 'text-emerald-400' : 'text-emerald-600']">{{
                                                    skill?.number }}.{{ item.number }}</span>
                                                <span
                                                    :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">{{
                                                    t('sk_editing') }}</span>
                                            </div>
                                            <textarea v-model="editForm.content" rows="4"
                                                :class="['w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-emerald-500 transition-all resize-none px-3 py-2 text-sm', isDark ? 'bg-gray-700 text-white ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300']"></textarea>
                                            <div class="flex space-x-2">
                                                <button @click="saveEdit(item.number)"
                                                    :disabled="editForm.processing || !editForm.content?.trim()"
                                                    :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-50', isDark ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white']">{{
                                                    t('sk_save') }}</button>
                                                <button @click="cancelEdit"
                                                    :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-all', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                                    t('sk_cancel') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div
                                :class="['px-6 py-4 border-t flex justify-end', isDark ? 'border-gray-700' : 'border-gray-200']">
                                <button @click="close"
                                    :class="['px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-gray-500', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">{{
                                    t('sk_close') }}</button>
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
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: Object,
    skill: Object,
    isDark: Boolean,
});

const emit = defineEmits(['update:modelValue', 'success']);

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const loading = ref(false);
const items = ref([]);
const editingItem = ref(null);
const editForm = useForm({ preset_id: null, skill_number: null, item_number: null, content: '' });

const handleEscape = (e) => { if (e.key === 'Escape' && show.value && !editingItem.value) close(); };

const close = () => {
    show.value = false;
    items.value = [];
    editingItem.value = null;
    document.body.style.overflow = '';
};

const loadItems = async () => {
    if (!props.skill || !props.preset) return;
    loading.value = true;
    try {
        const res = await axios.get(route('admin.skills.show', props.skill.number), {
            params: { preset_id: props.preset.id },
        });
        items.value = res.data.items ?? [];
    } catch (e) {
        items.value = [];
    } finally {
        loading.value = false;
    }
};

const startEdit = (item) => {
    editingItem.value = item;
    editForm.content = item.content;
};

const cancelEdit = () => {
    editingItem.value = null;
    editForm.reset();
};

const saveEdit = (itemNumber) => {
    editForm.preset_id = props.preset?.id;
    editForm.skill_number = props.skill?.number;
    editForm.item_number = itemNumber;
    editForm.post(route('admin.skills.update-item'), {
        onSuccess: () => {
            editingItem.value = null;
            loadItems();
            emit('success');
        },
    });
};

const deleteItem = (itemNumber) => {
    if (!confirm(t('sk_confirm_delete_item'))) return;
    router.delete(route('admin.skills.destroy-item'), {
        data: { preset_id: props.preset?.id, skill_number: props.skill?.number, item_number: itemNumber },
        onSuccess: () => { loadItems(); emit('success'); },
    });
};

watch(() => show.value, (visible) => {
    if (visible) {
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleEscape);
        loadItems();
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleEscape);
    }
});

watch(() => props.skill?.number, () => {
    if (show.value) loadItems();
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>