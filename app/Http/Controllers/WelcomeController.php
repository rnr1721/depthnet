<?php

namespace App\Http\Controllers;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function __construct(protected PresetRegistryInterface $presetRegistry)
    {
    }

    public function index()
    {

        $defaultPreset = $this->presetRegistry->getDefaultPreset();

        return Inertia::render('Welcome/Index', [
            'defaultPresetName' => $defaultPreset->getName()
        ]);
    }
}
