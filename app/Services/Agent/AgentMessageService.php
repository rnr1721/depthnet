<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\AgentMessageServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Delivers messages between agent presets.
 *
 * Reply-to tracking uses cache with TTL (default 10 minutes).
 * If the target doesn't respond within TTL, the reply-to expires
 * automatically — no stale state left behind.
 *
 * AgentJobServiceInterface is lazy-loaded via the container to break
 * a circular dependency chain.
 */
class AgentMessageService implements AgentMessageServiceInterface
{
    private const REPLY_TO_PREFIX = 'handoff_reply_to_';

    /** Reply-to TTL in seconds (10 minutes) */
    private const REPLY_TO_TTL = 600;

    /** @var AgentJobServiceInterface|null Lazy-loaded to avoid circular dependency */
    private ?AgentJobServiceInterface $agentJobService = null;

    public function __construct(
        protected InputPoolServiceInterface $inputPoolService,
        protected ChatStatusServiceInterface $chatStatusService,
        protected Cache $cache,
        protected Container $container,
        protected Message $messageModel,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deliver(
        AiPreset $fromPreset,
        AiPreset $toPreset,
        string $message,
        bool $triggerThinking = true,
        bool $setReplyTo = true
    ): void {
        $targetPresetId = $toPreset->getId();
        $sourceName = $fromPreset->getAvailableName();

        if ($this->inputPoolService->isEnabled($toPreset)) {
            $this->inputPoolService->add($targetPresetId, $sourceName, $message);
            $content = $this->inputPoolService->getAllAsJSON($toPreset);

            $this->messageModel->create([
                'role'               => 'user',
                'content'            => $content,
                'from_user_id'       => null,
                'preset_id'          => $targetPresetId,
                'is_visible_to_user' => true,
            ]);
        } else {
            $label = "handoff response from {$sourceName}:";
            $content = "{$label}\n{$message}";

            $this->messageModel->create([
                'role'               => 'user',
                'content'            => $content,
                'from_user_id'       => null,
                'preset_id'          => $targetPresetId,
                'is_visible_to_user' => true,
            ]);
        }

        if ($setReplyTo) {
            $this->cache->put(
                self::REPLY_TO_PREFIX . $targetPresetId,
                $fromPreset->getId(),
                self::REPLY_TO_TTL
            );
        }

        if ($triggerThinking) {
            $isActive = $this->chatStatusService->getPresetStatus($targetPresetId);
            $this->getAgentJobService()->start($targetPresetId, !$isActive);
        }

        // Remove the delivered item so it doesn't appear again
        // in the next pool flush (e.g. when user sends a message)
        $this->inputPoolService->removeItem($targetPresetId, $sourceName);

        $this->logger->debug('AgentMessageService: delivered', [
            'from'      => $fromPreset->getId(),
            'to'        => $targetPresetId,
            'mode'      => $this->inputPoolService->isEnabled($toPreset) ? 'pool' : 'plain',
            'reply_to'  => $setReplyTo,
            'trigger'   => $triggerThinking,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getReplyTo(int $presetId): ?int
    {
        $value = $this->cache->get(self::REPLY_TO_PREFIX . $presetId);

        return $value ? (int) $value : null;
    }

    /**
     * @inheritDoc
     */
    public function clearReplyTo(int $presetId): void
    {
        $this->cache->forget(self::REPLY_TO_PREFIX . $presetId);
    }

    /**
     * Get AgentJobService with lazy loading to prevent circular dependency.
     */
    private function getAgentJobService(): AgentJobServiceInterface
    {
        if ($this->agentJobService === null) {
            $this->agentJobService = $this->container->make(AgentJobServiceInterface::class);
        }

        return $this->agentJobService;
    }
}
