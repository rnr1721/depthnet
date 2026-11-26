<template>
  <!-- @pointerdown.prevent keeps focus (and the mobile keyboard) in the textarea -->
  <div :class="[
    'flex items-center gap-0.5 overflow-x-auto no-scrollbar rounded-lg px-1 py-0.5',
    isDark ? 'bg-gray-700/60' : 'bg-gray-100'
  ]" @pointerdown.prevent>
    <button v-for="b in buttons" :key="b.id" type="button" :title="b.title" @click="$emit('format', b.id)" :class="[
      'h-8 min-w-8 px-2 rounded-md text-sm flex items-center justify-center flex-shrink-0 transition-colors',
      b.cls,
      isDark ? 'text-gray-300 hover:bg-gray-600' : 'text-gray-600 hover:bg-gray-200'
    ]">{{ b.label }}</button>

    <span :class="['mx-1 w-px h-5 flex-shrink-0', isDark ? 'bg-gray-600' : 'bg-gray-300']"></span>

    <button type="button" @click="$emit('togglePreview')" :title="t('chat_md_preview')" :class="[
      'h-8 px-2 rounded-md text-xs font-medium flex items-center gap-1 flex-shrink-0 transition-colors',
      preview
        ? (isDark ? 'bg-indigo-600 text-white' : 'bg-indigo-500 text-white')
        : (isDark ? 'text-gray-300 hover:bg-gray-600' : 'text-gray-600 hover:bg-gray-200')
    ]">👁 {{ t('chat_md_preview') }}</button>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({ isDark: Boolean, preview: Boolean });
defineEmits(['format', 'togglePreview']);

const mod = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl+';

const buttons = computed(() => [
  { id: 'bold', label: 'B', cls: 'font-bold', title: `${t('chat_md_bold')} (${mod}B)` },
  { id: 'italic', label: 'I', cls: 'italic font-serif', title: `${t('chat_md_italic')} (${mod}I)` },
  { id: 'strike', label: 'S', cls: 'line-through', title: t('chat_md_strike') },
  { id: 'code', label: '</>', cls: 'font-mono text-xs', title: `${t('chat_md_code')} (${mod}E)` },
  { id: 'codeblock', label: '{ }', cls: 'font-mono text-xs', title: t('chat_md_codeblock') },
  { id: 'quote', label: '❝', cls: '', title: t('chat_md_quote') },
  { id: 'list', label: '•', cls: 'text-lg', title: t('chat_md_list') },
  { id: 'olist', label: '1.', cls: 'text-xs', title: t('chat_md_olist') },
  { id: 'link', label: '🔗', cls: 'text-xs', title: t('chat_md_link') },
]);
</script>

<style>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}

.no-scrollbar {
  scrollbar-width: none;
}
</style>
