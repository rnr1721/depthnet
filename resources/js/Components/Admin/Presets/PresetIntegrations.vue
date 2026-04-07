<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                {{ t('integrations_title') }}
            </h4>
        </div>

        <!-- Tabs -->
        <div class="flex space-x-1 mb-6 p-1 rounded-xl w-fit" :class="isDark ? 'bg-gray-800' : 'bg-gray-200'">
            <button v-for="tab in tabs" :key="tab.id" type="button" @click="activeTab = tab.id" :class="[
                'flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all',
                activeTab === tab.id
                    ? (isDark ? 'bg-gray-600 text-white shadow' : 'bg-white text-gray-900 shadow')
                    : (isDark ? 'text-gray-400 hover:text-gray-200' : 'text-gray-500 hover:text-gray-700')
            ]">
                <!-- Status dot -->
                <span :class="[
                    'w-2 h-2 rounded-full flex-shrink-0',
                    isTabEnabled(tab.id) ? 'bg-green-400' : (isDark ? 'bg-gray-600' : 'bg-gray-300')
                ]" />
                {{ tab.label }}
            </button>
        </div>

        <!-- Tab: Rhasspy -->
        <div v-if="activeTab === 'rhasspy'" class="space-y-6">

            <!-- Enable toggle -->
            <div
                :class="['flex items-start justify-between p-4 rounded-xl', isDark ? 'bg-gray-800 bg-opacity-60' : 'bg-white border border-gray-200']">
                <div>
                    <div :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('rhasspy_enable_label') }}
                    </div>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('rhasspy_enable_desc') }}
                    </p>
                </div>
                <button type="button" @click="updateField('rhasspy_enabled', !modelValue.rhasspy_enabled)" :class="[
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0 ml-4',
                    modelValue.rhasspy_enabled ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-300')
                ]">
                    <span :class="[
                        'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                        modelValue.rhasspy_enabled ? 'translate-x-6' : 'translate-x-1'
                    ]" />
                </button>
            </div>

            <!-- Fields (shown even when disabled, just dimmed) -->
            <div :class="{ 'opacity-50 pointer-events-none': !modelValue.rhasspy_enabled }">

                <!-- URL -->
                <div class="mb-4">
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('rhasspy_url_label') }}
                    </label>
                    <div class="flex gap-2">
                        <input :value="modelValue.rhasspy_url" @input="updateField('rhasspy_url', $event.target.value)"
                            type="url" :class="inputClass" placeholder="http://192.168.1.100:12101" />
                        <button type="button" @click="pingRhasspy" :disabled="isPinging || !modelValue.rhasspy_url"
                            :class="[
                                'flex-shrink-0 px-4 py-2 rounded-xl text-sm font-medium transition-all',
                                isPinging
                                    ? (isDark ? 'bg-gray-600 text-gray-400' : 'bg-gray-200 text-gray-400')
                                    : (isDark ? 'bg-gray-600 hover:bg-gray-500 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-700')
                            ]">
                            <span v-if="isPinging" class="flex items-center gap-1">
                                <svg class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                {{ t('rhasspy_pinging') }}
                            </span>
                            <span v-else>{{ t('rhasspy_ping') }}</span>
                        </button>
                    </div>
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('rhasspy_url_desc') }}
                    </p>
                    <div v-if="pingResult" :class="[
                        'mt-2 p-2 rounded-lg text-xs',
                        pingResult.success
                            ? (isDark ? 'bg-green-900 bg-opacity-50 text-green-300' : 'bg-green-50 text-green-700')
                            : (isDark ? 'bg-red-900 bg-opacity-50 text-red-300' : 'bg-red-50 text-red-700')
                    ]">
                        {{ pingResult.message }}
                    </div>
                </div>

                <!-- TTS Voice -->
                <div class="mb-4">
                    <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                        {{ t('rhasspy_tts_voice_label') }}
                    </label>
                    <input :value="modelValue.rhasspy_tts_voice"
                        @input="updateField('rhasspy_tts_voice', $event.target.value)" type="text" :class="inputClass"
                        :placeholder="t('rhasspy_tts_voice_ph')" />
                    <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ t('rhasspy_tts_voice_desc') }}
                    </p>
                </div>

                <!-- Divider: Incoming -->
                <div class="border-t pt-5 mt-5" :class="isDark ? 'border-gray-600' : 'border-gray-200'">
                    <div
                        :class="['flex items-start justify-between p-4 rounded-xl mb-4', isDark ? 'bg-gray-800 bg-opacity-60' : 'bg-white border border-gray-200']">
                        <div>
                            <div :class="['font-medium text-sm', isDark ? 'text-white' : 'text-gray-900']">
                                {{ t('rhasspy_incoming_label') }}
                            </div>
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ t('rhasspy_incoming_desc') }}
                            </p>
                        </div>
                        <button type="button"
                            @click="updateField('rhasspy_incoming_enabled', !modelValue.rhasspy_incoming_enabled)"
                            :class="[
                                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0 ml-4',
                                modelValue.rhasspy_incoming_enabled ? 'bg-indigo-600' : (isDark ? 'bg-gray-600' : 'bg-gray-300')
                            ]">
                            <span :class="[
                                'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                modelValue.rhasspy_incoming_enabled ? 'translate-x-6' : 'translate-x-1'
                            ]" />
                        </button>
                    </div>

                    <!-- Token -->
                    <div :class="{ 'opacity-50 pointer-events-none': !modelValue.rhasspy_incoming_enabled }">
                        <label :class="['block text-sm font-medium mb-2', isDark ? 'text-white' : 'text-gray-900']">
                            {{ t('rhasspy_token_label') }}
                        </label>
                        <div class="flex gap-2">
                            <input :value="modelValue.rhasspy_incoming_token"
                                @input="updateField('rhasspy_incoming_token', $event.target.value)"
                                :type="showToken ? 'text' : 'password'" :class="inputClass"
                                :placeholder="t('rhasspy_token_ph')" />
                            <button type="button" @click="showToken = !showToken" :class="[
                                'flex-shrink-0 px-3 py-2 rounded-xl text-sm transition-all',
                                isDark ? 'bg-gray-600 hover:bg-gray-500 text-gray-300' : 'bg-gray-200 hover:bg-gray-300 text-gray-600'
                            ]">
                                <svg v-if="showToken" class="w-4 h-4" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                            <button type="button" @click="generateToken" :class="[
                                'flex-shrink-0 px-3 py-2 rounded-xl text-sm transition-all whitespace-nowrap',
                                isDark ? 'bg-gray-600 hover:bg-gray-500 text-gray-300' : 'bg-gray-200 hover:bg-gray-300 text-gray-600'
                            ]" :title="t('rhasspy_token_generate')">
                                {{ t('rhasspy_token_generate') }}
                            </button>
                        </div>
                        <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                            {{ t('rhasspy_token_desc') }}
                        </p>

                        <!-- Endpoint info -->
                        <div v-if="presetId && modelValue.rhasspy_incoming_enabled && modelValue.rhasspy_incoming_token"
                            :class="['mt-3 p-3 rounded-lg font-mono text-xs break-all', isDark ? 'bg-gray-900 text-gray-300' : 'bg-gray-100 text-gray-700']">
                            <div class="flex items-center justify-between mb-1">
                                <span
                                    :class="['text-xs font-sans font-medium', isDark ? 'text-gray-400' : 'text-gray-500']">
                                    {{ t('rhasspy_endpoint_label') }}
                                </span>
                                <button type="button" @click="copyEndpoint"
                                    class="text-xs text-indigo-400 hover:text-indigo-300">
                                    {{ endpointCopied ? t('rhasspy_copied') : t('rhasspy_copy') }}
                                </button>
                            </div>
                            POST {{ endpointUrl }}
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Future tabs placeholder -->
        <!-- <div v-if="activeTab === 'telegram'"> ... </div> -->

    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Object,
        required: true
    },
    isDark: {
        type: Boolean,
        default: false
    },
    errors: {
        type: Object,
        default: () => ({})
    },
    presetId: {
        type: Number,
        default: null
    }
});

const emit = defineEmits(['update:modelValue', 'success', 'error']);

// State
const activeTab = ref('rhasspy');
const showToken = ref(false);
const isPinging = ref(false);
const pingResult = ref(null);
const endpointCopied = ref(false);

const tabs = computed(() => [
    { id: 'rhasspy', label: 'Rhasspy' },
    // { id: 'telegram', label: 'Telegram' }, // следующая интеграция
]);

// Computed
const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3',
    props.isDark
        ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400'
        : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500'
]);

const endpointUrl = computed(() => {
    const base = window.location.origin;
    return `${base}/api/v1/rhasspy/presets/${props.presetId}/speech`;
});

// Methods
const updateField = (field, value) => {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
};

const isTabEnabled = (tabId) => {
    if (tabId === 'rhasspy') return props.modelValue.rhasspy_enabled;
    return false;
};

const generateToken = () => {
    // Generate a random token client-side
    const array = new Uint8Array(32);
    crypto.getRandomValues(array);
    const token = Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
    updateField('rhasspy_incoming_token', token);
    showToken.value = true;
};

const pingRhasspy = async () => {
    if (!props.modelValue.rhasspy_url) return;

    isPinging.value = true;
    pingResult.value = null;

    try {
        // If preset is saved (has ID) — use our own ping endpoint with token auth
        // If not saved yet — try to reach Rhasspy /api/version directly
        if (props.presetId && props.modelValue.rhasspy_incoming_token) {
            const response = await fetch(
                `/api/v1/rhasspy/presets/${props.presetId}/ping`,
                {
                    headers: {
                        'Authorization': `Bearer ${props.modelValue.rhasspy_incoming_token}`,
                        'Accept': 'application/json',
                    }
                }
            );
            pingResult.value = response.ok
                ? { success: true, message: t('rhasspy_ping_ok') }
                : { success: false, message: t('rhasspy_ping_fail') };
        } else {
            // Direct ping to Rhasspy /api/version — works only if CORS allows it
            // In practice this may fail due to CORS from the browser,
            // but it's a best-effort check before the preset is saved.
            const url = props.modelValue.rhasspy_url.replace(/\/$/, '') + '/api/version';
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 4000);
            try {
                const response = await fetch(url, { signal: controller.signal });
                clearTimeout(timeout);
                pingResult.value = response.ok
                    ? { success: true, message: t('rhasspy_ping_ok') }
                    : { success: false, message: t('rhasspy_ping_fail') };
            } catch {
                clearTimeout(timeout);
                pingResult.value = { success: false, message: t('rhasspy_ping_fail_cors') };
            }
        }
    } catch {
        pingResult.value = { success: false, message: t('rhasspy_ping_fail') };
    } finally {
        isPinging.value = false;
    }
};

const copyEndpoint = async () => {
    try {
        await navigator.clipboard.writeText(endpointUrl.value);
        endpointCopied.value = true;
        setTimeout(() => { endpointCopied.value = false; }, 2000);
    } catch {
        endpointCopied.value = false;
    }
};
</script>