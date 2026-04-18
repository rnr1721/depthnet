<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PluginExecutionContextBuilderInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;

class PluginRegistry implements PluginRegistryInterface
{
    /**
     * renamed from PLUGIN_READY_METHOD. The hook is now opt-in
     * and used purely for placeholder/shortcode registration scoped to a
     * specific preset. Plugins that don't register shortcodes don't need
     * to implement it at all.
     */
    public const REGISTER_SHORTCODES_METHOD = 'registerShortcodes';

    protected array $disabledForNow = [];

    /**
     * @var CommandPluginInterface[]
     */
    protected array $plugins = [];

    public function __construct(
        // needed to build per-preset context for the
        // registerShortcodes() hook. Stateless service, safe to inject.
        protected PluginExecutionContextBuilderInterface $contextBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(CommandPluginInterface $plugin): self
    {
        $this->plugins[$plugin->getName()] = $plugin;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name])
            && !in_array($name, $this->disabledForNow);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ?CommandPluginInterface
    {
        return $this->has($name) ? $this->plugins[$name] : null;
    }

    /**
     * @inheritDoc
     *
     * this still returns the bare singleton instances.
     * Callers that need to invoke methods on them must build a
     * PluginExecutionContext and pass it explicitly.
     */
    public function all(): array
    {
        $availablePlugins = empty($this->disabledForNow)
            ? $this->plugins
            : array_filter(
                $this->plugins,
                fn ($name) => !in_array($name, $this->disabledForNow),
                ARRAY_FILTER_USE_KEY
            );

        return $availablePlugins;
    }

    /**
     * @inheritDoc
     */
    public function allRegistered(): array
    {
        return $this->plugins;
    }

    /**
     * @inheritDoc
     */
    public function getAvailablePluginNames(): array
    {
        return array_keys($this->all());
    }

    /**
     * Apply preset-specific disabled list and call registerShortcodes() on
     * each plugin that implements it.
     */
    public function applyPreset(AiPreset $preset): void
    {
        $this->setDisabledForNow($preset->getPluginsDisabled());

        foreach ($this->allRegistered() as $plugin) {
            if (!method_exists($plugin, self::REGISTER_SHORTCODES_METHOD)) {
                continue;
            }

            $context = $this->contextBuilder->build($plugin, $preset);

            // Skip disabled plugins — no point in registering their
            // shortcodes if they can't be invoked anyway.
            if (!$context->enabled) {
                continue;
            }

            $plugin->{self::REGISTER_SHORTCODES_METHOD}($context);
        }
    }

    /**
     * @inheritDoc
     */
    public function setDisabledForNow(array|string $disabledPlugins): void
    {
        $this->disabledForNow = is_string($disabledPlugins)
            ? array_map('trim', explode(',', $disabledPlugins))
            : $disabledPlugins;
    }

    /**
     * Legacy hook that did nothing in the previous version.
     * Kept for interface compatibility but is a no-op.
     */
    public function postInitPlugins(): void
    {
        // intentional no-op
    }
}
