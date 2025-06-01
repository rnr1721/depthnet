<?php

namespace App\Contracts\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Session\Session;

interface AuthServiceInterface
{
    /**
     * Get current logged in user ID
     *
     * @return integer|null
     */
    public function getCurrentUserId(): ?int;

    /**
     * Get current logged in user
     *
     * @return User|null
     */
    public function getCurrentUser(): ?User;

    /**
     * User authentication
     *
     * @param array $credentials
     * @return bool
     * @throws ValidationException
     */
    public function login(array $credentials): bool;

    /**
     * New User Registration
     *
     * @param array $userData
     * @return User
     */
    public function register(array $userData): User;

    /**
     * User logout
     *
     * @param Session $session
     * @return void
     */
    public function logout(Session $session): void;

    /**
     * Send password reset link to user's email
     *
     * @param string $email
     * @return string
     */
    public function sendPasswordResetLink(string $email): string;

    /**
     * Reset user's password with token
     *
     * @param array $credentials
     * @return string
     */
    public function resetPassword(array $credentials): string;

    /**
     * Check if password reset token is valid
     *
     * @param string $email
     * @param string $token
     * @return bool
     */
    public function isValidResetToken(string $email, string $token): bool;
}
