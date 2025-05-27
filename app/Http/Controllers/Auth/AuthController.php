<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\Auth\AuthServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(
        protected AuthServiceInterface $authService,
        protected PasswordBrokerContract $passwordBroker
    ) {
    }

    /**
     * Show the login form
     *
     * @return Response
     */
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Process login request
     *
     * @param LoginRequest $request
     * @return RedirectResponse
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $this->authService->login($request->validated());

        $request->session()->regenerate();

        return redirect()->intended('/chat');
    }

    /**
     * Show the registration form
     *
     * @return Response
     */
    public function showRegistrationForm(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Process registration request
     *
     * @param RegisterRequest $request
     * @return RedirectResponse
     */
    public function register(RegisterRequest $request): RedirectResponse
    {
        $this->authService->register($request->validated());

        return redirect('/chat');
    }

    /**
     * Logout the user
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function logout(Request $request): RedirectResponse
    {
        $this->authService->logout($request->session());

        return redirect('/');
    }

    /**
     * Show forgot password form
     *
     * @return Response
     */
    public function showForgotPasswordForm(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Send password reset link
     *
     * @param ForgotPasswordRequest $request
     * @return RedirectResponse
     */
    public function sendResetLinkEmail(ForgotPasswordRequest $request): RedirectResponse
    {
        $status = $this->authService->sendPasswordResetLink($request->validated('email'));

        return $this->isResetLinkSent($status)
            ? back()->with(['status' => __($status)])
            : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Show password reset form
     *
     * @param Request $request
     * @param string $token
     * @return Response
     */
    public function showResetPasswordForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->email
        ]);
    }

    /**
     * Reset user password
     *
     * @param ResetPasswordRequest $request
     * @return RedirectResponse
     */
    public function resetPassword(ResetPasswordRequest $request): RedirectResponse
    {
        $status = $this->authService->resetPassword($request->validated());

        return $this->isPasswordReset($status)
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => [__($status)]]);
    }

    /**
     * Check if reset link was sent successfully
     */
    private function isResetLinkSent(string $status): bool
    {
        return $status === $this->passwordBroker::RESET_LINK_SENT;
    }

    /**
     * Check if password was reset successfully
     */
    private function isPasswordReset(string $status): bool
    {
        return $status === $this->passwordBroker::PASSWORD_RESET;
    }
}
