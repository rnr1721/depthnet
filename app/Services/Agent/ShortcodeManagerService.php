<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\EnvironmentInfoServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;

class ShortcodeManagerService implements ShortcodeManagerServiceInterface
{
    public function __construct(
        protected PlaceholderServiceInterface $placeholderService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected EnvironmentInfoServiceInterface $environmentInfoService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function setDefaultShortcodes(): void
    {
        $this->setDateTime();
        $this->setCommandBuilderInstructions();
        $this->setEnvironmentInfo();
    }

    /**
     * Register current date and time shortcode
     *
     * @return void
     */
    private function setDateTime(): void
    {
        $this->placeholderService->registerDynamic('current_datetime', 'Current date and time', function () {
            return date('Y-m-d H:i:s');
        });
    }

    /**
     * Register plugin command instructions shortcode
     *
     * @return void
     */
    private function setCommandBuilderInstructions(): void
    {
        $this->placeholderService->registerDynamic('command_instructions', 'Instructions for plugin commands for model', function () {
            return $this->commandInstructionBuilder->buildInstructions();
        });
    }

    /**
     * Register environment information shortcode
     *
     * @return void
     */
    private function setEnvironmentInfo(): void
    {
        $this->placeholderService->registerDynamic('environment_info', 'Current system environment information', function () {
            return $this->environmentInfoService->getEnvironmentInfo();
        });
    }

    /**
     * @inheritDoc
     */
    public function registerShortcode(string $key, string $description, callable $callback): void
    {
        $this->placeholderService->registerDynamic($key, $description, $callback);
    }

    /**
     * @inheritDoc
     */
    public function unregisterShortcode(string $key): bool
    {
        $this->placeholderService->removePlaceholder($key);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredShortcodes(): array
    {
        return $this->placeholderService->getPlaceholders();
    }

    /**
     * @inheritDoc
     */
    public function hasShortcode(string $key): bool
    {
        return $this->placeholderService->hasPlaceholder($key);
    }

    /**
     * @inheritDoc
     */
    public function processShortcodes(string $text): string
    {
        return $this->placeholderService->processContentWithDynamic($text);
    }

    /**
     * @inheritDoc
     */
    public function getShortcodeValue(string $key): ?string
    {
        try {
            return $this->placeholderService->getPlaceholderContent($key);
        } catch (\Exception $e) {
            return null;
        }
    }
}
