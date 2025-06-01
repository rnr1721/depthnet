<?php

namespace App\Services\Auth;

use App\Contracts\Auth\AuthServiceInterface;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public function __construct(
        protected User $userModel,
        protected AuthFactory $authFactory,
        protected AuthManager $authManager,
        protected Hasher $hasher,
        protected PasswordBrokerContract $passwordBroker
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCurrentUser(): ?User
    {
        return $this->authManager->user();
    }

    /**
     * @inheritDoc
     */
    public function getCurrentUserId(): ?int
    {
        return $this->authManager->id();
    }

    /**
     * @inheritDoc
     */
    public function login(array $credentials): bool
    {
        $remember = $credentials['remember'] ?? false;
        $loginCredentials = [
            'email' => $credentials['email'],
            'password' => $credentials['password']
        ];

        if ($this->authFactory->attempt($loginCredentials, $remember)) {
            return true;
        }

        throw ValidationException::withMessages([
            'email' => 'Неверные учетные данные.',
        ]);
    }

    /**
     * @inheritDoc
     */
    public function register(array $userData): User
    {
        $user = $this->userModel->create([
            'name' => $userData['name'],
            'email' => $userData['email'],
            'password' => $this->hasher->make($userData['password']),
        ]);

        $this->authFactory->login($user);

        return $user;
    }

    /**
     * @inheritDoc
     */
    public function logout(Session $session): void
    {
        $this->authFactory->logout();
        $session->invalidate();
        $session->regenerateToken();
    }

    /**
     * @inheritDoc
     */
    public function sendPasswordResetLink(string $email): string
    {
        return $this->passwordBroker->sendResetLink(['email' => $email]);
    }

    /**
     * @inheritDoc
     */
    public function resetPassword(array $credentials): string
    {
        return $this->passwordBroker->reset(
            $credentials,
            function (User $user, string $password) {
                $this->updateUserPassword($user, $password);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function isValidResetToken(string $email, string $token): bool
    {
        $user = $this->userModel->where('email', $email)->first();

        if (!$user) {
            return false;
        }

        return $this->passwordBroker->tokenExists($user, $token);
    }

    /**
     * Update user's password and generate new remember token
     *
     * @param User $user
     * @param string $password
     * @return void
     */
    protected function updateUserPassword(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $this->hasher->make($password),
            'remember_token' => Str::random(60),
        ])->save();

        // Fire password reset event
        event(new PasswordReset($user));
    }
}
