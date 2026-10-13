<?php

namespace Outl1ne\NovaSortable\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\MorphMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

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

                if (! $this->authorizedToReorder($request, $relatedModels, $model)) {
                    return response()->json(['message' => 'This action is unauthorized.'], 403);
                }

                $relatedModelsCopy = $relatedModels->values();
                foreach ($resourceIds as $i => $id) {
                    $_model = $relatedModelsCopy->firstWhere($relatedKeyName, $id);
                    $relatedModelsCopy = $relatedModelsCopy->forget($relatedModelsCopy->search($_model));
                    $sortOrderNr = $sortedOrder[$i];

                    $_model->{$orderColumnName} = $sortOrderNr;
                    $_model->save();
                }
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

        if (! $this->authorizedToReorder($request, $models, null)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        // Continue with reorder
        foreach ($resourceIds as $i => $id) {
            $_model = $models->firstWhere($modelKeyName, $id);
            $_model->{$orderColumnName} = $sortedOrder[$i];
            $_model->save();
        }

        return response('', 204);
    }

    public function moveToStart(NovaRequest $request)
    {
        $validationResult = $this->validateRequest($request);
        $method = $request->resource()::getOrderByDirection($validationResult->sortable) !== 'DESC' ? 'moveToStart' : 'moveToEnd';
        if (! $this->authorizedToReorder($request, collect([$validationResult->model]), $request->viaResource() ? $request->findParentModel() : null)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }
        $validationResult->model->{$method}();

        return response('', 204);
    }

    public function moveToEnd(NovaRequest $request)
    {
        $validationResult = $this->validateRequest($request);
        $method = $request->resource()::getOrderByDirection($validationResult->sortable) !== 'DESC' ? 'moveToEnd' : 'moveToStart';
        if (! $this->authorizedToReorder($request, collect([$validationResult->model]), $request->viaResource() ? $request->findParentModel() : null)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }
        $validationResult->model->{$method}();

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

        if (! empty($request->input('viaResource')) && ! $this->isRelationshipOfParent($request)) {
            throw ValidationException::withMessages(['viaRelationship' => 'invalid']);
        }

        return $request->resource()::getSortability($request);
    }

    /**
     * viaRelationship is called as a method on the parent model, so it must be a to-many field
     * of the parent resource that lists the sorted resource.
     */
    protected function isRelationshipOfParent(NovaRequest $request): bool
    {
        $parentResourceClass = Nova::resourceForKey($request->input('viaResource'));
        if ($parentResourceClass === null || empty($request->input('viaRelationship'))) {
            return false;
        }

        return (new $parentResourceClass($request->findParentModelOrFail()))->availableFields($request)->contains(
            fn ($field) => ($field instanceof HasMany || $field instanceof MorphMany || $field instanceof BelongsToMany || $field instanceof MorphToMany)
                && $field->attribute === $request->input('viaRelationship')
                && $field->resourceClass === $request->resource()
        );
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
