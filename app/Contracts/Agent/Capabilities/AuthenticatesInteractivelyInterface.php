<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\AuthStep;

/**
 * Optional interface for providers whose login is a multi-step dialogue with a
 * human: Telegram user accounts (phone → code → 2FA), WhatsApp (QR scan), etc.
 *
 * Providers that authenticate with a static secret (a bot token) do NOT
 * implement this — the secret is an ordinary password field in the capability
 * config, and status() reports whether it works.
 *
 * The flow is a small state machine driven by the GUI:
 *
 *   beginAuth()                       → first AuthStep (or done, if already in)
 *   submitAuth($step->step, $input)   → next AuthStep, or the same step with
 *                                       ->error set when the input was rejected
 *   ... until AuthStep::isDone()
 *
 * Each AuthStep carries its own field definitions in the same schema as
 * getConfigFields(), so one generic renderer can draw every provider's login.
 */
interface AuthenticatesInteractivelyInterface
{
    /**
     * Start (or resume) the login flow.
     */
    public function beginAuth(): AuthStep;

    /**
     * Submit the input for a step.
     *
     * @param  string               $step  The step being answered (AuthStep::$step).
     * @param  array<string, mixed> $input Values keyed by the step's field names.
     */
    public function submitAuth(string $step, array $input): AuthStep;

    /**
     * Drop the session. The account is logged out from this preset; the
     * capability config (api credentials etc.) is left intact.
     */
    public function logout(): void;
}
