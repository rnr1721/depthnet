<?php

namespace App\Services\Agent\Plugins\Related\Prompt;

/**
 * Internal sentinel: annotation required but missing.
 * Lets resolveSummary() distinguish "optional & absent" (null) from
 * "required & absent" (this) without throwing across the parse boundary.
 */
final class AnnotationError
{
    public function __construct(public readonly string $message)
    {
    }
}
