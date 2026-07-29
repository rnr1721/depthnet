<template>
    <Transition enter-active-class="transition ease-out duration-300" enter-from-class="opacity-0"
        enter-to-class="opacity-100" leave-active-class="transition ease-in duration-200" leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <div v-if="modelValue" class="fixed inset-0 z-50 overflow-y-auto" @click.self="close">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="close"></div>

                <div :class="[
                    'relative w-full max-w-lg rounded-2xl shadow-2xl border transition-all my-8',
                    isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
                ]">
                    <!-- Header -->
                    <div :class="['px-6 py-4 border-b', isDark ? 'border-gray-700' : 'border-gray-200']">
                        <h3 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                            {{ isEdit ? t('wake_edit_title') : t('wake_new_title') }}
                        </h3>
                    </div>

                    <!-- Body -->
                    <div class="px-6 py-4 space-y-4">
                        <!-- Schedule type selector -->
                        <div>
                            <label
                                :class="['block text-sm font-medium mb-2', isDark ? 'text-gray-300' : 'text-gray-700']">
                                {{ t('wake_field_type') }}
                            </label>
                            <div class="grid grid-cols-4 gap-2">
                                <button v-for="type in types" :key="type" @click="form.schedule_type = type" :class="[
                                    'px-2 py-2 rounded-lg text-xs font-medium transition-all border',
                                    form.schedule_type === type
                                        ? (isDark ? 'bg-sky-600 border-sky-500 text-white' : 'bg-sky-600 border-sky-600 text-white')
                                        : (isDark ? 'bg-gray-700 border-gray-600 text-gray-300 hover:bg-gray-600' : 'bg-gray-50 border-gray-300 text-gray-700 hover:bg-gray-100')
                                ]">
                                    <span aria-hidden="true" class="mr-1">{{ typeIcon(type) }}</span>
                                    {{ t('wake_type_' + type) }}
                                </button>
                            </div>
                            <p :class="['mt-2 text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('wake_type_hint_' + form.schedule_type) }}
                            </p>
                        </div>

                        <!-- once: absolute datetime -->
                        <div v-if="form.schedule_type === 'once'">
                            <label :class="labelClass">{{ t('wake_field_run_at') }}</label>
                            <input type="datetime-local" v-model="runAtLocal" :class="inputClass" />
                        </div>

                        <!-- interval: value + unit -->
                        <div v-else-if="form.schedule_type === 'interval'">
                            <label :class="labelClass">{{ t('wake_field_interval') }}</label>
                            <div class="flex gap-2">
                                <input type="number" min="1" v-model.number="intervalValue"
                                    :class="[inputClass, 'flex-1']" :placeholder="t('wake_interval_placeholder')" />
                                <select v-model="intervalUnit" :class="[inputClass, 'w-32']">
                                    <option value="s">{{ t('wake_unit_seconds') }}</option>
                                    <option value="m">{{ t('wake_unit_minutes') }}</option>
                                    <option value="h">{{ t('wake_unit_hours') }}</option>
                                    <option v-if="pulsesEnabled" value="p">{{ t('wake_unit_pulses') }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- daily: time of day (or pulse position) -->
                        <div v-else-if="form.schedule_type === 'daily'">
                            <label :class="labelClass">{{ t('wake_field_daily') }}</label>
                            <div v-if="!dailyAsPulse">
                                <input type="time" v-model="dailyTime" :class="inputClass" />
                            </div>
                            <div v-else class="flex gap-2 items-center">
                                <span :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">p</span>
                                <input type="number" min="0" max="999" v-model.number="dailyPulse"
                                    :class="[inputClass, 'flex-1']" placeholder="850" />
                            </div>
                            <label v-if="pulsesEnabled" class="flex items-center gap-2 mt-2 cursor-pointer">
                                <input type="checkbox" v-model="dailyAsPulse"
                                    class="rounded border-gray-400 text-sky-600 focus:ring-sky-500" />
                                <span :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-600']">
                                    {{ t('wake_use_pulse_position') }}
                                </span>
                            </label>
                        </div>

                        <!-- cron: raw expression -->
                        <div v-else-if="form.schedule_type === 'cron'">
                            <label :class="labelClass">{{ t('wake_field_cron') }}</label>
                            <input type="text" v-model="form.cron_expression" :class="[inputClass, 'font-mono']"
                                placeholder="0 9 * * 1-5" />
                            <p :class="['mt-1 text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('wake_cron_hint') }}
                            </p>
                        </div>

                        <!-- wake message (always) -->
                        <div>
                            <label :class="labelClass">{{ t('wake_field_message') }}</label>
                            <textarea v-model="form.wake_message" rows="3" :class="inputClass"
                                :placeholder="t('wake_message_placeholder')"></textarea>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div
                        :class="['px-6 py-4 border-t flex justify-end gap-3', isDark ? 'border-gray-700' : 'border-gray-200']">
                        <button @click="close" :class="[
                            'px-4 py-2 rounded-xl font-medium transition-all',
                            isDark ? 'bg-gray-700 hover:bg-gray-600 text-gray-200' : 'bg-gray-100 hover:bg-gray-200 text-gray-700'
                        ]">
                            {{ t('wake_action_cancel') }}
                        </button>
                        <button @click="submit" :disabled="processing" :class="[
                            'px-4 py-2 rounded-xl font-medium transition-all focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:opacity-50',
                            isDark ? 'bg-sky-600 hover:bg-sky-700 text-white focus:ring-offset-gray-800' : 'bg-sky-600 hover:bg-sky-700 text-white'
                        ]">
                            {{ isEdit ? t('wake_action_save') : t('wake_action_create') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';

const { t } = useI18n();

const props = defineProps({
    modelValue: Boolean,
    preset: { type: Object, default: null },
    schedule: { type: Object, default: null },
    pulsesEnabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'success']);

const isDark = ref(false);
const processing = ref(false);
const types = ['once', 'interval', 'daily', 'cron'];

const isEdit = computed(() => !!props.schedule);

// ── Form model ───────────────────────────────────────────────────────────────
const form = ref({
    schedule_type: 'once',
    wake_message: '',
    cron_expression: '',
});

// Sub-fields with friendly editing surfaces
const runAtLocal = ref('');        // datetime-local string
const intervalValue = ref(2);
const intervalUnit = ref('h');
const dailyTime = ref('09:00');
const dailyAsPulse = ref(false);
const dailyPulse = ref(850);

const SECONDS_PER_PULSE = 86.4;

// ── Presentation helpers ─────────────────────────────────────────────────────
const typeIcon = (type) => ({ once: '→', interval: '↻', daily: '☀', cron: '⋮' }[type] || '•');

const labelClass = computed(() => [
    'block text-sm font-medium mb-2', isDark.value ? 'text-gray-300' : 'text-gray-700'
]);

const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-sky-500 transition-all px-4 py-3',
    isDark.value ? 'bg-gray-700 text-white ring-gray-600 placeholder-gray-400' : 'bg-gray-50 text-gray-900 ring-gray-300 placeholder-gray-500'
]);

// ── Hydrate form when opening / when an edit target arrives ───────────────────
watch(() => props.modelValue, (open) => {
    if (!open) return;
    if (props.schedule) {
        hydrateFromSchedule(props.schedule);
    } else {
        resetForm();
    }
});

const resetForm = () => {
    form.value = { schedule_type: 'once', wake_message: '', cron_expression: '' };
    runAtLocal.value = defaultRunAt();
    intervalValue.value = 2;
    intervalUnit.value = 'h';
    dailyTime.value = '09:00';
    dailyAsPulse.value = false;
    dailyPulse.value = 850;
};

const hydrateFromSchedule = (s) => {
    form.value = {
        schedule_type: s.schedule_type,
        wake_message: s.wake_message || '',
        cron_expression: s.cron_expression || '',
    };
    if (s.run_at) {
        // ISO → datetime-local (strip seconds/zone for the input)
        const d = new Date(s.run_at);
        runAtLocal.value = toLocalInput(d);
    } else {
        runAtLocal.value = defaultRunAt();
    }
    if (s.interval_seconds) {
        // Present hours/minutes/seconds sensibly
        if (s.interval_seconds % 3600 === 0) { intervalValue.value = s.interval_seconds / 3600; intervalUnit.value = 'h'; }
        else if (s.interval_seconds % 60 === 0) { intervalValue.value = s.interval_seconds / 60; intervalUnit.value = 'm'; }
        else { intervalValue.value = s.interval_seconds; intervalUnit.value = 's'; }
    }
    if (s.daily_seconds != null) {
        const h = Math.floor(s.daily_seconds / 3600);
        const m = Math.floor((s.daily_seconds % 3600) / 60);
        dailyTime.value = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        dailyPulse.value = Math.round(s.daily_seconds / SECONDS_PER_PULSE);
    }
    dailyAsPulse.value = false;
};

const defaultRunAt = () => {
    const d = new Date(Date.now() + 60 * 60 * 1000); // +1h
    return toLocalInput(d);
};

const toLocalInput = (d) => {
    // yyyy-MM-ddThh:mm in local time
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// ── Build payload from the friendly sub-fields ────────────────────────────────
const buildPayload = () => {
    const payload = {
        preset_id: props.preset?.id,
        schedule_type: form.value.schedule_type,
        wake_message: form.value.wake_message,
        run_at: null,
        interval_seconds: null,
        daily_seconds: null,
        cron_expression: null,
    };

    switch (form.value.schedule_type) {
        case 'once':
            payload.run_at = runAtLocal.value ? new Date(runAtLocal.value).toISOString() : null;
            break;
        case 'interval': {
            const v = Math.max(1, intervalValue.value || 0);
            payload.interval_seconds = intervalUnit.value === 'p'
                ? Math.round(v * SECONDS_PER_PULSE)
                : intervalUnit.value === 'h' ? v * 3600
                    : intervalUnit.value === 'm' ? v * 60 : v;
            break;
        }
        case 'daily':
            if (dailyAsPulse.value) {
                const p = Math.min(999, Math.max(0, dailyPulse.value || 0));
                payload.daily_seconds = Math.round(p * SECONDS_PER_PULSE);
            } else {
                const [h, m] = (dailyTime.value || '00:00').split(':').map(Number);
                payload.daily_seconds = h * 3600 + m * 60;
            }
            break;
        case 'cron':
            payload.cron_expression = form.value.cron_expression;
            break;
    }

    return payload;
};

// ── Submit ───────────────────────────────────────────────────────────────────
const submit = () => {
    if (!props.preset) return;
    processing.value = true;

    const payload = buildPayload();
    const opts = {
        preserveScroll: true,
        onSuccess: () => { emit('success'); close(); },
        onFinish: () => { processing.value = false; },
    };

    if (isEdit.value) {
        router.put(route('admin.wakes.update', props.schedule.id), payload, opts);
    } else {
        router.post(route('admin.wakes.store'), payload, opts);
    }
};

const close = () => emit('update:modelValue', false);

onMounted(() => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
    }
    window.addEventListener('theme-changed', (event) => {
        isDark.value = event.detail.isDark;
    });
});
</script>