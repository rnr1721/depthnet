import { ref, onBeforeUnmount } from 'vue';
import axios from 'axios';

const MESSAGES_PER_PAGE = 30;
const REFRESH_INTERVAL_MS = 5000;
const USERS_REFRESH_INTERVAL_MS = 30000;
const STATUS_FAST_POLL_MS = 2000;
const ACTIVE_STATES = ['working', 'queued', 'stopping'];

/**
 * Chat state + polling.
 *
 * Changes vs previous version:
 *  - ONE polling interval (Index.vue used to create its own on top of
 *    startFrequentRefresh() -> two parallel pollers after the first message).
 *  - "generation" counter: a response that belongs to a preset the user has
 *    already switched away from is dropped (previously a slow response could
 *    append old-preset messages into the new preset).
 *  - In-flight guard for refresh: no overlapping requests on slow networks.
 *  - Routine polling does NOT toggle isBackgroundRefreshing (the indicator was
 *    appearing/disappearing every 5s and shifted the layout).
 *  - Polling pauses while the tab is hidden and catches up when it's visible.
 */
export function useChat(props) {
    const localMessages = ref([]);
    const pagination = ref(null);
    const isInitialLoading = ref(true);
    const isBackgroundRefreshing = ref(false);
    const isProcessing = ref(false);
    const localUsers = ref([]);
    const isUserAtBottom = ref(true);
    const shouldAutoRefresh = ref(true);
    const hasUnreadMessages = ref(false);
    const presetMetadata = ref({});
    const currentPresetId = ref(props.currentPresetId);
    const isLoadingOlder = ref(false);
    const agentStatus = ref(null);

    const allLoadedMessageIds = ref(new Set());

    let refreshInterval = null;
    let usersRefreshInterval = null;
    let generation = 0;          // bumped on every preset switch / reset
    let refreshInFlight = false;
    let statusTimer = null;

    // ── Agent status ──────────────────────────────────────────────────────
    // Arrives with every message poll (5s). While the agent is busy, an extra
    // lightweight poll of chat/status runs every 2s so phases feel live.

    function applyStatus(status) {
        if (!status) return;
        agentStatus.value = status;
        scheduleStatusPoll();
    }

    function scheduleStatusPoll() {
        if (statusTimer) return;
        if (!ACTIVE_STATES.includes(agentStatus.value?.state)) return;
        statusTimer = setTimeout(async () => {
            statusTimer = null;
            await fetchStatus();
        }, STATUS_FAST_POLL_MS);
    }

    async function fetchStatus() {
        if (typeof document !== 'undefined' && document.hidden) {
            scheduleStatusPoll();
            return;
        }
        const myGen = generation;
        try {
            const { data } = await axios.get(route('chat.status'), {
                params: { preset_id: currentPresetId.value },
            });
            if (myGen === generation) applyStatus(data);
        } catch {
            // status is best-effort; next message poll will bring it again
        }
    }

    function clearStatus() {
        if (statusTimer) clearTimeout(statusTimer);
        statusTimer = null;
        agentStatus.value = null;
    }

    function lastMessageId() {
        let max = 0;
        for (const m of localMessages.value) if (m.id > max) max = m.id;
        return max;
    }

    function appendUnique(messages, { prepend = false } = {}) {
        const unique = (messages || []).filter(m => !allLoadedMessageIds.value.has(m.id));
        if (!unique.length) return unique;
        unique.forEach(m => allLoadedMessageIds.value.add(m.id));
        localMessages.value = prepend
            ? [...unique, ...localMessages.value]
            : [...localMessages.value, ...unique];
        return unique;
    }

    /**
     * Load latest messages (last page).
     * @param {number|null} presetId
     * @param {boolean} showLoading - full-screen spinner vs quiet background load
     */
    async function loadLatestMessages(presetId = null, showLoading = true) {
        const myGen = generation;
        const targetPresetId = presetId || currentPresetId.value;

        if (showLoading) isInitialLoading.value = true;
        else isBackgroundRefreshing.value = true;

        try {
            const response = await axios.get(route('chat.latest-messages'), {
                params: { preset_id: targetPresetId, per_page: MESSAGES_PER_PAGE },
            });

            if (myGen !== generation) return; // stale: preset switched meanwhile

            if (response.data.success) {
                localMessages.value = response.data.messages || [];
                pagination.value = response.data.pagination || null;
                allLoadedMessageIds.value = new Set(localMessages.value.map(m => m.id));
                if (response.data.presetMetadata) presetMetadata.value = response.data.presetMetadata;
                if (presetId) currentPresetId.value = presetId;
                applyStatus(response.data.agentStatus);
            } else {
                console.error('Failed to load messages:', response.data.error);
                localMessages.value = [];
                pagination.value = null;
            }
        } catch (error) {
            if (myGen !== generation) return;
            console.error('Error loading latest messages:', error);
            localMessages.value = [];
            pagination.value = null;
            allLoadedMessageIds.value = new Set();
        } finally {
            if (showLoading) isInitialLoading.value = false;
            else isBackgroundRefreshing.value = false;
        }
    }

    /**
     * Load the previous page and prepend it.
     * @returns {Promise<number>} number of prepended messages
     */
    async function loadOlderMessages() {
        if (!pagination.value?.has_more_pages || isLoadingOlder.value) return 0;

        const previousPage = pagination.value.current_page - 1;
        if (previousPage < 1) return 0;

        const myGen = generation;
        isLoadingOlder.value = true;

        try {
            const response = await axios.get(route('chat.older-messages'), {
                params: { page: previousPage, per_page: MESSAGES_PER_PAGE, preset_id: currentPresetId.value },
            });

            if (myGen !== generation) return 0;

            if (response.data.success) {
                const added = appendUnique(response.data.messages, { prepend: true });
                pagination.value = response.data.pagination;
                return added.length;
            }
            console.error('Failed to load older messages:', response.data.error);
        } catch (error) {
            console.error('Error loading older messages:', error);
        } finally {
            isLoadingOlder.value = false;
        }
        return 0;
    }

    /**
     * Fetch only messages newer than the last loaded one.
     */
    async function refreshMessages() {
        if (isInitialLoading.value || refreshInFlight) return;
        if (typeof document !== 'undefined' && document.hidden) return;

        const myGen = generation;
        refreshInFlight = true;

        try {
            const lastId = lastMessageId();
            if (!lastId) {
                // Nothing loaded yet (empty chat): quiet reload of the latest page
                await loadLatestMessages(null, false);
                return;
            }

            const response = await axios.get(route('chat.new-messages', lastId), {
                params: { limit: 50, preset_id: currentPresetId.value },
            });

            if (myGen !== generation) return;

            const newMessages = response.data.messages || [];
            if (newMessages.length) {
                const added = appendUnique(newMessages);
                if (added.length && !isUserAtBottom.value) hasUnreadMessages.value = true;
            }
            if (response.data.presetMetadata) presetMetadata.value = response.data.presetMetadata;
            applyStatus(response.data.agentStatus);
        } catch (error) {
            if (error.code === 'ECONNABORTED' || error.name === 'CanceledError') return;
            console.error('Error refreshing messages:', error);
        } finally {
            refreshInFlight = false;
        }
    }

    async function switchPreset(presetId) {
        try {
            generation++;
            clearStatus();
            localMessages.value = [];
            pagination.value = null;
            allLoadedMessageIds.value = new Set();
            hasUnreadMessages.value = false;
            isLoadingOlder.value = false;
            isUserAtBottom.value = true;
            shouldAutoRefresh.value = true;
            currentPresetId.value = presetId;

            await loadLatestMessages(presetId, true);
            return true;
        } catch (error) {
            console.error('Failed to switch preset:', error);
            return false;
        }
    }

    /** Reset chat state completely (after clearing history). */
    function resetChatState() {
        generation++;
        clearStatus();
        localMessages.value = [];
        pagination.value = null;
        allLoadedMessageIds.value = new Set();
        hasUnreadMessages.value = false;
        isLoadingOlder.value = false;
        isUserAtBottom.value = true;
        shouldAutoRefresh.value = true;
    }

    function onVisibilityChange() {
        if (!document.hidden) refreshMessages();
    }

    /**
     * Start polling. Idempotent: calling it again just keeps a single interval.
     * Polls regardless of scroll position so "unread" badge works.
     */
    function startFrequentRefresh() {
        if (refreshInterval) return;
        refreshInterval = setInterval(() => {
            refreshMessages().catch(e => console.error('Auto-refresh failed:', e));
        }, REFRESH_INTERVAL_MS);
        document.addEventListener('visibilitychange', onVisibilityChange);
    }

    function stopFrequentRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    function startUsersRefresh() {
        stopUsersRefresh();
        usersRefreshInterval = setInterval(async () => {
            if (document.hidden) return;
            try {
                const response = await axios.get(route('chat.users'));
                if (Array.isArray(response.data)) localUsers.value = response.data;
            } catch (error) {
                console.error('Users refresh failed:', error);
            }
        }, USERS_REFRESH_INTERVAL_MS);
    }

    function stopUsersRefresh() {
        if (usersRefreshInterval) {
            clearInterval(usersRefreshInterval);
            usersRefreshInterval = null;
        }
    }

    function cleanup() {
        stopFrequentRefresh();
        stopUsersRefresh();
        clearStatus();
    }

    onBeforeUnmount(cleanup);

    return {
        // State
        localMessages,
        pagination,
        isInitialLoading,
        isBackgroundRefreshing,
        isProcessing,
        isLoadingOlder,
        agentStatus,
        localUsers,
        isUserAtBottom,
        shouldAutoRefresh,
        hasUnreadMessages,
        presetMetadata,
        currentPresetId,

        // Debug state
        allLoadedMessageIds,

        // Functions
        loadLatestMessages,
        loadOlderMessages,
        refreshMessages,
        fetchStatus,
        switchPreset,
        resetChatState,
        startFrequentRefresh,
        stopFrequentRefresh,
        startUsersRefresh,
        stopUsersRefresh,
        cleanup,
    };
}
