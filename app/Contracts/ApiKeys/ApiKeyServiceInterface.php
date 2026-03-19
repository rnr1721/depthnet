<?php

namespace App\Contracts\ApiKeys;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ApiKeyServiceInterface
{
    /**
     * Maximum number of active keys per user.
     */
    public const MAX_KEYS_PER_USER = 5;

    /**
     * Create a new API key for the given user.
     * Returns the plain-text key (shown once) and the persisted model.
     *
     * @return array{ plaintext: string, apiKey: ApiKey }
     * @throws \RuntimeException when the user already has MAX_KEYS_PER_USER active keys.
     */
    public function createKey(User $user, string $name): array;

    /**
     * Revoke a specific key that belongs to the given user.
     */
    public function revokeKey(User $user, int $keyId): void;

    /**
     * Return all keys (active + revoked) for the given user.
     */
    public function listKeys(User $user): Collection;

    /**
     * Look up an active ApiKey by its plain-text value.
     * Returns null when not found or already revoked.
     */
    public function findByPlaintext(string $plaintext): ?ApiKey;
}
