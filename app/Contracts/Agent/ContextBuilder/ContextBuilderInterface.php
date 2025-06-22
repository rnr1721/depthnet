<?php

namespace App\Contracts\Agent\ContextBuilder;

use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;

interface ContextBuilderInterface
{
    /**
     * Build context array from messages
     *
     * @return array
     */
    public function build(): array;
}
