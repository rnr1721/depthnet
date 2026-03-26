<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presetId = $this->route('id');

        return [
            'name' => ['string', 'max:255', "unique:ai_presets,name,{$presetId}"],
            'input_mode' => ['required', 'in:single,pool'],
            'preset_code' => ['nullable', 'string', 'max:50', "unique:ai_presets,preset_code,{$presetId}"],
            'plugins_disabled' => ['nullable','string','max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'engine_name' => ['string', 'max:100'],
            'engine_config' => ['array'],
            'loop_interval' => ['required','integer','min:4','max:30'],
            'max_context_limit' => ['required','integer','min:0','max:50'],
            'agent_result_mode' => ['required','string'],
            'preset_code_next' => ['nullable', 'string', 'max:50'],
            'pre_run_commands' => ['nullable', 'string'],
            'rag_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'rag_context_limit' => 'required|integer|min:4|max:20',
            'voice_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'voice_context_limit' => 'required|integer|min:0|max:20',
            'rag_results' => 'required|integer|min:4|max:20',
            'rag_mode' => 'required|in:flat,associative',
            'rag_engine' => 'required|in:tfidf,embedding',
            'cycle_prompt_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'cp_context_limit' => 'required|integer|min:4|max:20',
            'voice_mp_commands' => ['nullable','string'],
            'default_call_message' => ['nullable', 'string', 'max:1000'],
            'before_execution_wait' => ['required', 'integer', 'min:1', 'max:60'],
            'error_behavior' => ['required','in:stop,continue,fallback'],
            'allow_handoff_to' => ['boolean'],
            'allow_handoff_from' => ['boolean'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'prompts' => ['nullable', 'array'],
            'prompts.*.id' => ['nullable', 'integer', 'exists:preset_prompts,id'],
            'prompts.*.code' => ['required_with:prompts', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            'prompts.*.content' => ['nullable', 'string', 'max:10000'],
            'prompts.*.description' => ['nullable', 'string', 'max:500'],
            'prompts.*.is_active' => ['nullable', 'boolean'],
            'deleted_prompt_ids' => ['nullable', 'array'],
            'deleted_prompt_ids.*' => ['integer', 'exists:preset_prompts,id'],

        ];
    }
}
