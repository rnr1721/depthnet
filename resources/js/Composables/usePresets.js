import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useSelectedPreset } from '@/Composables/useSelectedPreset';

/**
 * Composable for presets functionality.
 *
 * isChatActive is now a per-preset map: { [presetId]: boolean }
 * built from availablePresets[].chat_active passed by the server.
 */
export function usePresets(props, isAdmin) {
  const { getSavedPresetId } = useSelectedPreset();

  // Restore last selected preset from localStorage, fall back to server default.
  // Validate that saved preset actually exists in availablePresets.
  const savedId = getSavedPresetId();
  const availableIds = (props.availablePresets || []).map(p => p.id);
  const restoredId = (savedId && availableIds.includes(savedId)) ? savedId : props.currentPresetId;

  const selectedPresetId = ref(restoredId);

  // Build reactive per-preset active map from server props
  const presetActiveMap = ref(buildActiveMap(props.availablePresets || []));

  const selectedExportFormat = ref('');
  const isExporting = ref(false);
  const showEditPresetModal = ref(false);
  const editingPreset = ref(null);
  const engines = ref(props.engines || {});
  const currentPlaceholders = ref(props.placeholders || {});

  /**
   * Build { presetId: bool } map from availablePresets array.
   */
  function buildActiveMap(presets) {
    const map = {};
    for (const p of presets) {
      map[p.id] = !!p.chat_active;
    }
    return map;
  }

  /**
   * Whether the currently selected preset has its loop active.
   */
  const isChatActive = computed({
    get() {
      return !!presetActiveMap.value[selectedPresetId.value];
    },
    set(value) {
      setPresetActive(selectedPresetId.value, value);
    }
  });

  /**
   * Toggle loop for a specific preset.
   */
  function setPresetActive(presetId, value) {
    if (!isAdmin.value) {
      console.warn('Only admins can toggle preset active state');
      return;
    }
    presetActiveMap.value[presetId] = value;
    savePresetSettings(presetId, value);
  }

  /**
   * Get loop-active status for any preset ID.
   */
  function getPresetActive(presetId) {
    return !!presetActiveMap.value[presetId];
  }

  /**
   * Persist preset_id + chat_active to the server.
   */
  function savePresetSettings(presetId, isActive) {
    router.post(route('chat.preset-settings'), {
      preset_id: presetId,
      chat_active: isActive,
    }, {
      preserveScroll: true,
      onError: (errors) => {
        console.error('Error updating preset settings:', errors);
        // Rollback optimistic update
        presetActiveMap.value[presetId] = !isActive;
      }
    });
  }

  /**
   * Called when the user switches to a different preset.
   * Does NOT change the active/inactive loop state — just updates the selection.
   */
  function updatePresetSettings() {
    // No-op: loop toggle is handled per-preset via setPresetActive.
    // Kept for call-site compatibility in Index.vue.
  }

  /**
   * Export chat history.
   */
  async function exportChat() {
    if (!selectedExportFormat.value || isExporting.value) return;

    isExporting.value = true;

    try {
      const response = await axios.post(route('chat.export'), {
        format: selectedExportFormat.value,
        preset_id: selectedPresetId.value,
      }, {
        responseType: 'blob',
      });

      const contentDisposition = response.headers['content-disposition'];
      let filename = 'chat_export.txt';
      if (contentDisposition) {
        const match = contentDisposition.match(/filename="([^"]+)"/);
        if (match) filename = match[1];
      }

      const url = window.URL.createObjectURL(new Blob([response.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);

    } catch (error) {
      console.error('Export error:', error);
    } finally {
      isExporting.value = false;
    }
  }

  /**
   * Edit current preset.
   */
  const editCurrentPreset = async () => {
    if (!props.currentPreset?.id) {
      console.error('No current preset available');
      return;
    }

    try {
      const response = await axios.get(route('admin.presets.show', props.currentPreset.id));

      if (response.data.success) {
        editingPreset.value = response.data.data.preset;
        currentPlaceholders.value = response.data.data.placeholders ?? props.placeholders;
        engines.value = props.engines;
        showEditPresetModal.value = true;
      } else {
        console.error('Failed to load preset details');
      }
    } catch (error) {
      console.error('Error loading preset:', error);
    }
  };

  const closeEditModal = () => {
    showEditPresetModal.value = false;
    editingPreset.value = null;
    currentPlaceholders.value = props.placeholders || {};
  };

  const saveCurrentPreset = async (data) => {
    if (!editingPreset.value) return;

    try {
      const response = await axios.put(
        route('chat.preset.update', editingPreset.value.id),
        data,
        {
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          }
        }
      );

      if (response.data.success) {
        closeEditModal();
      }

    } catch (error) {
      if (error.response?.status === 422 && error.response.data.errors) {
        const errors = error.response.data.errors;
        let msg = 'Error on saving:\n';
        for (const [field, messages] of Object.entries(errors)) {
          msg += `${field}: ${Array.isArray(messages) ? messages[0] : messages}\n`;
        }
        alert(msg);
      } else {
        alert('Error while saving preset');
      }
    }
  };

  return {
    selectedPresetId,
    isChatActive,         // computed for currently selected preset
    presetActiveMap,      // full map for all presets
    getPresetActive,      // helper to read any preset's status
    setPresetActive,      // helper to write any preset's status
    selectedExportFormat,
    isExporting,
    showEditPresetModal,
    editingPreset,
    engines,
    currentPlaceholders,
    updatePresetSettings, // kept for compatibility (no-op)
    exportChat,
    editCurrentPreset,
    closeEditModal,
    saveCurrentPreset,
  };
}