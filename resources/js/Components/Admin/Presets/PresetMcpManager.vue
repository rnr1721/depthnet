<template>
    <div
        :class="['p-6 rounded-xl border', isDark ? 'bg-gray-700 bg-opacity-50 border-gray-600' : 'bg-gray-50 border-gray-200']">

        <!-- New preset placeholder -->
        <div v-if="!preset?.id" class="text-center py-8">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <h4 :class="['text-sm font-medium mb-2', isDark ? 'text-gray-400' : 'text-gray-600']">
                {{ $t('mcp_save_preset_first') }}
            </h4>
            <p :class="['text-xs', isDark ? 'text-gray-500' : 'text-gray-500']">
                {{ $t('mcp_save_preset_first_desc') }}
            </p>
        </div>

        <!-- Main content -->
        <div v-else>
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h4 :class="['text-lg font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                        {{ $t('mcp_servers') }}
                    </h4>
                    <p :class="['text-xs mt-0.5', isDark ? 'text-gray-400' : 'text-gray-500']">
                        {{ $t('mcp_servers_desc') }}
                    </p>
                </div>
                <button type="button" @click="showAddForm = !showAddForm" :class="[
                    'inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-all hover:scale-105',
                    showAddForm
                        ? (isDark ? 'bg-gray-600 text-gray-200' : 'bg-gray-200 text-gray-700')
                        : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                ]">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            :d="showAddForm ? 'M6 18L18 6M6 6l12 12' : 'M12 4v16m8-8H4'" />
                    </svg>
                    {{ showAddForm ? $t('mcp_cancel') : $t('mcp_add_server') }}
                </button>
            </div>

            <!-- Add server form -->
            <Transition enter-active-class="transition ease-out duration-200"
                enter-from-class="transform opacity-0 -translate-y-2"
                enter-to-class="transform opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="transform opacity-100 translate-y-0"
                leave-to-class="transform opacity-0 -translate-y-2">
                <div v-if="showAddForm" class="mb-4 p-4 rounded-xl border"
                    :class="isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200'">
                    <h5 :class="['text-sm font-semibold mb-3', isDark ? 'text-white' : 'text-gray-900']">
                        {{ $t('mcp_new_server') }}
                    </h5>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div>
                            <label :class="labelClass">{{ $t('mcp_name') }}</label>
                            <input v-model="newServer.name" type="text" :class="inputClass"
                                :placeholder="$t('mcp_name_ph')" />
                        </div>
                        <div>
                            <label :class="labelClass">{{ $t('mcp_server_key') }}</label>
                            <input v-model="newServer.server_key" type="text" :class="inputClass"
                                :placeholder="$t('mcp_server_key_ph')" />
                            <p :class="['text-xs mt-1', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ $t('mcp_used_in_commands') }}
                                <code>[mcp {{ newServer.server_key || 'key' }}]...[/mcp]</code>
                            </p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label :class="labelClass">{{ $t('mcp_server_url') }}</label>
                        <input v-model="newServer.url" type="url" :class="inputClass"
                            placeholder="https://mcp.example.com/sse" />
                    </div>

                    <!-- Headers (optional) -->
                    <div class="mb-3">
                        <button type="button" @click="showHeaders = !showHeaders"
                            :class="['text-xs flex items-center gap-1', isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-500 hover:text-gray-700']">
                            <svg class="w-3 h-3 transition-transform" :class="showHeaders ? 'rotate-90' : ''"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 5l7 7-7 7" />
                            </svg>
                            {{ $t('mcp_auth_headers') }}
                        </button>
                        <div v-if="showHeaders" class="mt-2">
                            <div v-for="(header, index) in newServer.headers" :key="index" class="flex gap-2 mb-2">
                                <input v-model="header.key" type="text" :class="[inputClass, 'flex-1']"
                                    :placeholder="$t('mcp_header_name_ph')" />
                                <input v-model="header.value" type="text" :class="[inputClass, 'flex-1']"
                                    :placeholder="$t('mcp_header_value_ph')" />
                                <button type="button" @click="removeHeader(index)"
                                    :class="['px-2 rounded-lg text-xs', isDark ? 'bg-red-900 text-red-300 hover:bg-red-800' : 'bg-red-100 text-red-700 hover:bg-red-200']">
                                    ✕
                                </button>
                            </div>
                            <button type="button" @click="addHeader"
                                :class="['text-xs px-3 py-1 rounded-lg', isDark ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                                + {{ $t('mcp_add_header') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="addError" class="mb-3 text-xs text-red-500">{{ addError }}</div>

                    <div class="flex justify-end">
                        <button type="button" @click="addServer" :disabled="isAdding"
                            class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 hover:bg-indigo-700 text-white disabled:opacity-50 transition-all">
                            <svg v-if="isAdding" class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            {{ isAdding ? $t('mcp_connecting') : $t('mcp_add_and_connect') }}
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Loading -->
            <div v-if="isLoading" class="py-8 text-center">
                <svg class="w-6 h-6 mx-auto animate-spin" :class="isDark ? 'text-gray-400' : 'text-gray-500'"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </div>

            <!-- Server list -->
            <div v-else-if="servers.length > 0" class="space-y-3">
                <div v-for="server in servers" :key="server.id" class="p-4 rounded-xl border"
                    :class="isDark ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-200'">
                    <div class="flex items-start justify-between gap-3">
                        <!-- Server info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span :class="['text-sm font-semibold', isDark ? 'text-white' : 'text-gray-900']">
                                    {{ server.name }}
                                </span>
                                <code
                                    :class="['text-xs px-2 py-0.5 rounded', isDark ? 'bg-gray-700 text-indigo-300' : 'bg-indigo-50 text-indigo-700']">
                                    {{ server.server_key }}
                                </code>
                                <!-- Health badge -->
                                <span :class="[
                                    'text-xs px-2 py-0.5 rounded-full',
                                    server.health_status === 'ok'
                                        ? (isDark ? 'bg-green-900 text-green-200' : 'bg-green-100 text-green-800')
                                        : server.health_status === 'error'
                                            ? (isDark ? 'bg-red-900 text-red-200' : 'bg-red-100 text-red-800')
                                            : (isDark ? 'bg-gray-600 text-gray-300' : 'bg-gray-100 text-gray-600')
                                ]">
                                    {{ server.health_status === 'ok' ? $t('mcp_health_ok') : server.health_status ===
                                        'error' ? $t('mcp_health_error') : $t('mcp_health_unknown') }}
                                </span>
                                <!-- Agent badge -->
                                <span v-if="server.added_by_agent"
                                    :class="['text-xs px-2 py-0.5 rounded-full', isDark ? 'bg-yellow-900 text-yellow-200' : 'bg-yellow-100 text-yellow-800']">
                                    {{ $t('mcp_by_agent') }}
                                </span>
                                <!-- Disabled badge -->
                                <span v-if="!server.is_enabled"
                                    :class="['text-xs px-2 py-0.5 rounded-full', isDark ? 'bg-gray-700 text-gray-400' : 'bg-gray-200 text-gray-500']">
                                    {{ $t('mcp_disabled') }}
                                </span>
                            </div>
                            <p :class="['text-xs mt-1 truncate', isDark ? 'text-gray-400' : 'text-gray-500']">
                                {{ server.url }}
                            </p>
                            <!-- Error -->
                            <p v-if="server.last_error && server.health_status === 'error'"
                                class="text-xs mt-1 text-red-500 truncate">
                                {{ server.last_error }}
                            </p>
                            <!-- Tools -->
                            <div v-if="server.tools_count > 0" class="mt-2">
                                <button type="button" @click="toggleTools(server.id)"
                                    :class="['text-xs flex items-center gap-1', isDark ? 'text-gray-400 hover:text-gray-300' : 'text-gray-500 hover:text-gray-700']">
                                    <svg class="w-3 h-3 transition-transform"
                                        :class="expandedTools === server.id ? 'rotate-90' : ''" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 5l7 7-7 7" />
                                    </svg>
                                    {{ $t('mcp_tools_count', { count: server.tools_count }) }}
                                </button>
                                <div v-if="expandedTools === server.id" class="mt-2 space-y-1">
                                    <div v-for="tool in server.tools" :key="tool.name"
                                        :class="['text-xs p-2 rounded-lg', isDark ? 'bg-gray-700' : 'bg-gray-50']">
                                        <span
                                            :class="['font-mono font-medium', isDark ? 'text-indigo-300' : 'text-indigo-700']">
                                            {{ tool.name }}
                                        </span>
                                        <span v-if="tool.description"
                                            :class="['ml-2', isDark ? 'text-gray-400' : 'text-gray-500']">
                                            — {{ tool.description }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <!-- Ping -->
                            <button type="button" @click="pingServer(server)" :disabled="pingingId === server.id"
                                :title="$t('mcp_ping_refresh')" :class="actionButtonClass">
                                <svg class="w-4 h-4" :class="{ 'animate-spin': pingingId === server.id }" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                            <!-- Toggle enabled -->
                            <button type="button" @click="toggleServer(server)"
                                :title="server.is_enabled ? $t('mcp_disable') : $t('mcp_enable')"
                                :class="actionButtonClass">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        :d="server.is_enabled
                                            ? 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21'
                                            : 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'" />
                                </svg>
                            </button>
                            <!-- Delete -->
                            <button type="button" @click="deleteServer(server)" :title="$t('mcp_remove_server')"
                                :class="[actionButtonClass, isDark ? 'hover:bg-red-900 hover:text-red-300' : 'hover:bg-red-100 hover:text-red-700']">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="py-10 text-center">
                <svg class="w-10 h-10 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <p :class="['text-sm', isDark ? 'text-gray-400' : 'text-gray-500']">
                    {{ $t('mcp_no_servers') }}
                </p>
                <p :class="['text-xs mt-1', isDark ? 'text-gray-500' : 'text-gray-400']">
                    {{ $t('mcp_no_servers_hint') }}
                </p>
            </div>

            <!-- Usage hint -->
            <div v-if="servers.length > 0" class="mt-4 p-3 rounded-lg" :class="isDark ? 'bg-gray-800' : 'bg-gray-100'">
                <p :class="['text-xs font-medium mb-1', isDark ? 'text-gray-300' : 'text-gray-700']">
                    {{ $t('mcp_usage_hint') }}
                </p>
                <code :class="['text-xs block', isDark ? 'text-indigo-300' : 'text-indigo-700']">
                    [mcp {{ servers[0]?.server_key }}]tool_name: {"arg": "value"}[/mcp]
                </code>
                <code :class="['text-xs block mt-1', isDark ? 'text-indigo-300' : 'text-indigo-700']">
                    [mcp list][/mcp]  — {{ $t('mcp_usage_list_desc') }}
                </code>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t: $t } = useI18n();

const props = defineProps({
    preset: Object,
    isDark: Boolean,
});

const emit = defineEmits(['error', 'success']);

// ── State ─────────────────────────────────────────────────────────────────────
const servers = ref([]);
const isLoading = ref(false);
const isAdding = ref(false);
const pingingId = ref(null);
const showAddForm = ref(false);
const showHeaders = ref(false);
const expandedTools = ref(null);
const addError = ref('');

const newServer = ref(resetNewServer());

// ── Computed ──────────────────────────────────────────────────────────────────
const inputClass = computed(() => [
    'w-full rounded-xl border-0 ring-1 ring-inset focus:ring-2 focus:ring-indigo-500 transition-all px-4 py-3 text-sm',
    props.isDark
        ? 'bg-gray-600 text-white ring-gray-500 placeholder-gray-400'
        : 'bg-white text-gray-900 ring-gray-300 placeholder-gray-500',
]);

const labelClass = computed(() => [
    'block text-xs font-medium mb-1',
    props.isDark ? 'text-gray-300' : 'text-gray-700',
]);

const actionButtonClass = computed(() => [
    'p-2 rounded-lg transition-all',
    props.isDark
        ? 'text-gray-400 hover:bg-gray-700 hover:text-gray-200'
        : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700',
]);

// ── Methods ───────────────────────────────────────────────────────────────────
function resetNewServer() {
    return { name: '', server_key: '', url: '', headers: [] };
}

function addHeader() {
    newServer.value.headers.push({ key: '', value: '' });
}

function removeHeader(index) {
    newServer.value.headers.splice(index, 1);
}

function toggleTools(serverId) {
    expandedTools.value = expandedTools.value === serverId ? null : serverId;
}

async function loadServers() {
    if (!props.preset?.id) return;
    isLoading.value = true;
    try {
        const res = await axios.get(`/admin/presets/${props.preset.id}/mcp`);
        if (res.data.success) {
            servers.value = res.data.data.servers;
        }
    } catch (e) {
        emit('error', $t('mcp_load_failed'));
    } finally {
        isLoading.value = false;
    }
}

async function addServer() {
    addError.value = '';

    if (!newServer.value.name || !newServer.value.server_key || !newServer.value.url) {
        addError.value = $t('mcp_fields_required');
        return;
    }

    // Build headers object from array
    const headers = {};
    for (const h of newServer.value.headers) {
        if (h.key && h.value) headers[h.key] = h.value;
    }

    isAdding.value = true;
    try {
        const res = await axios.post(`/admin/presets/${props.preset.id}/mcp`, {
            name: newServer.value.name,
            server_key: newServer.value.server_key,
            url: newServer.value.url,
            headers,
        });

        if (res.data.success) {
            servers.value.unshift(res.data.data.server);
            newServer.value = resetNewServer();
            showAddForm.value = false;
            showHeaders.value = false;
            emit('success', $t('mcp_server_added', { name: res.data.data.server.name }));
        }
    } catch (e) {
        addError.value = e.response?.data?.message || $t('mcp_add_failed');
    } finally {
        isAdding.value = false;
    }
}

async function deleteServer(server) {
    if (!confirm($t('mcp_confirm_remove', { name: server.name }))) return;

    try {
        await axios.delete(`/admin/presets/${props.preset.id}/mcp/${server.id}`);
        servers.value = servers.value.filter(s => s.id !== server.id);
        emit('success', $t('mcp_server_removed', { name: server.name }));
    } catch (e) {
        emit('error', e.response?.data?.message || $t('mcp_remove_failed'));
    }
}

async function toggleServer(server) {
    try {
        const res = await axios.patch(`/admin/presets/${props.preset.id}/mcp/${server.id}/toggle`);
        if (res.data.success) {
            const idx = servers.value.findIndex(s => s.id === server.id);
            if (idx !== -1) servers.value[idx] = res.data.data.server;
        }
    } catch (e) {
        emit('error', $t('mcp_toggle_failed'));
    }
}

async function pingServer(server) {
    pingingId.value = server.id;
    try {
        const res = await axios.post(`/admin/presets/${props.preset.id}/mcp/${server.id}/ping`);
        if (res.data.success) {
            const idx = servers.value.findIndex(s => s.id === server.id);
            if (idx !== -1) servers.value[idx] = res.data.data.server;
            const count = res.data.data.server.tools_count;
            emit('success', $t('mcp_ping_success', { count }));
        }
    } catch (e) {
        const idx = servers.value.findIndex(s => s.id === server.id);
        if (idx !== -1) servers.value[idx] = { ...servers.value[idx], health_status: 'error', last_error: e.response?.data?.message || $t('mcp_connection_failed') };
        emit('error', e.response?.data?.message || $t('mcp_ping_failed'));
    } finally {
        pingingId.value = null;
    }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(() => {
    if (props.preset?.id) loadServers();
});

watch(() => props.preset?.id, (newId) => {
    if (newId) loadServers();
    else servers.value = [];
});
</script>