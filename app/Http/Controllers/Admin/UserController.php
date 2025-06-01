<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Auth\AuthServiceInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Contracts\Users\AdminUserServiceInterface;
use App\Contracts\Users\UserServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        protected UserServiceInterface $userService,
        protected AdminUserServiceInterface $adminUserService,
        protected AuthServiceInterface $authService,
    ) {
    }

    /**
     * Показать список всех пользователей
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->get('search'),
            'is_admin' => $request->get('role') === 'admin' ? true : ($request->get('role') === 'user' ? false : null),
        ];

        $users = $this->userService->getPaginatedUsers(10, $filters);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'currentUserId' => $this->authService->getCurrentUserId(),
            'filters' => $filters,
        ]);
    }

    /**
     * Create a new user
     *
     * @param CreateUserRequest $request
     * @return RedirectResponse
     */
    public function store(CreateUserRequest $request): RedirectResponse
    {
        $result = $this->adminUserService->createUser($request->validated());

        return redirect()
            ->route('admin.users.index')
            ->with($result['type'], $result['message']);
    }

    /**
     * Update user data
     *
     * @param UpdateUserRequest $request
     * @param User $user
     * @return RedirectResponse
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $result = $this->adminUserService->updateUser($user, $request->validated());

        return redirect()
            ->back()
            ->with($result['type'], $result['message']);
    }

    /**
     * Delete user
     *
     * @param User $user
     * @return RedirectResponse
     */
    public function destroy(User $user): RedirectResponse
    {
        $result = $this->adminUserService->deleteUser($user);

        return redirect()
            ->route('admin.users.index')
            ->with($result['type'], $result['message']);
    }

    /**
     * Toggle user's administrator status
     *
     * @param Request $request
     * @param User $user
     * @return RedirectResponse
     */
    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        $newStatus = $request->boolean('is_admin');
        $result = $this->adminUserService->toggleUserAdmin($user, $newStatus);

        return redirect()
            ->back()
            ->with($result['type'], $result['message']);
    }

    /**
     * Export Users to CSV
     *
     * @return StreamedResponse
     */
    public function export(): StreamedResponse
    {
        return $this->adminUserService->exportUsers();
    }
}
