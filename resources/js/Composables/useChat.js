/**
 * Refresh messages and handle unread state
 */
export function refreshMessagesWithUnread() {
    let lastId = 0;
    if (localMessages.value.length > 0) {
        lastId = Math.max(...localMessages.value.map(msg => msg.id));
    }

    axios.get(route('chat.new-messages', { lastId }))
        .then(response => {
            const data = response.data;
            const newMessages = data.messages || data;

            if (newMessages.length > 0) {
                const hadNewVisibleMessages = newMessages.some(msg => msg.role === 'assistant' && msg.is_visible_to_user);

                newMessages.forEach(newMsg => {
                    const existingIndex = localMessages.value.findIndex(msg => msg.id === newMsg.id);

                    if (existingIndex >= 0) {
                        localMessages.value[existingIndex] = newMsg;
                    } else {
                        localMessages.value.push(newMsg);
                    }
                });

                if (hadNewVisibleMessages) {
                    isProcessing.value = false;

                    if (!shouldAutoRefresh.value) {
                        hasUnreadMessages.value = true;
                    }
                }
            }

            if (data.presetMetadata) {
                presetMetadata.value = data.presetMetadata;
            }
        })
        .catch(error => {
            console.error('Error fetching new messages:', error);
        });
} import { ref } from 'vue';
import axios from 'axios';

/**
 * Composable for chat functionality
 * @param {Object} props - Component props
 * @returns {Object} Chat state and methods
 */
export function useChat(props) {
    const localMessages = ref([...props.messages]);
    const isProcessing = ref(false);
    const localUsers = ref([...props.users]);
    const isUserAtBottom = ref(true);
    const shouldAutoRefresh = ref(true);
    const hasUnreadMessages = ref(false);
    const presetMetadata = ref(props.presetMetadata || {});

    let usersRefreshInterval = null;

    /**
     * Refresh messages from server
     */
    function refreshMessages() {
        if (!shouldAutoRefresh.value) {
            return;
        }

        let lastId = 0;
        if (localMessages.value.length > 0) {
            lastId = Math.max(...localMessages.value.map(msg => msg.id));
        }

        axios.get(route('chat.new-messages', { lastId }))
            .then(response => {
                const data = response.data;
                const newMessages = data.messages || data;

                if (newMessages.length > 0) {
                    newMessages.forEach(newMsg => {
                        const existingIndex = localMessages.value.findIndex(msg => msg.id === newMsg.id);

                        if (existingIndex >= 0) {
                            localMessages.value[existingIndex] = newMsg;
                        } else {
                            localMessages.value.push(newMsg);
                        }
                    });

                    if (newMessages.some(msg => msg.role === 'assistant' && msg.is_visible_to_user)) {
                        isProcessing.value = false;
                    }
                }

                if (data.presetMetadata) {
                    presetMetadata.value = data.presetMetadata;
                }
            })
            .catch(error => {
                console.error('Error fetching new messages:', error);
            });
    }

    /**
     * Refresh messages and handle unread state
     */
    function refreshMessagesWithUnread() {
        let lastId = 0;
        if (localMessages.value.length > 0) {
            lastId = Math.max(...localMessages.value.map(msg => msg.id));
        }

        axios.get(route('chat.new-messages', { lastId }))
            .then(response => {
                if (response.data.length > 0) {
                    const newMessages = response.data;
                    const hadNewVisibleMessages = newMessages.some(msg => msg.role === 'assistant' && msg.is_visible_to_user);

                    newMessages.forEach(newMsg => {
                        const existingIndex = localMessages.value.findIndex(msg => msg.id === newMsg.id);

                        if (existingIndex >= 0) {
                            localMessages.value[existingIndex] = newMsg;
                        } else {
                            localMessages.value.push(newMsg);
                        }
                    });

                    if (hadNewVisibleMessages) {
                        isProcessing.value = false;

                        if (!shouldAutoRefresh.value) {
                            hasUnreadMessages.value = true;
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching new messages:', error);
            });
    }

    /**
     * Start frequent refresh during processing
     */
    function startFrequentRefresh() {
        refreshMessagesWithUnread();

        const refreshInterval = setInterval(() => {
            refreshMessagesWithUnread();

            if (!isProcessing.value) {
                clearInterval(refreshInterval);
                // Return to normal refresh interval
                setInterval(refreshMessagesWithUnread, 10000);
            }
        }, 3000);

        return refreshInterval;
    }

    /**
     * Stop frequent refresh
     */
    function stopFrequentRefresh() {
        // This will be handled by the startFrequentRefresh interval logic
    }

    /**
     * Refresh users list from server
     */
    function refreshUsers() {
        axios.get(route('chat.users'))
            .then(response => {
                if (response.data && Array.isArray(response.data)) {
                    localUsers.value = response.data;
                }
            })
            .catch(error => {
                console.error('Error fetching users:', error);
            });
    }

    /**
     * Start users refresh interval
     */
    function startUsersRefresh() {
        usersRefreshInterval = setInterval(refreshUsers, 30000);
    }

    /**
     * Stop users refresh interval
     */
    function stopUsersRefresh() {
        if (usersRefreshInterval) {
            clearInterval(usersRefreshInterval);
            usersRefreshInterval = null;
        }
    }

    return {
        localMessages,
        isProcessing,
        localUsers,
        isUserAtBottom,
        shouldAutoRefresh,
        hasUnreadMessages,
        presetMetadata,
        refreshMessages,
        refreshMessagesWithUnread,
        startFrequentRefresh,
        stopFrequentRefresh,
        startUsersRefresh,
        stopUsersRefresh
    };
}
