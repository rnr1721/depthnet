<template>
    <div :class="['pt-4 mt-1 border-t space-y-3', isDark ? 'border-gray-700' : 'border-gray-100']">
        <div class="flex items-center justify-between">
            <h4 :class="['text-sm font-semibold', isDark ? 'text-gray-200' : 'text-gray-800']">
                {{ t('capabilities_auth_title') }}
            </h4>
            <button v-if="ready" type="button" @click="load" :disabled="busy"
                :class="['text-xs underline', isDark ? 'text-gray-400 hover:text-gray-200' : 'text-gray-500 hover:text-gray-700']">
                {{ t('capabilities_auth_refresh') }}
            </button>
        </div>

        <!-- Config not saved yet (or driver switched without saving) -->
        <p v-if="!ready" :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-400']">
            {{ t('capabilities_auth_save_first') }}
        </p>

        <template v-else>
            <!-- Loading the first time -->
            <p v-if="!step && busy" :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                {{ t('capabilities_auth_loading') }}
            </p>

            <!-- Request-level failure (network, 4xx/5xx) -->
            <div v-if="requestError" :class="errorBoxClass">{{ requestError }}</div>

            <!-- Logged in -->
            <div v-if="step?.done" :class="[
                'flex items-center justify-between rounded-lg p-3 border',
                isDark ? 'border-green-800 bg-green-900 bg-opacity-20' : 'border-green-200 bg-green-50'
            ]">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-green-400 flex-shrink-0"></span>
                    <span :class="['text-sm', isDark ? 'text-gray-200' : 'text-gray-800']">
                        {{ t('capabilities_auth_logged_in_as') }}
                        <strong>{{ accountLabel }}</strong>
                    </span>
                </div>
                <button type="button" @click="logout" :disabled="busy" :class="[
                    'px-3 py-1.5 rounded-lg text-xs font-medium transition-all',
                    isDark ? 'bg-red-900 bg-opacity-60 hover:bg-red-800 text-red-300' : 'bg-red-50 hover:bg-red-100 text-red-600'
                ]">
                    {{ t('capabilities_auth_logout') }}
                </button>
            </div>

            <!-- A login step -->
            <form v-else-if="step" @submit.prevent="submit" class="space-y-3">
                <p v-if="step.message" :class="['text-xs', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ step.message }}
                </p>

                <!-- QR login: the provider gives a URL to encode; it may change as tokens refresh -->
                <div v-if="step.data?.poll" class="flex flex-col items-start gap-2">
                    <div :class="['rounded-lg p-2', 'bg-white']">
                        <img v-if="qrImage" :src="qrImage" alt="QR" width="220" height="220" class="block" />
                        <div v-else class="w-[220px] h-[220px] flex items-center justify-center text-xs text-gray-500">
                            {{ t('capabilities_auth_qr_preparing') }}
                        </div>
                    </div>
                    <p :class="['text-xs flex items-center gap-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                        <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                        {{ t('capabilities_auth_qr_waiting') }}
                    </p>
                </div>

                <CapabilityField v-for="(field, key) in step.fields" :key="`${step.step}-${key}`" v-model="input[key]"
                    :field="field" :is-dark="isDark" :autocomplete="autocompleteFor(key)" />

                <div v-if="step.error" :class="errorBoxClass">{{ step.error }}</div>

                <div class="flex items-center gap-3">
                    <button v-if="hasFields" type="submit" :disabled="busy || !canSubmit" :class="[
                        'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-all bg-indigo-600 text-white',
                        busy || !canSubmit ? 'opacity-50 cursor-not-allowed' : 'hover:bg-indigo-700'
                    ]">
                        <svg v-if="busy" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        {{ t('capabilities_auth_continue') }}
                    </button>
                    <button v-if="step.step !== firstStep" type="button" @click="load" :disabled="busy"
                        :class="['text-xs underline', isDark ? 'text-gray-400 hover:text-gray-200' : 'text-gray-500 hover:text-gray-700']">
                        {{ t('capabilities_auth_start_over') }}
                    </button>
                </div>

                <!-- Secondary actions offered by the provider (e.g. resend the code another way) -->
                <div v-if="step.actions?.length" class="flex flex-wrap items-center gap-3">
                    <button v-for="action in step.actions" :key="action.step" type="button" @click="runAction(action)"
                        :disabled="busy || waitLeft(action) > 0"
                        :class="['text-xs underline',
                            busy || waitLeft(action) > 0 ? 'opacity-50 cursor-not-allowed no-underline' : '',
                            isDark ? 'text-indigo-300 hover:text-indigo-200' : 'text-indigo-600 hover:text-indigo-800']">
                        {{ action.label }}
                        <span v-if="waitLeft(action) > 0">({{ t('capabilities_auth_wait', { s: waitLeft(action) })
                            }})</span>
                    </button>
                </div>
            </form>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import QRCode from 'qrcode';
import CapabilityField from './CapabilityField.vue';

/**
 * Generic interactive login for capability providers implementing
 * AuthenticatesInteractivelyInterface. Knows nothing about any messenger:
 * it renders whatever AuthStep the backend returns and posts the answer back,
 * until the step says done.
 */
const props = defineProps({
    capability: { type: String, required: true },
    presetId: { type: [Number, String], default: null },
    // False while the config is unsaved or the driver was switched in the form —
    // the backend logs in against the SAVED config only.
    ready: { type: Boolean, default: false },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['authorized', 'logged-out']);

const { t } = useI18n();

const step = ref(null);
const firstStep = ref(null);
const input = ref({});
const busy = ref(false);
const requestError = ref(null);

const routeParams = computed(() => ({ presetId: props.presetId, capability: props.capability }));

const accountLabel = computed(() => {
    const a = step.value?.account;
    if (!a) return '';
    return a.username ? `${a.name} (@${a.username})` : a.name;
});

const hasFields = computed(() => Object.keys(step.value?.fields ?? {}).length > 0);

const canSubmit = computed(() => {
    const fields = step.value?.fields ?? {};
    return Object.entries(fields).every(([key, f]) => !f.required || String(input.value[key] ?? '').trim() !== '');
});

const autocompleteFor = (key) => ({
    phone: 'tel',
    code: 'one-time-code',
    password: 'current-password',
}[key] ?? 'off');

// Action countdowns: `wait` is relative to when the step arrived. One ticking
// clock for all actions, running only while something is still waiting.
const stepAt = ref(0);
const now = ref(Date.now());
let ticker = null;

const waitLeft = (action) => {
    if (!action?.wait) return 0;
    return Math.max(0, Math.ceil(action.wait - (now.value - stepAt.value) / 1000));
};

const syncTicker = () => {
    const waiting = (step.value?.actions ?? []).some((a) => waitLeft(a) > 0);
    if (waiting && !ticker) {
        ticker = setInterval(() => {
            now.value = Date.now();
            if (!(step.value?.actions ?? []).some((a) => waitLeft(a) > 0)) {
                clearInterval(ticker);
                ticker = null;
            }
        }, 1000);
    } else if (!waiting && ticker) {
        clearInterval(ticker);
        ticker = null;
    }
};

onBeforeUnmount(() => { if (ticker) clearInterval(ticker); });

// Keep what the user typed when the same step comes back with an error;
// clear it when the flow moves to a different step.
const applyStep = (next) => {
    const sameStep = step.value && next && step.value.step === next.step;
    if (!sameStep) input.value = {};
    if (!firstStep.value && next && !next.done) firstStep.value = next.step;

    const wasDone = step.value?.done;
    // An error re-renders the same step — keep the running countdown.
    if (!(sameStep && next?.error)) {
        stepAt.value = Date.now();
        now.value = stepAt.value;
    }
    step.value = next;
    syncTicker();
    schedulePoll();

    if (next?.done && !wasDone) emit('authorized', next.account);
};

const request = async (method, body = null) => {
    stopPolling();
    busy.value = true;
    requestError.value = null;
    try {
        const name = {
            get: 'admin.capabilities.auth.show',
            post: 'admin.capabilities.auth.submit',
            delete: 'admin.capabilities.auth.destroy',
        }[method];
        const res = await axios({ method, url: route(name, routeParams.value), data: body ?? undefined });
        if (res.data?.success && res.data.step) {
            applyStep(res.data.step);
        } else {
            requestError.value = res.data?.message ?? t('capabilities_auth_failed');
        }
    } catch (e) {
        requestError.value = e.response?.data?.message ?? t('capabilities_auth_failed');
    } finally {
        busy.value = false;
    }
};

const load = () => {
    firstStep.value = null;
    return request('get');
};

const submit = () => {
    if (!step.value || busy.value) return;
    request('post', { step: step.value.step, input: { ...input.value } });
};

// An action is just another step with empty input; the provider answers with
// the next AuthStep. What the user already typed stays in the form.
const runAction = (action) => {
    if (!action?.step || busy.value || waitLeft(action) > 0) return;
    request('post', { step: action.step, input: {} });
};

// ── Polling steps (QR login) ────────────────────────────────────────────────
// A step with data.poll is re-checked every `poll` seconds with input
// {check: '1'} until the provider moves on. `pollGen` invalidates a poll in
// flight when the user does something else, so a late answer can't overwrite
// the step they have moved to.
let pollTimer = null;
let pollGen = 0;

const stopPolling = () => {
    pollGen++;
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
};

const schedulePoll = () => {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
    const every = Number(step.value?.data?.poll ?? 0);
    if (!every || step.value?.done) return;

    const gen = pollGen;
    const pollStep = step.value.step;
    pollTimer = setTimeout(async () => {
        pollTimer = null;
        try {
            const res = await axios({
                method: 'post',
                url: route('admin.capabilities.auth.submit', routeParams.value),
                data: { step: pollStep, input: { check: '1' } },
            });
            if (gen !== pollGen) return;
            if (res.data?.success && res.data.step) {
                applyStep(res.data.step);
            } else {
                requestError.value = res.data?.message ?? t('capabilities_auth_failed');
            }
        } catch (e) {
            if (gen !== pollGen) return;
            requestError.value = e.response?.data?.message ?? t('capabilities_auth_failed');
        }
    }, every * 1000);
};

const qrImage = ref(null);
watch(() => step.value?.data?.qr, async (url) => {
    if (!url) { qrImage.value = null; return; }
    try {
        qrImage.value = await QRCode.toDataURL(url, { margin: 1, width: 220, errorCorrectionLevel: 'M' });
    } catch {
        qrImage.value = null;
    }
});

onBeforeUnmount(stopPolling);

const logout = async () => {
    if (!confirm(t('capabilities_auth_logout_confirm'))) return;
    await request('delete');
    emit('logged-out');
};

onMounted(() => {
    if (props.ready) load();
});

watch(() => props.ready, (now) => {
    if (now) load();
    else { stopPolling(); step.value = null; requestError.value = null; }
});

const errorBoxClass = computed(() => [
    'p-3 rounded-lg text-sm',
    props.isDark ? 'bg-red-900 bg-opacity-50 text-red-200' : 'bg-red-50 text-red-700',
]);
</script>
