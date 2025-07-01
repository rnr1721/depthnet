<?php

namespace App\Services\Agent\Plugins\Traits;

trait PluginExecutionMetaTrait
{
    protected array $pluginExecutionMeta = [];

    /**
     * Here it is possible to return some data that may affect the agent's work.
     * This is system things
     *
     * @return array
     */
    public function getPluginExecutionMeta(): array
    {
        return $this->pluginExecutionMeta;
    }

    /**
     * Here it is possible to set some data that may affect the agent's work.
     * This is system things
     *
     * @param string $key
     * @param string $data
     * @return void
     */
    protected function setPluginExecutionMeta(string $key, string $data)
    {
        $this->pluginExecutionMeta[$key] = $data;
    }
}
