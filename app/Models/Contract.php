<?php

namespace App\Models;

use App\Services\Agent\Contract\ContractDefinition;
use App\Services\Agent\Contract\ContractForm;
use App\Services\Agent\Contract\ContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contract — Eloquent storage for a single metabolism contract.
 *
 * This is the *storage* side of the contract. It knows columns, JSON casts,
 * and scopes — and nothing about evaluation. The engine never touches this
 * model: it works with the immutable ContractDefinition DTO, obtained via
 * toDomain(). Conversion happens only in ContractService.
 *
 * @property int            $id
 * @property int            $preset_id
 * @property string         $name
 * @property ContractForm   $form
 * @property ContractStatus $status
 * @property bool           $vital
 * @property string         $source
 * @property string|null    $suspend_when
 * @property float          $confidence
 * @property array          $trigger
 * @property array          $action
 * @property array|null     $history
 */
class Contract extends Model
{
    protected $table = 'agent_contracts';

    protected $fillable = [
        'preset_id',
        'name',
        'form',
        'status',
        'vital',
        'source',
        'suspend_when',
        'confidence',
        'trigger',
        'action',
        'history',
    ];

    protected $casts = [
        'form'       => ContractForm::class,
        'status'     => ContractStatus::class,
        'vital'      => 'boolean',
        'confidence' => 'float',
        'trigger'    => 'array',
        'action'     => 'array',
        'history'    => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function preset(): BelongsTo
    {
        return $this->belongsTo(AiPreset::class, 'preset_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForPreset($query, int $presetId)
    {
        return $query->where('preset_id', $presetId);
    }

    public function scopeOfStatus($query, ContractStatus $status)
    {
        return $query->where('status', $status->value);
    }

    /** Only active contracts are evaluated on a tick. */
    public function scopeActive($query)
    {
        return $query->where('status', ContractStatus::ACTIVE->value);
    }

    // -------------------------------------------------------------------------
    // Domain conversion
    // -------------------------------------------------------------------------

    /**
     * Project this stored row into the immutable execution DTO.
     * The DTO reassembles `match` from inside the trigger, exactly as
     * the canonical JSON shape carries it.
     */
    public function toDomain(): ContractDefinition
    {
        return ContractDefinition::fromArray([
            'name'         => $this->name,
            'form'         => $this->form->value,
            'status'       => $this->status->value,
            'vital'        => $this->vital,
            'trigger'      => $this->trigger,
            'action'       => $this->action,
            'source'       => $this->source,
            'suspend_when' => $this->suspend_when,
            'confidence'   => $this->confidence,
            'history'      => $this->history ?? [],
        ]);
    }
}
