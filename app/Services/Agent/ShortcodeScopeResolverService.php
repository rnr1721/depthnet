<?php

namespace App\Services\Agent;

use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;

class ShortcodeScopeResolverService implements ShortcodeScopeResolverServiceInterface
{
    private const GLOBAL_SCOPE = 'global';
    private const PRESET_PREFIX = 'preset:';

    /**
     * @inheritDoc
     */
    public function global(): string
    {
        return self::GLOBAL_SCOPE;
    }

    /**
     * @inheritDoc
     */
    public function preset(int $presetId): string
    {
        return self::PRESET_PREFIX . $presetId;
    }

    /**
     * @inheritDoc
     */
    public function buildScopes(?int $presetId = null): array
    {
        $scopes = [self::GLOBAL_SCOPE];

        if ($presetId !== null) {
            $scopes[] = $this->preset($presetId);
        }

        return $scopes;
    }
}
