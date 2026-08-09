<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * HandlesCatalogResources
 *
 * Shared, DRY CRUD for the reconciliation "catalog" domains that shipped as
 * models but were never wired into controllers/routes (Service Management,
 * Network configuration, Advanced RADIUS, Inventory extensions, Support/SLA,
 * Communications, Customer Experience, Security, Field Operations and
 * Analytics).
 *
 * Each consuming controller declares a `$catalogResources` map keyed by the
 * URL resource segment → [model, searchable columns, store rules, eager loads].
 *
 * Tenant isolation is guaranteed by each model's BelongsToTenant trait (the
 * global scope + resolveRouteBinding + auto-inject on create). Here we resolve
 * records through the scoped query so a cross-tenant id correctly 404s.
 */
trait HandlesCatalogResources
{
    protected function resourceConfig(string $resource): ?array
    {
        return $this->catalogResources[$resource] ?? null;
    }

    protected function catalogModel(string $resource): ?string
    {
        return $this->resourceConfig($resource)['model'] ?? null;
    }

    public function catalogIndex(Request $request, string $resource): JsonResponse
    {
        $config = $this->resourceConfig($resource);
        $this->requireCatalogResource($resource, $config);

        $query = ($config['model'])::query();

        $search = $request->query('search');
        if ($search && ! empty($config['search'])) {
            $query->where(function ($q) use ($config, $search) {
                foreach ($config['search'] as $index => $column) {
                    $q->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', '%'.$search.'%');
                }
            });
        }

        if (! empty($config['with'])) {
            $query->with($config['with']);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($perPage),
        ]);
    }

    public function catalogShow(Request $request, string $resource, string $id): JsonResponse
    {
        $config = $this->resourceConfig($resource);
        $this->requireCatalogResource($resource, $config);

        $query = ($config['model'])::query();
        if (! empty($config['with'])) {
            $query->with($config['with']);
        }
        $model = $query->findOrFail((int) $id);

        return response()->json([
            'success' => true,
            'data' => $model,
        ]);
    }

    public function catalogStore(Request $request, string $resource): JsonResponse
    {
        $config = $this->resourceConfig($resource);
        $this->requireCatalogResource($resource, $config);

        $request->validate($config['rules'] ?? []);

        $payload = $request->all();
        $payload['created_by'] = $request->user()?->id;

        $model = ($config['model'])::create($payload);

        app(AuditService::class)->log(
            action: Str::singular($resource).'.created',
            model: class_basename($config['model']),
            modelId: $model->id,
            newValues: $model->only(array_keys($config['rules'] ?? [])) ?: [],
        );

        return response()->json([
            'success' => true,
            'message' => Str::title(str_replace('-', ' ', $resource)).' created',
            'data' => $model,
        ], 201);
    }

    public function catalogUpdate(Request $request, string $resource, string $id): JsonResponse
    {
        $config = $this->resourceConfig($resource);
        $this->requireCatalogResource($resource, $config);

        $model = ($config['model'])::findOrFail((int) $id);

        $request->validate($config['rules'] ?? []);

        $payload = $request->all();
        if (in_array('updated_by', $model->getFillable(), true)) {
            $payload['updated_by'] = $request->user()?->id;
        }

        $before = $model->replicate();
        $model->update($payload);

        app(AuditService::class)->log(
            action: Str::singular($resource).'.updated',
            model: class_basename($config['model']),
            modelId: $model->id,
            oldValues: $before->getAttributes(),
            newValues: $model->getAttributes(),
        );

        return response()->json([
            'success' => true,
            'message' => Str::title(str_replace('-', ' ', $resource)).' updated',
            'data' => $model,
        ]);
    }

    public function catalogDestroy(Request $request, string $resource, string $id): JsonResponse
    {
        $config = $this->resourceConfig($resource);
        $this->requireCatalogResource($resource, $config);

        $model = ($config['model'])::findOrFail((int) $id);
        $model->delete();

        app(AuditService::class)->log(
            action: Str::singular($resource).'.deleted',
            model: class_basename($config['model']),
            modelId: (int) $id,
            oldValues: $model->getAttributes(),
        );

        return response()->json([
            'success' => true,
            'message' => Str::title(str_replace('-', ' ', $resource)).' deleted',
        ]);
    }

    protected function requireCatalogResource(string $resource, ?array $config): void
    {
        abort_if($config === null, 404, 'Unknown resource.');
    }
}
