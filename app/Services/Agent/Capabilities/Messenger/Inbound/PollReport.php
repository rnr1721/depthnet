<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

final class PollReport
{
    public const SKIPPED   = 'skipped';
    public const FAILED    = 'failed';
    public const BASELINE  = 'baseline';
    public const DELIVERED = 'delivered';

    private function __construct(
        public readonly string $status,
        public readonly string $detail = '',
        public readonly int $fetched = 0,
        public readonly ?DeliveryReport $delivery = null,
        public readonly bool $truncated = false,
    ) {
    }

    public static function skipped(string $why): self
    {
        return new self(self::SKIPPED, $why);
    }

    public static function failed(string $error, string $code): self
    {
        return new self(self::FAILED, "{$code}: {$error}");
    }

    public static function baseline(): self
    {
        return new self(self::BASELINE, 'baseline taken — new messages are reported from now on');
    }

    public static function delivered(int $fetched, DeliveryReport $delivery, bool $truncated): self
    {
        return new self(self::DELIVERED, '', $fetched, $delivery, $truncated);
    }

    public function summary(): string
    {
        if ($this->status !== self::DELIVERED) {
            return "{$this->status}: {$this->detail}";
        }

        $d = $this->delivery;

        return sprintf(
            'fetched %d, delivered %d%s%s%s',
            $this->fetched,
            $d?->delivered ?? 0,
            $d && $d->delivered ? ($d->toPool ? ' to pool' : ' to history') : '',
            $d && $d->wanted ? ($d->woke ? ', woke agent' : ', wake deferred/not needed') : '',
            $this->truncated ? ', more pending' : '',
        );
    }
}
