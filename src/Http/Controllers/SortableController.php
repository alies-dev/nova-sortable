<?php

namespace Outl1ne\NovaSortable\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Outl1ne\NovaSortable\Events\RowsReordered;
use Spatie\EloquentSortable\EloquentModelSortedEvent;

class SortableController
{
    public function updateOrder(NovaRequest $request)
    {
        $validationResult = $this->validateRequest($request);
        $model = $validationResult->model;

        $resourceName = $request->route('resource');
        $resourceIds = $request->input('resourceIds');
        $viaResource = $request->input('viaResource');
        $viaResourceId = $request->input('viaResourceId');
        $viaRelationship = $request->input('viaRelationship');
        $relationshipType = $request->input('relationshipType');

        // Reverse the array if it is configured to order by DESC.
        if ($request->resource()::getOrderByDirection($validationResult->sortable) === 'DESC') {
            $resourceIds = array_reverse($resourceIds);
        }

        // Relationship sorting
        if (! empty($viaResource)) {
            $resourceClass = Nova::resourceForKey($viaResource);
            if (empty($resourceClass)) {
                return response()->json(['resourceName' => 'invalid'], 400);
            }

            $modelClass = $resourceClass::$model;
            $model = $modelClass::find($viaResourceId);
            $relatedModels = $model->{$viaRelationship}()->findMany($resourceIds);
            if ($relatedModels->count() !== count($resourceIds)) {
                return response()->json(['resourceIds' => 'invalid'], 400);
            }

            // BelongsToMany
            if ($relationshipType === 'belongsToMany' || $relationshipType === 'morphToMany') {
                $relatedModels = $relatedModels->pluck(
                    $model->{$viaRelationship}()->getPivotAccessor()
                );
            }

            $relatedModel = $relatedModels->first();

            if (! empty($relatedModel)) {
                $orderColumnName = $relatedModel->determineOrderColumnName();
                $relatedKeyName = ($relationshipType === 'belongsToMany' || $relationshipType === 'morphToMany')
                    ? $model->{$viaRelationship}()->getRelatedPivotKeyName()
                    : $relatedModel->getKeyName();

                // Sort orderColumn values
                $sortedOrder = $relatedModels->pluck($orderColumnName)->sort()->values();
                $sortedOrder = $this->fixSortOrder($sortedOrder);

                // Validate if can be sorted
                if (method_exists($resourceClass, 'canSort')) {
                    $relatedModelsCopy = $relatedModels->values();

                    foreach ($resourceIds as $i => $id) {
                        $_model = $relatedModelsCopy->firstWhere($relatedKeyName, $id);
                        $relatedModelsCopy = $relatedModelsCopy->forget($relatedModelsCopy->search($_model));
                        $sortOrderNr = $sortedOrder[$i];

                        $canSort = $resourceClass::canSort($request, $_model);
                        if (! $canSort) {
                            $currentOrderNr = $_model->{$orderColumnName};

                            // canSort was false - check if the position changed
                            if ($currentOrderNr !== $sortedOrder[$i]) {
                                // Order changed - show error
                                return response()->json(['canNotReorder' => $id], 400);
                            }
                        }
                    }
                }

                $relatedModelsCopy = $relatedModels->values();
                $orderedModels = [];
                foreach ($resourceIds as $id) {
                    $_model = $relatedModelsCopy->firstWhere($relatedKeyName, $id);
                    $relatedModelsCopy = $relatedModelsCopy->forget($relatedModelsCopy->search($_model));
                    $orderedModels[] = $_model;
                }

                return $this->reorder($request, collect($orderedModels), $sortedOrder, $orderColumnName, $model);
            }

            return response('', 204);
        }

        // Regular ordering
        $resourceClass = Nova::resourceForKey($resourceName);
        if (empty($resourceClass)) {
            return response()->json(['resourceName' => 'invalid'], 400);
        }

        $modelClass = $resourceClass::$model;
        $query = $modelClass::query();
        if (method_exists($modelClass, 'trashed')) {
            $models = $resourceClass::indexQuery($request, $query)->withTrashed()->findMany($resourceIds);
        } else {
            $models = $resourceClass::indexQuery($request, $query)->findMany($resourceIds);
        }
        if ($models->count() !== count($resourceIds)) {
            return response()->json(['resourceIds' => 'invalid'], 400);
        }

        $model = $models->first();
        $modelKeyName = $model->getKeyName();
        $orderColumnName = $model->determineOrderColumnName();

        // Sort orderColumn values
        $sortedOrder = $models->pluck($orderColumnName)->sort()->values();
        $sortedOrder = $this->fixSortOrder($sortedOrder);

        // Validate if can be sorted
        if (method_exists($resourceClass, 'canSort')) {
            foreach ($resourceIds as $i => $id) {
                $_model = $models->firstWhere($modelKeyName, $id);
                $canSort = $resourceClass::canSort($request, $_model);
                if (! $canSort) {
                    // canSort was false - check if the position changed
                    if ($_model->{$orderColumnName} !== $sortedOrder[$i]) {
                        // Order changed - show error
                        return response()->json(['canNotReorder' => $id], 400);
                    }
                }
            }
        }

        $orderedModels = collect($resourceIds)->map(fn ($id) => $models->firstWhere($modelKeyName, $id));

        return $this->reorder($request, $orderedModels, $sortedOrder, $orderColumnName);
    }

    public function moveToStart(NovaRequest $request)
    {
        $validationResult = $this->validateRequest($request);
        $method = $request->resource()::getOrderByDirection($validationResult->sortable) !== 'DESC' ? 'moveToStart' : 'moveToEnd';

        return $this->move($validationResult->model, $method, $request);
    }

    public function moveToEnd(NovaRequest $request)
    {
        $validationResult = $this->validateRequest($request);
        $method = $request->resource()::getOrderByDirection($validationResult->sortable) !== 'DESC' ? 'moveToEnd' : 'moveToStart';

        return $this->move($validationResult->model, $method, $request);
    }

    /**
     * Writes the new order and lets listeners veto it, all in one transaction.
     */
    protected function reorder(NovaRequest $request, Collection $orderedModels, $sortedOrder, string $orderColumnName, ?Model $parent = null)
    {
        if (! $this->authorizedToReorder($request, $orderedModels, $parent)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $modelClass = $orderedModels->first()::class;

        return $this->inTransaction(function () use ($orderedModels, $sortedOrder, $orderColumnName, $parent, $modelClass) {
            foreach ($orderedModels->values() as $i => $_model) {
                $_model->{$orderColumnName} = $sortedOrder[$i];
                $_model->save();
            }

            Event::dispatch(new EloquentModelSortedEvent($modelClass));
            Event::dispatch(new RowsReordered($modelClass, $orderedModels->values(), $parent));
        });
    }

    protected function move(Model $model, string $method, NovaRequest $request)
    {
        $parent = $request->viaResource() ? $request->findParentModel() : null;

        if (! $this->authorizedToReorder($request, collect([$model]), $parent)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return $this->inTransaction(function () use ($model, $method, $parent) {
            $model->{$method}();

            Event::dispatch(new EloquentModelSortedEvent($model::class));
            Event::dispatch(new RowsReordered($model::class, collect([$model]), $parent));
        });
    }

    /**
     * The frontend hides reorder controls without update rights; enforce the same rule on the server.
     */
    protected function authorizedToReorder(NovaRequest $request, Collection $models, ?Model $parent): bool
    {
        $resourceClass = $request->resource();
        if ($resourceClass::getSortabilityConfiguration($models->first())['ignore_policies'] ?? false) {
            return true;
        }

        // Pivot rows have no resource of their own: reordering them edits the parent.
        if (in_array($request->input('relationshipType'), ['belongsToMany', 'morphToMany'], true)) {
            return $parent !== null && Nova::newResourceFromModel($parent)->authorizedToUpdate($request);
        }

        return $models->every(fn (Model $model) => (new $resourceClass($model))->authorizedToUpdate($request));
    }

    protected function inTransaction(\Closure $callback)
    {
        try {
            DB::transaction($callback);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response('', 204);
    }

    protected function validateRequest(NovaRequest $request)
    {
        $request->validate([
            'resourceId' => 'present',
            'resourceIds' => 'required_if:resourceId,null',
            'relatedResource' => 'present',
            'relationshipType' => 'present',
            'viaRelationship' => 'present',
            'viaResource' => 'present',
            'viaResourceId' => 'present',
        ]);

        return $request->resource()::getSortability($request);
    }

    protected function fixSortOrder($sortOrder)
    {
        $improvedSortedOrder = [];
        $previousSortOrderNr = null;
        foreach ($sortOrder as $i => $orderNr) {
            $sortOrderNr = $orderNr ?? ((int) $previousSortOrderNr) + 1;
            if ($sortOrderNr === $previousSortOrderNr) {
                $sortOrderNr += 1;
            }
            $previousSortOrderNr = $sortOrderNr;
            $improvedSortedOrder[$i] = $sortOrderNr;
        }

        return $improvedSortedOrder;
    }
}
