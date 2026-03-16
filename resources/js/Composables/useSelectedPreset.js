import { ref } from 'vue';

const STORAGE_KEY = 'selected_preset_id';

/**
 * Composable for persisting the selected preset ID across pages.
 *
 * Chat writes here on preset switch.
 * Admin pages read here on mount to open the right preset automatically.
 */
export function useSelectedPreset() {

    /**
     * Read the saved preset ID from localStorage.
     * Returns null if nothing is saved or value is invalid.
     */
    function getSavedPresetId() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const id = parseInt(raw, 10);
            return isNaN(id) ? null : id;
        } catch {
            return null;
        }
    }

    /**
     * Save the selected preset ID to localStorage.
     */
    function savePresetId(presetId) {
        try {
            if (presetId) {
                localStorage.setItem(STORAGE_KEY, String(presetId));
            }
        } catch {
            // Silently fail if localStorage is unavailable
        }
    }

    /**
     * Build a route URL with preset_id query param injected from localStorage.
     * Use for Memory / VectorMemory pages which use ?preset_id=X.
     *
     * @param {string} routeUrl - The base route URL (from route() helper)
     * @param {number|null} overridePresetId - Use this instead of localStorage value
     */
    function routeWithPreset(routeUrl, overridePresetId = null) {
        const presetId = overridePresetId ?? getSavedPresetId();
        if (!presetId) return routeUrl;

        const separator = routeUrl.includes('?') ? '&' : '?';
        return `${routeUrl}${separator}preset_id=${presetId}`;
    }

    /**
     * Build a route URL with presetId as a route segment.
     * Use for Plugins page which uses /admin/plugins/{presetId}.
     *
     * @param {string} routeName - The Laravel route name
     * @param {number|null} overridePresetId - Use this instead of localStorage value
     */
    function routeWithPresetParam(routeName, overridePresetId = null) {
        const presetId = overridePresetId ?? getSavedPresetId();
        if (!presetId) return route(routeName);
        return route(routeName, { presetId });
    }

    return {
        getSavedPresetId,
        savePresetId,
        routeWithPreset,
        routeWithPresetParam,
    };
}