<?php

namespace App\Http\Requests\Chat;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Models\AiPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for updating chat preset settings
 *
 * Handles validation and data transformation for preset selection
 * and chat active status changes in the admin interface.
 */
class UpdatePresetSettingsRequest extends FormRequest
{
    /**
     * Create a new form request instance
     *
     * @param PresetServiceInterface $presetService Service for managing AI presets
     */
    public function __construct(
        protected PresetServiceInterface $presetService
    ) {
        parent::__construct();
    }

    /**
     * Determine if the user is authorized to make this request
     *
     * Only admin users can update preset settings.
     *
     * @return bool True if user is authorized, false otherwise
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request
     *
     * Validates preset_id against active presets and chat_active as boolean.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Get available active preset IDs for validation
        $availablePresetIds = $this->presetService->getActivePresets()
            ->pluck('id')
            ->toArray();

        return [
            'preset_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::in($availablePresetIds)
            ],
            'chat_active' => [
                'sometimes',
                'required',
                'boolean'
            ]
        ];
    }

    /**
     * Get the validated preset ID
     *
     * @return int|null The preset ID if provided and valid, null otherwise
     */
    public function getPresetId(): ?int
    {
        return $this->validated()['preset_id'] ?? null;
    }

    /**
     * Get the validated chat active state
     *
     * @return bool|null The chat active status if provided and valid, null otherwise
     */
    public function isChatActive(): ?bool
    {
        return $this->validated()['chat_active'] ?? null;
    }

    /**
     * Get custom error messages for validation rules
     *
     * @return array<string, string> Custom validation error messages
     */
    public function messages(): array
    {
        return [
            'preset_id.in' => 'The selected preset is not available or inactive.',
            'preset_id.required' => 'A preset must be selected.',
            'chat_active.boolean' => 'Chat active status must be true or false.',
        ];
    }

    /**
     * Prepare the data for validation
     *
     * Converts string values to appropriate types before validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean values to actual booleans
        if ($this->has('chat_active')) {
            $this->merge([
                'chat_active' => filter_var(
                    $this->chat_active,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                )
            ]);
        }

        // Convert string preset_id to integer
        if ($this->has('preset_id')) {
            $this->merge([
                'preset_id' => (int) $this->preset_id
            ]);
        }
    }

    /**
     * Get the selected preset model
     *
     * Retrieves the full preset model based on the validated preset_id.
     *
     * @return AiPreset|null The selected preset model or null if not found
     */
    public function getSelectedPreset(): ?AiPreset
    {
        $presetId = $this->getPresetId();
        if (!$presetId) {
            return null;
        }

        return $this->presetService->findById($presetId);
    }

    /**
     * Check if this request is updating the default preset
     *
     * @return bool True if preset_id is present in request
     */
    public function isUpdatingPreset(): bool
    {
        return $this->has('preset_id');
    }

    /**
     * Check if this request is updating chat active status
     *
     * @return bool True if chat_active is present in request
     */
    public function isUpdatingChatStatus(): bool
    {
        return $this->has('chat_active');
    }
}
