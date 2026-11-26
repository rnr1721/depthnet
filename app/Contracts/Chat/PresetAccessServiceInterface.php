<?php

namespace App\Contracts\Chat;

use App\Models\AiPreset;

/**
 * Single place for "which preset may the current user talk to".
 *
 * Rule: admins — any preset; everyone else — only the default preset.
 */
interface PresetAccessServiceInterface
{
    /**
     * Resolve the preset for the current user.
     *
     * @param int|null $requestedId preset_id from the request (null/0 = default)
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException  preset doesn't exist
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException user may not use it
     */
    public function resolve(?int $requestedId): AiPreset;

    /**
     * Non-throwing variant: null when not found or not allowed.
     */
    public function tryResolve(?int $requestedId): ?AiPreset;

    /**
     * Whether the current user may use the given preset.
     */
    public function canAccess(AiPreset $preset): bool;
}
