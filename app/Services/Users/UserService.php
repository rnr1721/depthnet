<?php

namespace App\Services\Users;

use App\Contracts\Auth\AuthServiceInterface;
use App\Contracts\Users\UserServiceInterface;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UserService implements UserServiceInterface
{
    public function __construct(
        protected AuthServiceInterface $authService,
        protected User $userModel,
        protected Hasher $hash
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findUserById(int $id): ?User
    {
        return $this->userModel->find($id);
    }

    /**
     * @inheritDoc
     */
    public function getUserById(int $id): User
    {
        return $this->userModel->findOrFail($id);
    }

    /**
     * @inheritDoc
     */
    public function updateProfile(User $user, array $data): bool
    {
        if (isset($data['password'])) {
            $data['password'] = $this->hash->make($data['password']);
        }

        return $user->update($data);
    }

    /**
     * @inheritDoc
     */
    public function updatePassword(User $user, string $newPassword): bool
    {
        $user->password = $this->hash->make($newPassword);
        return $user->save();
    }

    /**
     * @inheritDoc
     */
    public function isAdmin(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * @inheritDoc
     */
    public function getAllUsers(): Collection
    {
        return $this->userModel->orderBy('created_at', 'desc')->get();
    }

    /**
     * @inheritDoc
     */
    public function getPaginatedUsers(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->userModel->query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if (isset($filters['is_admin'])) {
            $query->where('is_admin', $filters['is_admin']);
        }

        if (!empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * @inheritDoc
     */
    public function createUser(array $data): User
    {
        return $this->userModel->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $this->hash->make($data['password']),
            'is_admin' => $data['is_admin'] ?? false,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function deleteUser(User $user): bool
    {
        return $user->delete();
    }

    /**
     * @inheritDoc
     */
    public function toggleAdminStatus(User $user, bool $isAdmin): bool
    {
        $user->is_admin = $isAdmin;
        return $user->save();
    }

    /**
     * @inheritDoc
     */
    public function searchUsers(string $query, int $limit = 10): Collection
    {
        return $this->userModel
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('email', 'LIKE', "%{$query}%");
            })
            ->limit($limit)
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function bulkDeleteUsers(array $userIds): int
    {
        $currentUserId = $this->authService->getCurrentUserId();
        $userIds = array_filter($userIds, fn ($id) => $id != $currentUserId);

        if (empty($userIds)) {
            return 0;
        }

        return $this->userModel->whereIn('id', $userIds)->delete();
    }

    /**
     * @inheritDoc
     */
    public function bulkToggleAdmin(array $userIds, bool $isAdmin): int
    {
        $currentUserId = $this->authService->getCurrentUserId();
        $userIds = array_filter($userIds, fn ($id) => $id != $currentUserId);

        if (empty($userIds)) {
            return 0;
        }

        return $this->userModel->whereIn('id', $userIds)->update(['is_admin' => $isAdmin]);
    }

    /**
     * @inheritDoc
     */
    public function getUserStats(): array
    {
        return [
            'total' => $this->userModel->count(),
            'admins' => $this->userModel->where('is_admin', true)->count(),
            'users' => $this->userModel->where('is_admin', false)->count(),
            'verified' => $this->userModel->whereNotNull('email_verified_at')->count(),
            'unverified' => $this->userModel->whereNull('email_verified_at')->count(),
            'active_today' => $this->userModel->whereDate('updated_at', today())->count(),
            'new_this_week' => $this->userModel->whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek()
            ])->count(),
            'new_this_month' => $this->userModel->whereBetween('created_at', [
                now()->startOfMonth(),
                now()->endOfMonth()
            ])->count(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function isEmailAvailable(string $email, ?int $excludeUserId = null): bool
    {
        $query = $this->userModel->where('email', $email);

        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }

        return !$query->exists();
    }

    /**
     * @inheritDoc
     */
    public function getUsersCreatedBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->userModel
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function getTopActiveUsers(int $limit = 10): Collection
    {
        return $this->userModel
            ->withCount('messages')
            ->orderBy('messages_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function deactivateInactiveUsers(int $daysInactive = 90): int
    {
        $cutoffDate = now()->subDays($daysInactive);

        return $this->userModel
            ->where('updated_at', '<', $cutoffDate)
            ->where('is_admin', false)
            ->update(['email_verified_at' => null]);
    }

    /**
     * @inheritDoc
     */
    public function isLastAdmin(User $user): bool
    {
        if (!$user->is_admin) {
            return false;
        }

        return $this->userModel->where('is_admin', true)->count() <= 1;
    }

    /**
     * @inheritDoc
     */
    public function getRecentUsers(int $days = 7, int $limit = 10): Collection
    {
        return $this->userModel
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function getUserCount(): int
    {
        return $this->userModel->count();
    }
}
