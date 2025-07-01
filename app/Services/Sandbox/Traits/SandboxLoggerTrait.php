<?php

namespace App\Services\Sandbox\Traits;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Stringable;
use Psr\Log\LoggerInterface;

/**
 * @property LoggerInterface $logger A voice speaking from the depths of the logs.
 * @property Config $config The source of the setting that feeds the meaning of the logs.
 * @method void log(string|Stringable $message, array $context = [], string $type = 'info')
 */
trait SandboxLoggerTrait
{
    /**
     * Undocumented function
     *
     * @param string|Stringable $message
     * @param array $context
     * @param string $type
     * @return void
     */
    protected function log(string|Stringable $message, array $context = [], string $type = 'info'): void
    {
        if ($this->config->get('sandbox.manager.debug')) {
            $this->logger->{$type}($message, $context);
        }
    }
}
