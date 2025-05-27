<?php

namespace App\Services\Users;

use App\Contracts\Users\AdminUserServiceInterface;
use App\Contracts\Users\UserExporterInterface;
use App\Contracts\Users\UserServiceInterface;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminUserService implements AdminUserServiceInterface
{
    public function __construct(
        protected UserServiceInterface $userService,
        protected UserExporterInterface $userExporter,
        protected User $userModel,
        protected AuthManager $auth,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getUsersListData(): array
    {
        $users = $this->userService->getAllUsers()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ];
            });

        return [
            'users' => $users,
            'currentUserId' => $this->auth->id(),
            'stats' => [
                'total' => $users->count(),
                'admins' => $users->where('is_admin', true)->count(),
                'users' => $users->where('is_admin', false)->count(),
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getUserShowData(User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'email_verified_at' => $user->email_verified_at,
            ],
            'stats' => [
                'messages_count' => $user->messages()->count(),
                'last_login' => $user->updated_at,
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getUserEditData(User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function createUser(array $data): array
    {
        try {
            $user = $this->userService->createUser($data);

            return [
                'type' => 'success',
                'message' => "User '{$user->name}' created!"
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error due user created: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function updateUser(User $user, array $data): array
    {
        try {
            // If the password is not specified, remove it from the data
            if (empty($data['password'])) {
                unset($data['password']);
            }

            $this->userService->updateProfile($user, $data);

            return [
                'type' => 'success',
                'message' => "User data '{$user->name}' was succesfully updated!"
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error on user update: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteUser(User $user): array
    {
        try {
            // Check that the user does not delete himself
            if ($user->id === $this->auth->id()) {
                return [
                    'type' => 'error',
                    'message' => 'You cannot delete your own account!'
                ];
            }

            // We check that we do not delete the last admin
            if ($this->userService->isLastAdmin($user)) {
                return [
                    'type' => 'error',
                    'message' => 'The last system administrator cannot be removed!'
                ];
            }

            $userName = $user->name;
            $this->userService->deleteUser($user);

            return [
                'type' => 'success',
                'message' => "User '{$userName}' succesfully deleted!"
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'Error delete user: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function toggleUserAdmin(User $user, bool $newStatus): array
    {
        try {
            // We check that we do not remove the rights of the last admin
            if (!$newStatus && $this->userService->isLastAdmin($user)) {
                return [
                    'type' => 'error',
                    'message' => 'It is impossible to remove the rights of the last system administrator!'
                ];
            }

            // We check that the user does not remove rights from himself
            if (!$newStatus && $user->id === $this->auth->id()) {
                return [
                    'type' => 'error',
                    'message' => 'You can\'t remove administrator rights from yourself!'
                ];
            }

            $this->userService->toggleAdminStatus($user, $newStatus);

            $message = $newStatus
                ? "User '{$user->name}' now admin!"
                : "User '{$user->name}' has had its administrator rights removed!";

            return [
                'type' => 'success',
                'message' => $message
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'An error occurred while changing permissions: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function performBulkAction(string $action, array $userIds): array
    {
        try {
            $currentUserId = $this->auth->id();

            // Exclude the current user from bulk operations
            $userIds = array_filter($userIds, fn ($id) => $id != $currentUserId);

            if (empty($userIds)) {
                return [
                    'type' => 'error',
                    'message' => 'The operation cannot be performed on your account!'
                ];
            }

            $users = $this->userModel->whereIn('id', $userIds)->get();
            $count = $users->count();

            switch ($action) {
                case 'delete':
                    return $this->handleBulkDelete($users, $count);

                case 'make_admin':
                    return $this->handleBulkMakeAdmin($users, $count);

                case 'remove_admin':
                    return $this->handleBulkRemoveAdmin($users, $userIds, $count);

                default:
                    return [
                        'type' => 'error',
                        'message' => 'Achtung! Unknown operation!'
                    ];
            }
        } catch (\Exception $e) {
            return [
                'type' => 'error',
                'message' => 'An error occurred while performing the operation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    protected function handleBulkDelete($users, int $count): array
    {
        // We check that we do not delete all admins
        $adminIds = $users->where('is_admin', true)->pluck('id');
        $remainingAdmins = $this->userModel->where('is_admin', true)->whereNotIn('id', $adminIds)->count();

        if ($remainingAdmins < 1) {
            return [
                'type' => 'error',
                'message' => 'Operation cancelled: at least one administrator must remain alive!'
            ];
        }

        foreach ($users as $user) {
            $this->userService->deleteUser($user);
        }

        return [
            'type' => 'success',
            'message' => "Users successfully removed: {$count}"
        ];
    }

    /**
     * @inheritDoc
     */
    protected function handleBulkMakeAdmin($users, int $count): array
    {
        foreach ($users as $user) {
            if (!$user->is_admin) {
                $this->userService->toggleAdminStatus($user, true);
            }
        }

        return [
            'type' => 'success',
            'message' => "Administrator rights have been granted to users: {$count}"
        ];
    }

    /**
     * @inheritDoc
     */
    protected function handleBulkRemoveAdmin($users, array $userIds, int $count): array
    {
        // We check that at least one admin will remain
        $nonSelectedAdmins = $this->userModel->where('is_admin', true)->whereNotIn('id', $userIds)->count();

        if ($nonSelectedAdmins < 1) {
            return [
                'type' => 'error',
                'message' => 'Operation cancelled: at least one administrator must remain!'
            ];
        }

        foreach ($users as $user) {
            if ($user->is_admin) {
                $this->userService->toggleAdminStatus($user, false);
            }
        }

        return [
            'type' => 'success',
            'message' => "Administrator rights have been removed from users: {$count}"
        ];
    }

    /**
     * @inheritDoc
     */
    public function exportUsers(): StreamedResponse
    {
        return $this->userExporter->export();
    }

    /**
     * @inheritDoc
     */
    public function canPerformActionOn(User $targetUser, string $action): bool
    {
        $currentUser = $this->auth->user();

        if (!$currentUser || !$currentUser->is_admin) {
            return false;
        }

        // You cannot perform operations on yourself
        if ($currentUser->id === $targetUser->id) {
            return false;
        }

        // When deleting or removing rights from an admin, check that it is not the last one
        if (in_array($action, ['delete', 'remove_admin']) && $targetUser->is_admin) {
            return !$this->userService->isLastAdmin($targetUser);
        }

        return true;
    }

}
