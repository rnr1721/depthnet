<template>
  <div :class="['flex-1 min-h-0 flex flex-col', isDark ? 'bg-gray-900 text-gray-200' : 'bg-gray-50 text-gray-800']">
    <!-- Toolbar -->
    <div :class="[
      'flex flex-wrap items-center gap-2 px-3 py-2 border-b flex-shrink-0 text-sm',
      isDark ? 'border-gray-700 bg-gray-800' : 'border-gray-200 bg-white'
    ]">
      <select v-model="file" @change="reload" :class="inputCls" class="max-w-[12rem]">
        <option v-for="f in files" :key="f.name" :value="f.name">{{ f.name }} · {{ humanSize(f.size) }}</option>
      </select>

      <select v-model="minLevel" :class="inputCls">
        <option value="debug">{{ t('log_level_all') }}</option>
        <option value="info">info+</option>
        <option value="warning">warning+</option>
        <option value="error">error+</option>
      </select>

      <input v-model="search" type="search" :placeholder="t('log_search')" :class="inputCls"
        class="flex-1 min-w-[8rem] text-base lg:text-sm" />

      <label class="flex items-center gap-1.5 cursor-pointer select-none" :title="t('log_follow_hint')">
        <input type="checkbox" v-model="follow" class="rounded text-indigo-600" />
        <span>{{ t('log_follow') }}</span>
        <span v-if="follow" class="relative flex h-2 w-2">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
        </span>
      </label>

      <button type="button" @click="reload" :class="btnCls" :disabled="loading" :title="t('log_reload')">⟳</button>
      <button type="button" @click="expandAll = !expandAll" :class="btnCls" :title="t('log_toggle_all')">
        {{ expandAll ? '⊟' : '⊞' }}
      </button>
      <button type="button" @click="clearLog" :class="[btnCls, 'hover:!bg-red-600 hover:!text-white']"
        :title="t('log_clear')">🗑</button>
    </div>

    <!-- Status line -->
    <div v-if="error || truncated" :class="[
      'px-3 py-1 text-xs flex-shrink-0',
      error ? (isDark ? 'bg-red-900/60 text-red-200' : 'bg-red-50 text-red-700')
        : (isDark ? 'bg-yellow-900/40 text-yellow-200' : 'bg-yellow-50 text-yellow-800')
    ]">{{ error || t('log_truncated') }}</div>

    <!-- Entries -->
    <div ref="listEl" class="chat-scroll flex-1 min-h-0 overflow-y-auto font-mono text-xs" @scroll.passive="onScroll">
      <div v-if="!loading && !visibleEntries.length"
        :class="['text-center py-12 font-sans text-sm', isDark ? 'text-gray-500' : 'text-gray-400']">
        {{ entries.length ? t('log_nothing_matches') : t('log_empty') }}
      </div>

      <div v-for="e in visibleEntries" :key="e.id" :class="[
        'border-b px-3 py-1.5',
        isDark ? 'border-gray-800 hover:bg-gray-800/60' : 'border-gray-100 hover:bg-white',
        rowTint(e.level)
      ]">
        <div class="flex items-start gap-2 cursor-pointer" @click="toggle(e)">
          <span :class="['px-1.5 rounded text-[10px] font-bold uppercase flex-shrink-0 mt-px', levelBadge(e.level)]">
            {{ e.level }}
          </span>
          <span :class="['flex-shrink-0 tabular-nums', isDark ? 'text-gray-500' : 'text-gray-400']"
            :title="e.datetime">{{ shortTime(e.datetime) }}</span>
          <span class="flex-1 min-w-0 break-words [overflow-wrap:anywhere]">{{ e.message }}</span>
          <span v-if="e.details" :class="['flex-shrink-0', isDark ? 'text-gray-600' : 'text-gray-400']">
            {{ isOpen(e) ? '▾' : '▸' }}
          </span>
        </div>
        <pre v-if="e.details && isOpen(e)" :class="[
          'mt-1 ml-1 p-2 rounded whitespace-pre-wrap break-all text-[11px] leading-snug max-h-96 overflow-auto',
          isDark ? 'bg-black/40 text-gray-300' : 'bg-gray-100 text-gray-700'
        ]">{{ e.details }}</pre>
      </div>

      <div v-if="loading && !entries.length" class="flex justify-center py-8">
        <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
  isDark: Boolean,
  active: { type: Boolean, default: true }, // tab visible -> poll
});

const POLL_MS = 3000;
const MAX_ENTRIES = 3000;
const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

const files = ref([]);
const file = ref(localStorage.getItem('log_viewer_file') || '');
const entries = ref([]);
const offset = ref(null);
const loading = ref(false);
const error = ref('');
const truncated = ref(false);
const minLevel = ref(localStorage.getItem('log_viewer_level') || 'debug');
const search = ref('');
const follow = ref(true);
const expandAll = ref(false);
const opened = ref(new Set());
const listEl = ref(null);

let timer = null;
let seq = 0;
let atBottom = true;

const inputCls = computed(() => [
  'rounded-lg border-0 ring-1 ring-inset px-2 py-1 text-sm focus:ring-2 focus:ring-indigo-500',
  props.isDark ? 'bg-gray-700 text-gray-100 ring-gray-600' : 'bg-gray-50 text-gray-900 ring-gray-300',
]);
const btnCls = computed(() => [
  'h-8 w-8 rounded-lg flex items-center justify-center transition-colors disabled:opacity-50',
  props.isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700',
]);

const visibleEntries = computed(() => {
  const min = LEVELS.indexOf(minLevel.value);
  const q = search.value.trim().toLowerCase();
  return entries.value.filter(e => {
    if (LEVELS.indexOf(e.level) < min) return false;
    if (!q) return true;
    return e.message.toLowerCase().includes(q) || (e.details && e.details.toLowerCase().includes(q));
  });
});

watch(minLevel, v => { try { localStorage.setItem('log_viewer_level', v); } catch { } });
watch(file, v => { try { localStorage.setItem('log_viewer_file', v); } catch { } });

function isOpen(e) { return expandAll.value || opened.value.has(e.id); }
function toggle(e) {
  if (!e.details) return;
  const s = new Set(opened.value);
  s.has(e.id) ? s.delete(e.id) : s.add(e.id);
  opened.value = s;
}

function levelBadge(level) {
  const d = props.isDark;
  switch (level) {
    case 'emergency': case 'alert': case 'critical': return 'bg-red-700 text-white';
    case 'error': return d ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-700';
    case 'warning': return d ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800';
    case 'notice': case 'info': return d ? 'bg-blue-900 text-blue-200' : 'bg-blue-100 text-blue-700';
    default: return d ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-600';
  }
}

function rowTint(level) {
  if (['emergency', 'alert', 'critical', 'error'].includes(level)) {
    return props.isDark ? 'bg-red-950/30' : 'bg-red-50/50';
  }
  return '';
}

function shortTime(dt) {
  // "2026-11-26 21:16:03" -> "11-26 21:16:03"
  return (dt || '').replace(/^\d{4}-/, '').replace('T', ' ').replace(/\.\d+.*$/, '');
}

function humanSize(b) {
  if (!b) return '0 B';
  if (b < 1024) return b + ' B';
  if (b < 1_048_576) return (b / 1024).toFixed(0) + ' KB';
  return (b / 1_048_576).toFixed(1) + ' MB';
}

function onScroll() {
  const el = listEl.value;
  if (!el) return;
  atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 40;
}

function scrollToEnd() {
  nextTick(() => {
    const el = listEl.value;
    if (el) el.scrollTop = el.scrollHeight;
    atBottom = true;
  });
}

function addEntries(newEntries, orphan) {
  const list = entries.value;
  // Lines without a header at the start of an incremental chunk belong to the
  // previous entry (e.g. stack trace written after we read the header line).
  if (orphan && list.length) {
    const last = list[list.length - 1];
    last.details = (last.details ? last.details + '\n' : '') + orphan;
  }
  for (const e of newEntries) list.push({ ...e, id: ++seq });
  if (list.length > MAX_ENTRIES) list.splice(0, list.length - MAX_ENTRIES);
}

async function fetchLog({ incremental }) {
  if (loading.value) return;
  loading.value = true;
  try {
    const { data } = await axios.get(route('admin.logs.index'), {
      params: { file: file.value || undefined, offset: incremental ? offset.value : undefined },
    });

    files.value = data.files || [];
    if (data.file && data.file !== file.value) file.value = data.file;
    error.value = '';

    if (!incremental || data.reset) {
      entries.value = [];
      opened.value = new Set();
      truncated.value = !!data.truncated;
      addEntries(data.entries || [], null);
      scrollToEnd();
    } else if ((data.entries?.length || data.orphan)) {
      const stick = atBottom;
      addEntries(data.entries || [], data.orphan);
      if (data.truncated) truncated.value = true;
      if (stick) scrollToEnd();
    }
    offset.value = data.offset ?? null;
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'Failed to load log';
  } finally {
    loading.value = false;
  }
}

function reload() {
  offset.value = null;
  fetchLog({ incremental: false });
}

async function clearLog() {
  if (!file.value || !confirm(t('log_clear_confirm', { file: file.value }))) return;
  try {
    await axios.post(route('admin.logs.clear'), { file: file.value });
    reload();
  } catch (e) {
    error.value = e.response?.data?.message || e.message;
  }
}

function startPolling() {
  stopPolling();
  timer = setInterval(() => {
    if (!props.active || !follow.value || document.hidden) return;
    fetchLog({ incremental: offset.value !== null });
  }, POLL_MS);
}

function stopPolling() {
  if (timer) clearInterval(timer);
  timer = null;
}

watch(() => props.active, (a) => {
  if (a) {
    // Coming back to the tab: catch up immediately
    if (offset.value === null) reload();
    else fetchLog({ incremental: true });
  }
});

onMounted(() => {
  reload();
  startPolling();
});

onBeforeUnmount(stopPolling);
</script>
