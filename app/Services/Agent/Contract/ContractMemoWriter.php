<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\ContractMemoWriterInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * ContractMemoWriter — the inject_memo sink, wired to the SelfNote (memo) plugin.
 *
 * The memo subsystem is a SINGLE-SLOT, READ-ONCE channel:
 *   - SelfNotePlugin (system_message mode) stores the agent's note under
 *     metadata key memo/self_system_note;
 *   - Agent::setupPresetEnvironment() reads that key once at cycle start, deletes
 *     it, and exposes the text via the [[memo]] placeholder.
 *
 * That single slot is also where the agent writes its OWN note to its future
 * self. So this writer must never overwrite — it APPENDS. The contract engine
 * and the agent share one mailbox; the metabolism adds a line, it does not erase
 * the agent's voice. This matches the spec's wording for inject_memo: "append a
 * line to next cycle's memo".
 *
 * Concurrency note: the engine ticks on its own cadence, the agent thinks on
 * another. Between two ticks the agent may not have consumed the memo yet, so a
 * second tick must not duplicate the same line. We guard with a per-text marker:
 * if our marked line is already present, we don't add it again. (Last-write-wins
 * on the metadata blob is acceptable here — the texts are short and the window is
 * seconds; a lost append simply re-appends next tick.)
 */
final class ContractMemoWriter implements ContractMemoWriterInterface
{
    /** Must match SelfNotePlugin::PLUGIN_NAME and the key it writes. */
    private const MEMO_PLUGIN = 'memo';
    private const MEMO_KEY    = 'self_system_note';

    /** Prefix marking a line as metabolism-originated, for transparency + dedup. */
    private const MARK = '[metabolism]';

    public function __construct(
        protected PluginMetadataServiceInterface $metadata,
        protected LoggerInterface                $logger,
    ) {
    }

    public function write(AiPreset $preset, string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        try {
            $line = self::MARK . ' ' . $text;

            $existing = $this->metadata->get($preset, self::MEMO_PLUGIN, self::MEMO_KEY, '');
            $existing = is_string($existing) ? $existing : '';

            // Don't duplicate the same metabolism line if a prior tick already
            // appended it and the agent hasn't consumed the memo yet.
            if ($existing !== '' && str_contains($existing, $line)) {
                return true;
            }

            $merged = $existing === ''
                ? $line
                : $existing . "\n" . $line;

            $this->metadata->set($preset, self::MEMO_PLUGIN, self::MEMO_KEY, $merged);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ContractMemoWriter: failed to append memo', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
