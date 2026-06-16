<?php

namespace App\Contracts\Agent\Contract;

use App\Models\AiPreset;

/**
 * ContractMemoWriterInterface — the sink for the inject_memo action.
 *
 * The contract engine does not know how memo works; it just hands text to this
 * writer. A concrete implementation (wired to the real memo plugin/service) is
 * bound later. The engine treats the writer as optional: if nothing is bound,
 * inject_memo degrades to a logged warning rather than failing the tick, so the
 * rest of the metabolism keeps running.
 */
interface ContractMemoWriterInterface
{
    /**
     * Append a line to the preset's next-cycle memo.
     *
     * @return bool whether the text was written.
     */
    public function write(AiPreset $preset, string $text): bool;
}
