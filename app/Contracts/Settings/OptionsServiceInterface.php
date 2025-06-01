<?php

namespace App\Contracts\Settings;

interface OptionsServiceInterface
{
    /**
     * Get value by key
     *
     * @param string $key Option key
     * @param mixed $default Default value, if option not found
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set option value
     *
     * @param string $key Option key
     * @param mixed $value Option value
     * @return bool
     */
    public function set(string $key, $value): bool;

    /**
     * If value exists?
     *
     * @param string $key Option key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Delete option by key
     *
     * @param string $key Option key
     * @return bool
     */
    public function remove(string $key): bool;
}
