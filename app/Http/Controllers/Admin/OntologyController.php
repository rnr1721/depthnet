<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Ontology\OntologyServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ontology\OntologyPresetRequest;
use App\Http\Requests\Admin\Ontology\StoreOntologyNodeRequest;
use App\Http\Requests\Admin\Ontology\StoreOntologyEdgeRequest;
use App\Http\Requests\Admin\Ontology\DeleteOntologyNodeRequest;
use App\Http\Requests\Admin\Ontology\DeleteOntologyEdgeRequest;
use App\Http\Requests\Admin\Ontology\UpdateOntologyNodeRequest;
use App\Http\Requests\Admin\Ontology\UpdateOntologyEdgeRequest;
use App\Services\Agent\Ontology\OntologyQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OntologyController extends Controller
{
    public function __construct(
        protected OntologyServiceInterface $ontologyService,
        protected OntologyQueryService     $ontologyQueryService,
        protected PresetRegistryInterface  $presetRegistry,
    ) {
    }

    public function index(Request $request)
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id'         => $preset->id,
                'name'       => $preset->name,
                'is_default' => $preset->is_default,
            ])
            ->sortByDesc('is_default')
            ->values();

        $currentPresetId = $request->input('preset_id', $this->presetRegistry->getDefaultPreset()->id);
        $currentPreset   = $this->presetRegistry->getPresetOrDefault($currentPresetId);

        $search      = (string) $request->input('search', '');
        $filterClass = (string) $request->input('class', '');
        $perPage     = max(10, min(100, (int) $request->input('per_page', 25)));

        $data = $currentPreset
            ? $this->ontologyQueryService->listForAdmin($currentPreset, $search, $filterClass, $perPage)
            : ['nodes' => collect(), 'pagination' => null, 'stats' => [], 'available_classes' => collect()];

        return Inertia::render('Admin/Ontology/Index', [
            'presets'          => $presets,
            'currentPreset'    => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'nodes'            => $data['nodes'],
            'pagination'       => $data['pagination'],
            'stats'            => $data['stats'],
            'search'           => $search,
            'filterClass'      => $filterClass,
            'perPage'          => $perPage,
            'availableClasses' => $data['available_classes'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Node CRUD
    // -------------------------------------------------------------------------

    public function storeNode(StoreOntologyNodeRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        $result = $this->ontologyService->addNode($preset, [
            'name'    => $request->validated('canonical_name'),
            'class'   => $request->validated('class'),
            'aliases' => $request->validated('aliases') ?: [],
        ]);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    public function updateNode(UpdateOntologyNodeRequest $request, int $nodeId)
    {
        $node   = $this->ontologyQueryService->findOrFailNode($nodeId);
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        $aliases = $request->validated('aliases');
        if (is_array($aliases)) {
            $aliases = array_values(array_filter(array_map('trim', $aliases)));
        }

        $params = [];

        if ($request->has('canonical_name')) {
            $params['canonical_name'] = $request->validated('canonical_name');
        }
        if ($request->has('class')) {
            $params['class'] = $request->validated('class');
        }
        if ($request->has('aliases')) {
            $params['aliases'] = $aliases;
        }
        if ($request->has('weight')) {
            $params['weight'] = $request->validated('weight');
        }

        $result = $this->ontologyService->updateNode($preset, $node, $params);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function destroyNode(DeleteOntologyNodeRequest $request, int $nodeId)
    {
        $node = $this->ontologyQueryService->findOrFailNode($nodeId);
        $name = $node->canonical_name;
        $node->delete();

        return back()->with('success', "Node \"{$name}\" deleted.");
    }

    // -------------------------------------------------------------------------
    // Edge CRUD
    // -------------------------------------------------------------------------

    public function storeEdge(StoreOntologyEdgeRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        $result = $this->ontologyService->addEdge($preset, [
            'source'   => $request->validated('source'),
            'target'   => $request->validated('target'),
            'relation' => $request->validated('relation_type'),
            'weight'   => $request->validated('weight') ?? 1.0,
        ]);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    public function updateEdge(UpdateOntologyEdgeRequest $request, int $edgeId)
    {
        $edge = $this->ontologyQueryService->findOrFailEdge($edgeId);
        $edge->update([
            'relation_type' => $request->validated('relation_type'),
            'weight'        => $request->validated('weight') ?? $edge->weight,
        ]);

        return back()->with('success', 'Edge updated.');
    }

    public function destroyEdge(DeleteOntologyEdgeRequest $request, int $edgeId)
    {
        $edge = $this->ontologyQueryService->findOrFailEdge($edgeId);
        $edge->delete();

        return back()->with('success', 'Edge deleted.');
    }

    // -------------------------------------------------------------------------
    // Bulk
    // -------------------------------------------------------------------------

    public function clear(OntologyPresetRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->ontologyService->clear($preset);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }
}
