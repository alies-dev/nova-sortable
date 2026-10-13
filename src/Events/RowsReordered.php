<?php

namespace Outl1ne\NovaSortable\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Dispatched inside the reorder transaction, after the new order is written.
 * Throwing a \DomainException from a listener rolls the reorder back and shows its message to the user.
 */
class RowsReordered
{
    /**
     * @param class-string<Model> $model
     * @param Collection<int, Model> $models Rows the user moved, in their new order
     * @param Model|null $parent Parent model when sorting via a relationship
     */
    public function __construct(
        public readonly string $model,
        public readonly Collection $models,
        public readonly ?Model $parent = null,
    ) {}

    public function isFor(Model|string $model): bool
    {
        return (is_string($model) ? $model : $model::class) === $this->model;
    }
}
