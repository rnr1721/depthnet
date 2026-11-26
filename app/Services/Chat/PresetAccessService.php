<?php

namespace App\Services\Chat;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Chat\PresetAccessServiceInterface;
use App\Models\AiPreset;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PresetAccessService implements PresetAccessServiceInterface
{
    public function __construct(
        protected PresetServiceInterface $presetService,
        protected AuthServiceInterface $authService
    ) {
    }

    public function resolve(?int $requestedId): AiPreset
    {
        $requestedId = $requestedId ?: null;

        if ($this->isAdmin()) {
            $preset = $requestedId
                ? $this->presetService->findById($requestedId)
                : $this->presetService->getDefaultPreset();

            if (!$preset) {
                throw new NotFoundHttpException('Preset not found');
            }
            return $preset;
        }

        $default = $this->presetService->getDefaultPreset();
        if (!$default) {
            throw new NotFoundHttpException('Preset not found');
        }

        if ($requestedId !== null && $requestedId !== $default->getId()) {
            throw new AccessDeniedHttpException('Access to this preset is not allowed');
        }

        return $default;
    }

    public function tryResolve(?int $requestedId): ?AiPreset
    {
        try {
            return $this->resolve($requestedId);
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    public function canAccess(AiPreset $preset): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $default = $this->presetService->getDefaultPreset();

        return $default && $default->getId() === $preset->getId();
    }

    protected function isAdmin(): bool
    {
        $user = $this->authService->getCurrentUser();

        return $user && $user->isAdmin();
    }
}
