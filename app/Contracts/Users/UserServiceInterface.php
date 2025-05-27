<?php

namespace App\Contracts\Users;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserServiceInterface
{
    /**
     * Get user by ID
     *
     * @param int $id
     * @return User|null
     */
    public function getUserById(int $id): ?User;

    /**
     * Get the current logged in user
     *
     * @return User|null
     */
    public function getCurrentUser(): ?User;

    /**
     * Update user profile information
     *
     * @param User $user
     * @param array $data
     * @return bool
     */
    public function updateProfile(User $user, array $data): bool;

    /**
     * Update user password
     *
     * @param User $user
     * @param string $newPassword
     * @return bool
     */
    public function updatePassword(User $user, string $newPassword): bool;

    /**
     * Check if user is administrator
     *
     * @param User $user
     * @return bool
     */
    public function isAdmin(User $user): bool;

    /**
     * Get all users
     *
     * @return Collection
     */
    public function getAllUsers();

    /**
     * Get users with pagination
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginatedUsers(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Create a new user
     *
     * @param array $data
     * @return User
     */
    public function createUser(array $data): User;

    /**
     * Delete user
     *
     * @param User $user
     * @return bool
     */
    public function deleteUser(User $user): bool;

    /**
     * Change the user's administrator status
     *
     * @param User $user
     * @param bool $isAdmin
     * @return bool
     */
    public function toggleAdminStatus(User $user, bool $isAdmin): bool;

    /**
     * Search users by request
     *
     * @param string $query
     * @param int $limit
     * @return Collection
     */
    public function searchUsers(string $query, int $limit = 10): Collection;

    /**
     * Bulk deletion of users
     *
     * @param array $userIds
     * @return int Number of deleted users
     */
    public function bulkDeleteUsers(array $userIds): int;

    /**
     * Bulk change of administrator status
     *
     * @param array $userIds
     * @param bool $isAdmin
     * @return int Number of updated users
     */
    public function bulkToggleAdmin(array $userIds, bool $isAdmin): int;

    /**
     * Get user statistics
     *
     * @return array
     */
    public function getUserStats(): array;

    /**
     * Check email availability
     *
     * @param string $email
     * @param int|null $excludeUserId
     * @return bool
     */
    public function isEmailAvailable(string $email, ?int $excludeUserId = null): bool;

    /**
     * Check if user is last administrator
     *
     * @param User $user
     * @return bool
     */
    public function isLastAdmin(User $user): bool;

    /**
     * Deactivate users who have been inactive for a specified number of days
     *
     * @param integer $daysInactive
     * @return integer
     */
    public function deactivateInactiveUsers(int $daysInactive = 90): int;

    /**
     * Get top active users
     *
     * @param integer $limit Number of users to return
     * @return Collection
     */
    public function getTopActiveUsers(int $limit = 10): Collection;

    /**
     * Get users created between two dates
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return Collection
     */
    public function getUsersCreatedBetween(CarbonInterface $from, CarbonInterface $to): Collection;

    /**
     * Get recent users for a given number of days
     *
     * @param integer $days
     * @param integer $limit
     * @return Collection
     */
    public function getRecentUsers(int $days = 7, int $limit = 10): Collection;

    /**
     * Get count of users
     *
     * @return integer Count users
     */
    public function getUserCount(): int;
}
