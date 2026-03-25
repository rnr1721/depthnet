<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PresetResource extends JsonResource
{
    /**
     * Transform the resource into an array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'engine_name' => $this->engine_name,
            'input_mode' => $this->input_mode,
            'preset_code' => $this->preset_code,
            'plugins_disabled' => $this->plugins_disabled,
            'engine_config' => $this->engine_config,
            'loop_interval' => $this->loop_interval,
            'max_context_limit' => $this->max_context_limit,
            'agent_result_mode' => $this->agent_result_mode,
            'preset_code_next' => $this->preset_code_next,
            'pre_run_commands' => $this->pre_run_commands,
            'rag_preset_id' => $this->rag_preset_id,
            'rag_context_limit' => $this->rag_context_limit,
            'rag_results' => $this->rag_results,
            'voice_preset_id' => $this->voice_preset_id,
            'voice_context_limit' => $this->voice_context_limit,
            'cycle_prompt_preset_id' => $this->cycle_prompt_preset_id,
            'cp_context_limit' => $this->cp_context_limit,
            'voice_mp_commands' => $this->voice_mp_commands,
            'default_call_message' => $this->default_call_message,
            'before_execution_wait' => $this->before_execution_wait,
            'error_behavior' => $this->error_behavior,
            'allow_handoff_to' => $this->allow_handoff_to,
            'allow_handoff_from' => $this->allow_handoff_from,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

            'engine_display_name' => $this->when(
                isset($this->engine_display_name),
                $this->engine_display_name
            ),
        ];
    }
}
