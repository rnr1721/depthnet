<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Contracts\Settings\SettingsServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Cache\Repository as CacheRepository;
use Inertia\Inertia;

class SettingsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param SettingsServiceInterface $settingsService
     * @param CacheRepository $cache
     */
    public function __construct(
        protected SettingsServiceInterface $settingsService,
        protected CacheRepository $cache
    ) {
    }

    /**
     * Show admin dashboard
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => $this->settingsService->getSettingsForFrontend()
        ]);
    }

    /**
     * Save options
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function saveOptions(Request $request)
    {
        $result = $this->settingsService->validateAndSaveSettings($request);

        if ($result['success']) {
            $this->cache->forget('app_language');
            return back()->with('success', 'Settings saved successfully');
        }

        if (isset($result['errors'])) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return back()->with('error', $result['message'] ?? 'There was an error saving settings');
    }
}
