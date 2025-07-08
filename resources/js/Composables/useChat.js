import { ref, onBeforeUnmount } from 'vue';
import axios from 'axios';

const MESSAGES_PER_PAGE = 30;

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

    const allLoadedMessageIds = ref(new Set());

    let refreshInterval = null;
    let usersRefreshInterval = null;

    /**
     * Load latest messages (last page - newest messages)
     * 
     * @param {number|null} presetId 
     * @param {boolean} showLoading - Whether to show initial loading state
     */
    async function loadLatestMessages(presetId = null, showLoading = true) {
        try {
            if (showLoading) {
                isInitialLoading.value = true;
            } else {
                isBackgroundRefreshing.value = true;
            }

            // Use provided presetId or current one
            const targetPresetId = presetId || currentPresetId.value;

            const response = await axios.get(route('chat.latest-messages'), {
                params: {
                    preset_id: targetPresetId,
                    per_page: MESSAGES_PER_PAGE
                }
            });

            if (response.data.success) {
                // Reset state when loading latest messages
                localMessages.value = response.data.messages || [];
                pagination.value = response.data.pagination || null;

                // Track loaded message IDs
                allLoadedMessageIds.value = new Set(
                    localMessages.value.map(msg => msg.id)
                );

                if (response.data.presetMetadata) {
                    presetMetadata.value = response.data.presetMetadata;
                }

                // Update current preset ID if it was provided
                if (presetId) {
                    currentPresetId.value = presetId;
                }
            } else {
                console.error('Failed to load messages:', response.data.error);
                localMessages.value = [];
                pagination.value = null;
            }
        } catch (error) {
            console.error('Error loading latest messages:', error);
            localMessages.value = [];
            pagination.value = null;
            allLoadedMessageIds.value = new Set();
        } finally {
            if (showLoading) {
                isInitialLoading.value = false;
            } else {
                isBackgroundRefreshing.value = false;
            }
        }
    }

    const isLoadingOlder = ref(false);

    /**
     * Load older messages (previous pages)
     * @returns 
     */
    async function loadOlderMessages() {
        if (!pagination.value || !pagination.value.has_more_pages) {
            return;
        }

        if (isLoadingOlder.value) {
            return;
        }

        try {
            isLoadingOlder.value = true;
            const previousPage = pagination.value.current_page - 1;

            if (previousPage < 1) {
                return;
            }

            const response = await axios.get(route('chat.older-messages'), {
                params: {
                    page: previousPage,
                    per_page: MESSAGES_PER_PAGE,
                    preset_id: currentPresetId.value // Add preset_id to load older messages request
                }
            });

            if (response.data.success) {
                const newMessages = response.data.messages || [];

                const uniqueNewMessages = newMessages.filter(
                    msg => !allLoadedMessageIds.value.has(msg.id)
                );

                if (uniqueNewMessages.length > 0) {
                    // Add to BEGINNING (older messages come first chronologically)
                    localMessages.value = [...uniqueNewMessages, ...localMessages.value];

                    // Update ID tracking
                    uniqueNewMessages.forEach(msg => allLoadedMessageIds.value.add(msg.id));
                }

                // Update pagination state
                pagination.value = response.data.pagination;

            } else {
                console.error('Failed to load older messages:', response.data.error);
            }
        } catch (error) {
            console.error('Error loading older messages:', error);
        } finally {
            isLoadingOlder.value = false;
        }
    }

    /**
     * Refresh messages (for auto-refresh)
     * Loads only NEW messages after the last loaded message
     * 
     * @returns 
     */
    async function refreshMessages() {
        if (isInitialLoading.value) {
            return;
        }

        try {
            // If no messages loaded, load latest instead of trying to refresh
            // But don't show loading spinner - do it in background
            if (localMessages.value.length === 0) {
                // console.log('No messages loaded, loading latest in background');
                await loadLatestMessages(null, false);
                return;
            }

            isBackgroundRefreshing.value = true;

            // Find ID of last message
            const lastMessageId = Math.max(...localMessages.value.map(msg => msg.id));

            // Safety check for valid ID
            if (!lastMessageId || lastMessageId <= 0) {
                console.log('Invalid last message ID, reloading latest messages in background');
                await loadLatestMessages(null, false);
                return;
            }

            const response = await axios.get(route('chat.new-messages', lastMessageId), {
                params: {
                    limit: 50,
                    preset_id: currentPresetId.value // Add preset_id to refresh request
                }
            });

            if (response.data.messages && response.data.messages.length > 0) {
                const newMessages = response.data.messages;

                // Filter duplicates
                const uniqueNewMessages = newMessages.filter(
                    msg => !allLoadedMessageIds.value.has(msg.id)
                );

                if (uniqueNewMessages.length > 0) {
                    // Add new messages to END of array
                    localMessages.value = [...localMessages.value, ...uniqueNewMessages];

                    // Update ID tracking
                    uniqueNewMessages.forEach(msg => allLoadedMessageIds.value.add(msg.id));

                    // Update hasUnreadMessages only if user is not at bottom
                    if (!isUserAtBottom.value) {
                        hasUnreadMessages.value = true;
                    }
                }

                if (response.data.presetMetadata) {
                    presetMetadata.value = response.data.presetMetadata;
                }
            }
        } catch (error) {
            console.error('Error refreshing messages:', error);

            // If refresh fails completely, try to reload latest messages as fallback
            if (localMessages.value.length === 0) {
                // console.log('Refresh failed and no messages loaded, attempting to load latest in background');
                try {
                    await loadLatestMessages(null, false);
                } catch (fallbackError) {
                    console.error('Fallback load also failed:', fallbackError);
                }
            }
        } finally {
            isBackgroundRefreshing.value = false;
        }
    }

    /**
     * Switch to different preset
     * 
     * @param {number} presetId 
     * @returns 
     */
    async function switchPreset(presetId) {
        try {
            console.log(`Switching to preset ${presetId} from ${currentPresetId.value}`);

            // Complete state reset
            localMessages.value = [];
            pagination.value = null;
            allLoadedMessageIds.value = new Set();
            hasUnreadMessages.value = false;
            isLoadingOlder.value = false;

            // Update current preset ID BEFORE loading messages
            currentPresetId.value = presetId;

            // Load messages for new preset
            await loadLatestMessages(presetId, true);

            // console.log(`Successfully switched to preset ${presetId}`);
            return true;
        } catch (error) {
            console.error('Failed to switch preset:', error);
            return false;
        }
    }

    /**
     * Reset chat state completely (for after clearing history)
     */
    function resetChatState() {
        // Clear all state
        localMessages.value = [];
        pagination.value = null;
        allLoadedMessageIds.value = new Set();
        hasUnreadMessages.value = false;
        isLoadingOlder.value = false;

        // Reset user position
        isUserAtBottom.value = true;
        shouldAutoRefresh.value = true;

        console.log('Chat state reset completely');
    }

    /**
     * Start frequent refresh for new messages
     */
    function startFrequentRefresh() {
        stopFrequentRefresh(); // Clear any existing interval

        refreshInterval = setInterval(() => {
            if (shouldAutoRefresh.value) {
                refreshMessages().catch(error => {
                    console.error('Auto-refresh failed:', error);
                });
            }
        }, 5000); // Every 5 seconds
    }

    /**
     * Stop frequent refresh
     */
    function stopFrequentRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }

    /**
     * Start users refresh
     */
    function startUsersRefresh() {
        stopUsersRefresh(); // Clear any existing interval

        usersRefreshInterval = setInterval(async () => {
            try {
                const response = await axios.get(route('chat.users'));
                if (response.data && Array.isArray(response.data)) {
                    localUsers.value = response.data;
                }
            } catch (error) {
                console.error('Users refresh failed:', error);
            }
        }, 30000); // 30 seconds
    }

    /**
     * Stop users refresh
     */
    function stopUsersRefresh() {
        if (usersRefreshInterval) {
            clearInterval(usersRefreshInterval);
            usersRefreshInterval = null;
        }
    }

    /**
     * Cleanup function
     */
    function cleanup() {
        stopFrequentRefresh();
        stopUsersRefresh();
    }

    // Auto-cleanup on unmount
    onBeforeUnmount(() => {
        cleanup();
    });

    return {
        // State
        localMessages,
        pagination,
        isInitialLoading,
        isBackgroundRefreshing,
        isProcessing,
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
        switchPreset,
        resetChatState,
        startFrequentRefresh,
        stopFrequentRefresh,
        startUsersRefresh,
        stopUsersRefresh,
        cleanup
    };
}
