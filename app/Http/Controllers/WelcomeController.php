<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected OptionsServiceInterface $optionsService
    ) {
    }

    public function index()
    {

        $registrationEnabled = (bool) $this->optionsService->get('site_enable_registration', true);
        $defaultPreset = $this->presetRegistry->getDefaultPreset();

        return Inertia::render('Welcome/Index', [
            'defaultPresetName' => $defaultPreset->getName(),
            'registrationEnabled' => $registrationEnabled
        ]);
    }
}
