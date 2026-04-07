<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class CreatePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:ai_presets,name'],
            'input_mode' => ['required', 'in:single,pool'],
            'preset_code' => ['nullable', 'string', 'max:50', 'unique:ai_presets,preset_code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'engine_name' => ['required', 'string', 'max:100'],
            'plugins_disabled' => ['nullable','string','max:255'],
            'engine_config' => ['required', 'array'],
            'loop_interval' => ['required','integer','min:4','max:30'],
            'max_context_limit' => ['required','integer','min:0','max:50'],
            'agent_result_mode' => ['required','string'],
            'preset_code_next' => ['nullable', 'string', 'max:50'],
            'pre_run_commands' => ['nullable', 'string'],
            'rag_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'rag_context_limit' => 'required|integer|min:1|max:20',
            'rag_results' => 'required|integer|min:4|max:20',
            'rag_mode' => 'required|in:flat,associative',
            'rag_engine' => 'required|in:tfidf,embedding',
            'rag_relative_dates'         => ['boolean'],
            'rag_journal_limit'          => ['required', 'integer', 'min:1', 'max:20'],
            'rag_skills_limit'           => ['required', 'integer', 'min:1', 'max:20'],
            'rag_content_limit'          => ['required', 'integer', 'min:100', 'max:2000'],
            'rag_journal_context_window' => ['required', 'integer', 'min:0', 'max:5'],
            'voice_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'voice_context_limit' => 'required|integer|min:0|max:20',
            'cycle_prompt_preset_id' => 'nullable|integer|exists:ai_presets,id',
            'cp_context_limit' => 'required|integer|min:4|max:20',
            'voice_mp_commands' => ['nullable','string'],
            'default_call_message' => ['nullable', 'string', 'max:1000'],
            'before_execution_wait' => ['required', 'integer', 'min:1', 'max:60'],
            'error_behavior' => ['required','in:stop,continue,fallback'],
            'allow_handoff_to' => ['boolean'],
            'allow_handoff_from' => ['boolean'],
            'rhasspy_enabled'          => ['boolean'],
            'rhasspy_url'              => ['nullable', 'string', 'url', 'max:255'],
            'rhasspy_tts_voice'        => ['nullable', 'string', 'max:100'],
            'rhasspy_incoming_enabled' => ['boolean'],
            'rhasspy_incoming_token'   => ['nullable', 'string', 'max:255'],
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

    public function messages(): array
    {
        return [
            'name.unique' => 'A preset with this name already exists.',
            'engine_config.required' => 'Engine configuration is required.',
        ];
    }
}
