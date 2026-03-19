<?php

namespace App\Services\ApiKeys;

use App\Contracts\ApiKeys\ApiKeyServiceInterface;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ApiKeyService implements ApiKeyServiceInterface
{
    // Prefix makes keys instantly recognisable in logs / config files
    private const KEY_PREFIX_STRING = 'sk-';
    private const KEY_RANDOM_LENGTH = 40; // bytes → 80 hex chars

    public function __construct(
        protected ApiKey $apiKeyModel,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createKey(User $user, string $name): array
    {
        $activeCount = $this->apiKeyModel
            ->where('user_id', $user->id)
            ->active()
            ->count();

        if ($activeCount >= self::MAX_KEYS_PER_USER) {
            throw new \RuntimeException(
                "You have reached the limit of " . self::MAX_KEYS_PER_USER . " active API keys."
            );
        }

        // Generate the full key: "sk-<80 hex chars>"
        $randomPart = bin2hex(random_bytes(self::KEY_RANDOM_LENGTH));
        $plaintext  = self::KEY_PREFIX_STRING . $randomPart;

        // First 8 chars after "sk-" are stored as the visible prefix, e.g. "sk-a1b2c3d4"
        $prefix = self::KEY_PREFIX_STRING . substr($randomPart, 0, 8);
        $hash   = hash('sha256', $plaintext);

        $apiKey = $this->apiKeyModel->create([
            'user_id'    => $user->id,
            'name'       => $name,
            'key_prefix' => $prefix,
            'key_hash'   => $hash,
        ]);

        return [
            'plaintext' => $plaintext,
            'apiKey'    => $apiKey,
        ];
    }

    /**
     * @inheritDoc
     */
    public function revokeKey(User $user, int $keyId): void
    {
        $key = $this->apiKeyModel
            ->where('user_id', $user->id)
            ->where('id', $keyId)
            ->active()
            ->firstOrFail();

        $key->update(['revoked_at' => now()]);
    }

    /**
     * @inheritDoc
     */
    public function listKeys(User $user): Collection
    {
        return $this->apiKeyModel
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function findByPlaintext(string $plaintext): ?ApiKey
    {
        $hash = hash('sha256', $plaintext);

        $key = $this->apiKeyModel
            ->with('user')
            ->where('key_hash', $hash)
            ->active()
            ->first();

        return $key;
    }
}
