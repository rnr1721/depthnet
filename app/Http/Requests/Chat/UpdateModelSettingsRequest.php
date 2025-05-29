<?php

namespace App\Http\Requests\Chat;

use App\Contracts\Agent\ModelRegistryInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModelSettingsRequest extends FormRequest
{
    public function __construct(
        protected ModelRegistryInterface $modelRegistry
    ) {

    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $availableModels = array_map(
            fn ($model) => $model->getName(),
            $this->modelRegistry->all()
        );

        return [
            'model_default' => [
                'required',
                'string',
                Rule::in($availableModels)
            ],
            'model_active' => [
                'required',
                'boolean'
            ]
        ];
    }

    /**
     * Get the validated model name.
     */
    public function getModelName(): string
    {
        return $this->validated()['model_default'];
    }

    /**
     * Get the validated active state.
     */
    public function isActive(): bool
    {
        return $this->validated()['model_active'];
    }

    /**
     * Prepare the data for validation.
     *
     * Convert string boolean values to actual booleans.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('model_active')) {
            $this->merge([
                'model_active' => filter_var($this->model_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ]);
        }
    }

}
