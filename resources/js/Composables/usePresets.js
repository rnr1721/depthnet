import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * Composable for presets functionality
 * @param {Object} props - Component props
 * @param {Object} isAdmin - Computed admin status
 * @returns {Object} Presets state and methods
 */
export function usePresets(props, isAdmin) {
  // Reactive state
  const selectedPresetId = ref(props.currentPresetId);
  const isChatActive = ref(props.chatActive);
  const selectedExportFormat = ref('');
  const includeThinking = ref(false);
  const isExporting = ref(false);
  const showEditPresetModal = ref(false);
  const editingPreset = ref(null);
  const engines = ref(props.engines || {});
  const showThinking = ref(props.mode === 'single' || true);

  /**
   * Update preset settings
   */
  function updatePresetSettings() {
    if (!isAdmin.value) {
      console.warn('Only admins can update preset settings');
      return;
    }

    router.post(route('chat.preset-settings'), {
      preset_id: selectedPresetId.value,
      chat_active: isChatActive.value
    }, {
      preserveScroll: true,
      onSuccess: () => {
        // Settings saved successfully
      },
      onError: (errors) => {
        console.error('Error updating preset settings:', errors);
      }
    });
  }

  /**
   * Export chat history
   */
  async function exportChat() {
    if (!selectedExportFormat.value || isExporting.value) return;

    isExporting.value = true;

    try {
      const response = await axios.post(route('chat.export'), {
        format: selectedExportFormat.value,
        include_thinking: includeThinking.value
      }, {
        responseType: 'blob'
      });

      const contentDisposition = response.headers['content-disposition'];
      let filename = 'chat_export.txt';
      if (contentDisposition) {
        const filenameMatch = contentDisposition.match(/filename="([^"]+)"/);
        if (filenameMatch) {
          filename = filenameMatch[1];
        }
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
   * Edit current preset
   */
  const editCurrentPreset = async () => {
    if (!props.currentPreset?.id) {
      console.error('No current preset available');
      return;
    }

    try {
      const response = await axios.get(route('admin.presets.show', props.currentPreset.id));

      if (response.data.success) {
        editingPreset.value = response.data.data;
        engines.value = props.engines;
        showEditPresetModal.value = true;
      } else {
        console.error('Failed to load preset details');
      }
    } catch (error) {
      console.error('Error loading preset:', error);
    }
  };

  /**
   * Close edit modal
   */
  const closeEditModal = () => {
    showEditPresetModal.value = false;
    editingPreset.value = null;
  };

  /**
   * Save current preset changes
   * @param {Object} data - Preset data to save
   */
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
            'X-Requested-With': 'XMLHttpRequest'
          }
        }
      );

      if (response.data.success) {
        closeEditModal();
      }

    } catch (error) {
      if (error.response?.status === 422 && error.response.data.errors) {
        // Validation errors
        const errors = error.response.data.errors;
        let errorMessage = 'Error on saving:\n';
        for (const [field, messages] of Object.entries(errors)) {
          const message = Array.isArray(messages) ? messages[0] : messages;
          errorMessage += `${field}: ${message}\n`;
        }
        alert(errorMessage);
      } else {
        // Other errors
        alert('Error while saving preset');
      }
    }
  };

  return {
    selectedPresetId,
    isChatActive,
    selectedExportFormat,
    includeThinking,
    isExporting,
    showEditPresetModal,
    editingPreset,
    engines,
    showThinking,
    updatePresetSettings,
    exportChat,
    editCurrentPreset,
    closeEditModal,
    saveCurrentPreset
  };
}
