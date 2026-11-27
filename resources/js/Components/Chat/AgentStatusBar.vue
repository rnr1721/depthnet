<template>
  <div v-if="visible" :class="[
    'flex-shrink-0 border-t text-xs select-none',
    isDark ? 'bg-gray-900/80 border-gray-800' : 'bg-gray-50 border-gray-200'
  ]">
    <!-- One-line status + optional action -->
    <div class="flex items-stretch">
      <button type="button" @click="expanded = !expanded" :class="[
        'flex-1 min-w-0 flex items-center gap-2 px-3 sm:px-4 py-1.5 text-left transition-colors',
        isDark ? 'hover:bg-gray-800/60' : 'hover:bg-gray-100'
      ]">
        <span class="relative flex h-2 w-2 flex-shrink-0">
          <span v-if="isActive"
            :class="['animate-ping absolute inline-flex h-full w-full rounded-full opacity-60', dotColor]"></span>
          <span :class="['relative inline-flex rounded-full h-2 w-2', dotColor]"></span>
        </span>

        <span class="flex-shrink-0">{{ line.icon }}</span>
        <span :class="['truncate', toneClass]">
          <span class="font-medium">{{ line.text }}</span>
          <span v-if="line.detail" class="opacity-70"> · {{ line.detail }}</span>
        </span>

        <span v-if="line.time" :class="['ml-auto flex-shrink-0 tabular-nums', mutedClass]">{{ line.time }}</span>
        <span v-if="hasTimeline" :class="['flex-shrink-0', line.time ? '' : 'ml-auto', mutedClass]">
          {{ expanded ? '▾' : '▸' }}
        </span>
      </button>

      <!-- Retry: after a failure, or "now" instead of waiting for the backoff -->
      <button v-if="showRetry" type="button" @click="onRetry" :disabled="retrying" :class="[
        'flex-shrink-0 px-3 my-1 mr-2 rounded-md text-xs font-medium transition-colors disabled:opacity-50',
        state === 'error'
          ? (isDark ? 'bg-red-700 hover:bg-red-600 text-white' : 'bg-red-600 hover:bg-red-700 text-white')
          : (isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-200 hover:bg-gray-300 text-gray-700')
      ]">
        ↻ {{ state === 'error' ? t('agent_retry') : t('agent_retry_now') }}
      </button>
    </div>

    <!-- Timeline: running cycle, or the last finished one -->
    <div v-if="expanded && hasTimeline" :class="['px-3 sm:px-4 pb-2 pt-0.5 max-h-[35vh] overflow-y-auto', mutedClass]">
      <div class="flex items-center justify-between mb-1">
        <span>{{ timelineTitle }}</span>
        <span v-if="timelineTotal" class="tabular-nums">{{ fmtMs(timelineTotal) }}</span>
      </div>
      <div v-for="(step, i) in timeline" :key="i" class="flex items-center gap-2 py-0.5"
        :class="step.depth > 1 ? 'pl-5' : ''">
        <span class="flex-shrink-0 w-4 text-center">{{ phaseInfo(step.phase).icon }}</span>
        <span class="truncate min-w-0"
          :class="step.depth > 1 ? 'opacity-80' : (isDark ? 'text-gray-300' : 'text-gray-700')">
          {{ phaseInfo(step.phase).text }}<span v-if="step.label" class="opacity-70"> · {{ labelText(step) }}</span>
        </span>
        <span class="ml-auto flex items-center gap-2 flex-shrink-0">
          <span v-if="step.depth === 1 && timelineTotal"
            :class="['h-1 rounded-full', isDark ? 'bg-indigo-500/60' : 'bg-indigo-400/70']"
            :style="{ width: barWidth(step) }"></span>
          <span class="tabular-nums w-12 text-right">{{ step.ms === null ? '…' : fmtMs(step.ms) }}</span>
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
  status: { type: Object, default: null },
  isDark: Boolean,
  // Admin only: the retry endpoint is behind AdminMiddleware
  canRetry: { type: Boolean, default: false },
});

const emit = defineEmits(['retry']);
const retrying = ref(false);

const showRetry = computed(() => props.canRetry
  && (state.value === 'retrying' || (state.value === 'error' && props.status?.retryable !== false)));

async function onRetry() {
  if (retrying.value) return;
  retrying.value = true;
  try {
    // parent returns a promise (axios); keep the button disabled until done
    await new Promise((resolve) => emit('retry', resolve));
  } finally {
    retrying.value = false;
  }
}

const expanded = ref(false);
const nowTick = ref(Date.now());
let tickTimer = null;

// Server clock offset: elapsed times use server timestamps, so correct for the
// difference between server "now" and the browser clock.
const offset = computed(() => (props.status?.now ? props.status.now - Date.now() : 0));
const serverNow = computed(() => nowTick.value + offset.value);

const state = computed(() => props.status?.state || 'idle');
const isActive = computed(() => ['working', 'queued', 'stopping'].includes(state.value));

const PHASES = {
  prepare: { icon: '⚙️', key: 'agent_phase_prepare' },
  compact: { icon: '🗜️', key: 'agent_phase_compact' },
  context: { icon: '🧩', key: 'agent_phase_context' },
  rag: { icon: '📚', key: 'agent_phase_rag' },
  rag_query: { icon: '🔎', key: 'agent_phase_rag_query' },
  rag_search: { icon: '📚', key: 'agent_phase_rag_search' },
  voice: { icon: '🗣️', key: 'agent_phase_voice' },
  impulse: { icon: '🔁', key: 'agent_phase_impulse' },
  reasoning: { icon: '💭', key: 'agent_phase_reasoning' },
  generate: { icon: '🧠', key: 'agent_phase_generate' },
  act: { icon: '⚡', key: 'agent_phase_act' },
  tool: { icon: '🛠️', key: 'agent_phase_tool' },
};

function phaseInfo(phase) {
  const p = PHASES[phase];
  return p ? { icon: p.icon, text: t(p.key) } : { icon: '•', text: phase };
}

const RETRY_REASONS = { network: 'agent_reason_network', rate_limit: 'agent_reason_rate_limit', overloaded: 'agent_reason_overloaded' };

function labelText(step) {
  if (step.phase === 'rag' && ['silent', 'warm', 'cold'].includes(step.label)) {
    return t(`agent_rag_${step.label}`);
  }
  // HTTP-level retry inside a phase: "retry:2/3:network"
  const m = /^retry:(\d+)\/(\d+):(\w+)$/.exec(step.label || '');
  if (m) {
    const reason = RETRY_REASONS[m[3]] ? t(RETRY_REASONS[m[3]]) : m[3];
    return `${t('agent_retry_attempt')} ${m[1]}/${m[2]} · ${reason}`;
  }
  return step.label;
}

function fmtMs(ms) {
  if (ms < 1000) return `${ms}${t('agent_unit_ms')}`;
  const s = ms / 1000;
  if (s < 60) return `${s < 10 ? s.toFixed(1) : Math.round(s)}${t('agent_unit_s')}`;
  const m = Math.floor(s / 60);
  return `${m}${t('agent_unit_m')} ${Math.round(s % 60)}${t('agent_unit_s')}`;
}

function since(ms) {
  return ms ? fmtMs(Math.max(0, serverNow.value - ms)) : null;
}

const line = computed(() => {
  const s = props.status || {};
  switch (state.value) {
    case 'working':
    case 'stopping': {
      const stack = s.stack || [];
      const top = stack[0];
      const inner = stack.length > 1 ? stack[stack.length - 1] : null;
      const main = top ? phaseInfo(top.phase) : { icon: '🌀', text: t('agent_state_waking') };

      // detail: innermost phase (e.g. "formulating queries · Memory-RAG") or top label
      let detail = null;
      if (inner) {
        const info = phaseInfo(inner.phase);
        detail = inner.label ? `${info.text} · ${labelText(inner)}` : info.text;
      } else if (top?.label) {
        detail = labelText(top);
      }
      if (state.value === 'stopping') detail = t('agent_state_stopping') + (detail ? ` · ${detail}` : '');

      return {
        icon: main.icon,
        text: main.text,
        detail,
        time: since(top?.since_ms ?? s.cycle_since),
      };
    }
    case 'queued':
      return { icon: '⏳', text: t('agent_state_queued'), time: since(s.queued_at) };
    case 'sleeping': {
      const left = s.next_at ? Math.max(0, s.next_at - serverNow.value) : null;
      return {
        icon: '💤',
        text: left ? t('agent_state_sleeping_in') : t('agent_state_sleeping'),
        detail: lastSummary.value,
        time: left ? fmtMs(Math.ceil(left / 1000) * 1000) : null,
      };
    }
    case 'retrying': {
      const left = s.next_at ? Math.max(0, s.next_at - serverNow.value) : null;
      return {
        icon: '🔁',
        text: `${t('agent_state_retrying')} ${s.attempt ?? '?'}/${s.max_attempts ?? '?'}`,
        detail: s.error || null,
        time: left !== null ? fmtMs(Math.ceil(left / 1000) * 1000) : null,
      };
    }
    case 'error':
      return {
        icon: '⚠️',
        text: s.gave_up ? t('agent_state_gave_up') : t('agent_state_error'),
        detail: s.error || null,
      };
    case 'unknown':
      return { icon: '❔', text: t('agent_state_unknown') };
    default:
      return { icon: '✓', text: t('agent_state_waiting'), detail: lastSummary.value };
  }
});

const lastSummary = computed(() => {
  const last = props.status?.last;
  return last?.total_ms ? `${t('agent_last_cycle')} ${fmtMs(last.total_ms)}` : null;
});

// Show while busy / sleeping / failed, or when there's a last cycle to inspect.
const visible = computed(() => {
  if (!props.status) return false;
  if (['working', 'queued', 'stopping', 'sleeping', 'retrying', 'error', 'unknown'].includes(state.value)) return true;
  return !!props.status.last?.total_ms;
});

const timeline = computed(() => {
  const s = props.status || {};
  if (state.value === 'working' || state.value === 'stopping') {
    // live: running steps show elapsed time so far
    const stack = s.stack || [];
    return (s.steps || []).map((step) => {
      if (step.ms !== null) return step;
      const open = stack.find(e => e.phase === step.phase && e.label === step.label);
      return { ...step, ms: open ? Math.max(0, serverNow.value - open.since_ms) : null };
    });
  }
  return s.last?.steps || [];
});

const hasTimeline = computed(() => timeline.value.length > 0);

const timelineTotal = computed(() => {
  const s = props.status || {};
  if (state.value === 'working' || state.value === 'stopping') {
    return s.cycle_since ? Math.max(0, serverNow.value - s.cycle_since) : null;
  }
  return s.last?.total_ms || null;
});

const timelineTitle = computed(() => (state.value === 'working' || state.value === 'stopping')
  ? t('agent_timeline_current')
  : t('agent_timeline_last'));

function barWidth(step) {
  if (!timelineTotal.value || step.ms === null) return '0';
  // share of the cycle -> up to 60px bar
  const share = Math.min(1, step.ms / timelineTotal.value);
  return `${Math.max(2, Math.round(share * 60))}px`;
}

const dotColor = computed(() => {
  switch (state.value) {
    case 'working': return 'bg-indigo-500';
    case 'queued': return 'bg-amber-400';
    case 'stopping': return 'bg-orange-500';
    case 'sleeping': return 'bg-sky-400';
    case 'retrying': return 'bg-amber-500';
    case 'error': return 'bg-red-500';
    case 'unknown': return 'bg-gray-400';
    default: return 'bg-green-500';
  }
});

const toneClass = computed(() => state.value === 'error'
  ? (props.isDark ? 'text-red-300' : 'text-red-600')
  : (props.isDark ? 'text-gray-300' : 'text-gray-700'));

const mutedClass = computed(() => props.isDark ? 'text-gray-500' : 'text-gray-400');

// Tick only when something on screen depends on the clock.
function syncTicker() {
  const needs = isActive.value || state.value === 'sleeping' || state.value === 'retrying';
  if (needs && !tickTimer) {
    tickTimer = setInterval(() => { nowTick.value = Date.now(); }, 1000);
  } else if (!needs && tickTimer) {
    clearInterval(tickTimer);
    tickTimer = null;
  }
}

watch(state, syncTicker);
watch(() => props.status, () => { nowTick.value = Date.now(); });
onMounted(syncTicker);
onBeforeUnmount(() => { if (tickTimer) clearInterval(tickTimer); });
</script>
