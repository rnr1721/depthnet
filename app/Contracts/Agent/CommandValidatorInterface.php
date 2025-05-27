<?php

namespace App\Contracts\Agent;

interface CommandValidatorInterface
{
    public function validate(string $output): array;
}
