<template>
  <div :class="[
    'w-[min(20rem,calc(100vw-1.5rem))] rounded-xl shadow-xl border overflow-hidden select-none',
    isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
  ]" @pointerdown.prevent>
    <!-- Category tabs -->
    <div :class="['flex border-b px-1', isDark ? 'border-gray-700' : 'border-gray-200']">
      <button v-for="(cat, i) in categories" :key="cat.id" type="button" :title="t(cat.label)"
        @click="activeCat = i" :class="[
          'flex-1 py-1.5 text-lg leading-none rounded-t transition-colors',
          activeCat === i
            ? (isDark ? 'bg-gray-700' : 'bg-gray-100')
            : 'opacity-60 hover:opacity-100'
        ]">{{ cat.icon }}</button>
    </div>

    <div class="p-2 h-52 overflow-y-auto overscroll-contain">
      <div v-if="activeCat === 0 && !recent.length"
        :class="['text-xs text-center py-8', isDark ? 'text-gray-500' : 'text-gray-400']">
        {{ t('chat_emoji_recent_empty') }}
      </div>
      <div class="grid grid-cols-8 gap-0.5">
        <button v-for="e in currentList" :key="e" type="button" @click="pick(e)" :class="[
          'h-9 text-xl rounded-lg flex items-center justify-center transition-transform active:scale-90',
          isDark ? 'hover:bg-gray-700' : 'hover:bg-gray-100'
        ]">{{ e }}</button>
      </div>
    </div>

    <div v-if="categories[activeCat].id === 'depth'"
      :class="['px-3 py-1.5 text-[11px] border-t italic', isDark ? 'border-gray-700 text-gray-500' : 'border-gray-200 text-gray-400']">
      {{ t('chat_emoji_depth_hint') }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({ isDark: Boolean });
const emit = defineEmits(['pick']);

const RECENT_KEY = 'chat_recent_emoji';
const RECENT_MAX = 24;

// Small curated set — no dependency, no CDN fetches.
const categories = [
  { id: 'recent', icon: '🕘', label: 'chat_emoji_recent', list: null },
  {
    id: 'depth', icon: '🧠', label: 'chat_emoji_depth',
    // For talking with (and as) an agent: thinking, memory, inner states
    list: ['🧠', '💭', '🤔', '🌀', '✨', '🔮', '🧬', '🫀', '❤️', '🌱', '🌊', '🌙',
      '🤖', '👁️', '🪞', '🗝️', '📚', '📝', '🎯', '⚡', '🛠️', '🔍', '🧩', '♾️',
      '🫂', '🙏', '🕯️', '⏳', '🌌', '🪐', '🌗', '🧭'],
  },
  {
    id: 'smileys', icon: '😊', label: 'chat_emoji_smileys',
    list: ['😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '😉', '😊', '😇',
      '🥰', '😍', '🤩', '😘', '😋', '😛', '😜', '🤪', '🤗', '🤭', '🤫', '🤨',
      '😐', '😑', '😶', '🙄', '😏', '😌', '😔', '😪', '😴', '😮', '😲', '🥺',
      '😢', '😭', '😤', '😠', '😡', '🤯', '😳', '🥵', '🥶', '😱', '🤓', '😎',
      '🥳', '🤠', '😈', '💀', '👻', '👽', '🙈', '🙉', '🙊', '😺'],
  },
  {
    id: 'gestures', icon: '👍', label: 'chat_emoji_gestures',
    list: ['👍', '👎', '👌', '🤌', '✌️', '🤞', '🤟', '🤘', '🤙', '👋', '🖐️', '✋',
      '👏', '🙌', '👐', '🤝', '💪', '🫡', '🫶', '☝️', '👆', '👇', '👈', '👉',
      '✍️', '🤷', '🤦', '🙋', '🧘', '🏃', '💃', '🕺'],
  },
  {
    id: 'nature', icon: '🐾', label: 'chat_emoji_nature',
    list: ['🐱', '🐶', '🐭', '🐀', '🕷️', '🦊', '🐻', '🐼', '🦉', '🐸', '🐢', '🐙',
      '🦋', '🐝', '🌸', '🌻', '🌵', '🌲', '🍀', '🍁', '🔥', '💧', '❄️', '☀️',
      '🌈', '⭐', '☁️', '⛈️', '🍉', '🍎', '☕', '🍕'],
  },
  {
    id: 'symbols', icon: '💡', label: 'chat_emoji_symbols',
    list: ['💡', '✅', '❌', '⚠️', '❓', '❗', '💯', '🔥', '🚀', '🎉', '🏁', '📌',
      '📎', '🔗', '🔒', '🔑', '⚙️', '🧪', '🐛', '💻', '🖥️', '📱', '💾', '📦',
      '📈', '📉', '🕐', '📅', '💬', '🗨️', '➡️', '🔁'],
  },
];

function loadRecent() {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').slice(0, RECENT_MAX); }
  catch { return []; }
}

const recent = ref(loadRecent());
const activeCat = ref(recent.value.length ? 0 : 1);

const currentList = computed(() =>
  activeCat.value === 0 ? recent.value : categories[activeCat.value].list
);

function pick(emoji) {
  recent.value = [emoji, ...recent.value.filter(e => e !== emoji)].slice(0, RECENT_MAX);
  try { localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value)); } catch { }
  emit('pick', emoji);
}
</script>
