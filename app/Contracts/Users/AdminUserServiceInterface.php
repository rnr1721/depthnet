<?php

namespace App\Contracts\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface AdminUserServiceInterface
{
    /**
     * Get data for a list of users
     *
     * @return array
     */
    public function getUsersListData(): array;

    /**
     * Get user view data
     *
     * @param User $user
     * @return array
     */
    public function getUserShowData(User $user): array;

    /**
     * Get data to edit user
     *
     * @param User $user
     * @return array
     */
    public function getUserEditData(User $user): array;

    /**
     * Create a new user
     *
     * @param array $data
     * @return array
     */
    public function createUser(array $data): array;

    /**
     * Update user
     *
     * @param User $user
     * @param array $data
     * @return array
     */
    public function updateUser(User $user, array $data): array;

    /**
     * Delete user
     *
     * @param User $user
     * @return array
     */
    public function deleteUser(User $user): array;

    /**
     * Toggle Administrator Rights
     *
     * @param User $user
     * @param boolean $newStatus
     * @return array
     */
    public function toggleUserAdmin(User $user, bool $newStatus): array;

    /**
     * Perform bulk operations
     *
     * @param string $action
     * @param array $userIds
     * @return array
     */
    public function performBulkAction(string $action, array $userIds): array;

    /**
     * Export Users to CSV, Excel, or PDF
     *
     * @return StreamedResponse
     */
    public function exportUsers(): StreamedResponse;

}
