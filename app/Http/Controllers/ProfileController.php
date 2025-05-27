<?php

namespace App\Http\Controllers;

use App\Contracts\Users\UserServiceInterface;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Inertia\Inertia;

class ProfileController extends Controller
{
    protected UserServiceInterface $userService;

    /**
     * Constructor
     *
     * @param UserServiceInterface $userService
     */
    public function __construct(UserServiceInterface $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Show user profile page
     *
     * @return \Inertia\Response
     */
    public function show()
    {
        $user = $this->userService->getCurrentUser();

        return Inertia::render('Profile/Index', [
            'user' => $user->only('id', 'name', 'email', 'is_admin'),
        ]);
    }

    /**
     * Update user profile information
     *
     * @param UpdateProfileRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateProfileRequest $request)
    {
        $user = $this->userService->getCurrentUser();
        $this->userService->updateProfile($user, $request->validated());

        return redirect()->route('profile.show')->with('success', 'Profile updated successfully.');
    }

    /**
     * Update user password
     *
     * @param UpdatePasswordRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePassword(UpdatePasswordRequest $request)
    {
        $user = $this->userService->getCurrentUser();
        $this->userService->updatePassword($user, $request->validated('password'));

        return redirect()->route('profile.show')->with('success', 'Password updated successfully.');
    }
}
