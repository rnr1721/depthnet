<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * One step of an interactive login, described for a generic renderer.
 *
 * $fields uses the same schema as CapabilityProviderInterface::getConfigFields()
 * (type, label, description, placeholder, required), so the GUI draws a login
 * step with the same field component it uses for capability config.
 *
 * A rejected input comes back as the SAME step with $error set — the GUI keeps
 * the form and shows the error instead of moving on.
 *
 * Step names are provider-defined; the GUI only needs isDone(). Common ones are
 * provided as constants so providers stay consistent where it costs nothing.
 *
 * $actions are secondary buttons next to the form ("send the code another
 * way"). Pressing one submits its `step` with empty input; the provider answers
 * with the next AuthStep as usual. An action may carry `wait` seconds — the GUI
 * keeps it disabled with a countdown until then.
 */
final class AuthStep
{
    public const STEP_PHONE    = 'phone';
    public const STEP_CODE     = 'code';
    public const STEP_PASSWORD = 'password';
    public const STEP_QR       = 'qr';
    public const STEP_DONE     = 'done';
    public const STEP_RESEND   = 'resend';

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $data   Extra payload for special steps
     *                                                    (e.g. ['qr' => '<url>'] for STEP_QR).
     * @param array<int, array{step: string, label: string, wait?: int}> $actions
     */
    public function __construct(
        public readonly string $step,
        public readonly array $fields = [],
        public readonly ?string $message = null,
        public readonly ?string $error = null,
        public readonly ?Peer $account = null,
        public readonly array $data = [],
        public readonly array $actions = [],
    ) {
    }

    public static function done(Peer $account, ?string $message = null): self
    {
        return new self(self::STEP_DONE, [], $message, null, $account);
    }

    public function isDone(): bool
    {
        return $this->step === self::STEP_DONE;
    }

    /** Same step, now carrying an error. */
    public function withError(string $error): self
    {
        return new self($this->step, $this->fields, $this->message, $error, $this->account, $this->data, $this->actions);
    }

    public function toArray(): array
    {
        return [
            'step'    => $this->step,
            'done'    => $this->isDone(),
            'fields'  => $this->fields,
            'message' => $this->message,
            'error'   => $this->error,
            'account' => $this->account?->toArray(),
            'data'    => $this->data,
            'actions' => array_values($this->actions),
        ];
    }
}
