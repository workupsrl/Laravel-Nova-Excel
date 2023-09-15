<?php

namespace Workup\Nova\LaravelNovaExcel\Actions;

use Laravel\Nova\Resource;
use Illuminate\Support\Arr;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Gravatar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Concerns\WithMapping;
use Laravel\Nova\Http\Requests\ActionRequest;
use Workup\Nova\LaravelNovaExcel\Concerns\Only;
use Workup\Nova\LaravelNovaExcel\Concerns\Except;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Workup\Nova\LaravelNovaExcel\Concerns\WithDisk;
use Workup\Nova\LaravelNovaExcel\Concerns\WithFilename;
use Workup\Nova\LaravelNovaExcel\Concerns\WithHeadings;
use Workup\Nova\LaravelNovaExcel\Concerns\WithChunkCount;
use Workup\Nova\LaravelNovaExcel\Concerns\WithWriterType;
use Workup\Nova\LaravelNovaExcel\Interactions\AskForFilename;
use Workup\Nova\LaravelNovaExcel\Requests\ExportActionRequest;
use Workup\Nova\LaravelNovaExcel\Interactions\AskForWriterType;
use Maatwebsite\Excel\Concerns\WithHeadings as WithHeadingsConcern;
use Workup\Nova\LaravelNovaExcel\Requests\ExportActionRequestFactory;

class ExportToExcel extends Action implements FromQuery, WithCustomChunkSize, WithHeadingsConcern, WithMapping
{
    use AskForFilename;
    use AskForWriterType;
    use Except;
    use Only;
    use WithChunkCount;
    use WithDisk;
    use WithFilename;
    use WithHeadings;
    use WithWriterType;

    /**
     * @var ExportActionRequest|ActionRequest
     */
    protected $request;

    /**
     * @var string
     */
    protected $resource;

    /**
     * @var Builder
     */
    protected $query;

    /**
     * @var Field[]
     */
    protected $actionFields = [];

    /**
     * @var callable|null
     */
    protected $onSuccess;

    /**
     * @var callable|null
     */
    protected $onFailure;

    /**
     * Execute the action for the given request.
     *
     * @param  \Laravel\Nova\Http\Requests\ActionRequest  $request
     *
     * @return mixed
     */
    public function handleRequest(ActionRequest $request)
    {
        $this->handleWriterType($request);
        $this->handleFilename($request);

        $this->resource = $request->resource();
        $this->request = ExportActionRequestFactory::make($request);

        $query = $this->request->toExportQuery();
        $this->handleOnly($this->request);
        $this->handleHeadings($query, $this->request);

        return $this->handle($request, $this->withQuery($query));
    }

    /**
     * @param  ActionRequest  $request
     * @param  Action  $exportable
     *
     * @return mixed
     */
    public function handle(ActionRequest $request, Action $exportable)
    {
        $response = Excel::store(
            $exportable,
            $this->getFilename(),
            $this->getDisk(),
            $this->getWriterType()
        );

        if (false === $response) {
            return \is_callable($this->onFailure)
                ? ($this->onFailure)($request, $response)
                : Action::danger(__('Resource could not be exported.'));
        }

        return \is_callable($this->onSuccess)
            ? ($this->onSuccess)($request, $response)
            : Action::message(__('Resource was successfully exported.'));
    }

    /**
     * @param  callable  $callback
     *
     * @return $this
     */
    public function onSuccess(callable $callback)
    {
        $this->onSuccess = $callback;

        return $this;
    }

    /**
     * @param  callable  $callback
     *
     * @return $this
     */
    public function onFailure(callable $callback)
    {
        $this->onFailure = $callback;

        return $this;
    }

    /**
     * @return Builder
     */
    public function query()
    {
        return $this->query;
    }

    /**
     * @param  NovaRequest  $request
     *
     * @return Field[]
     */
    public function fields(NovaRequest $request)
    {
        return $this->actionFields;
    }

    /**
     * @param  Model|mixed  $row
     *
     * @return array
     */
    public function map($row): array
    {
        $only = $this->getOnly();
        $except = $this->getExcept();

        if ($row instanceof Model) {
            // If user didn't specify a custom except array, use the hidden columns.
            // User can override this by passing an empty array ->except([])
            // When user specifies with only(), ignore if the column is hidden or not.
            if (! $this->onlyIndexFields && $except === null && (! is_array($only) || count($only) === 0)) {
                $except = $row->getHidden();
            }

            // Make all attributes visible
            $row->setHidden([]);
            $row = $this->replaceFieldValuesWhenOnResource(
                $row,
                $only
            );
        }

        if (is_array($only) && count($only) > 0) {
            $row = Arr::only($row, $only);
        }

        if (is_array($except) && count($except) > 0) {
            $row = Arr::except($row, $except);
        }

        return $row;
    }

    /**
     * @param  Builder  $query
     *
     * @return $this
     */
    protected function withQuery($query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return string
     */
    protected function getDefaultExtension(): string
    {
        return $this->getWriterType() ? strtolower($this->getWriterType()) : 'xlsx';
    }

    /**
     * @param  Model  $model
     * @param  array  $only
     *
     * @return array
     */
    protected function replaceFieldValuesWhenOnResource(Model $model, array $only = []): array
    {
        $resource = $this->resolveResource($model);
        $fields = $this->resourceFields($resource);

        $row = [];
        foreach ($fields as $field) {
            if (! $this->isExportableField($field)) {
                continue;
            }

            if (\in_array($field->attribute, $only, true)) {
                $row[$field->attribute] = $field->value;
            } elseif (\in_array($field->name, $only, true)) {
                // When no field could be found by their attribute name, it's most likely a computed field.
                $row[$field->name] = $field->value;
            }
        }

        // Add fields that were requested by ->only(), but are not registered as fields in the Nova resource.
        foreach (array_diff($only, array_keys($row)) as $attribute) {
            if ($model->{$attribute}) {
                $row[$attribute] = $model->{$attribute};
            } else {
                $row[$attribute] = '';
            }
        }

        // Fix sorting
        $row = array_merge(array_flip($only), $row);

        return $row;
    }

    /**
     * @param  \Laravel\Nova\Resource  $resource
     *
     * @return Collection
     */
    protected function resourceFields(Resource $resource)
    {
        return $this->request->resourceFields($resource);
    }

    /**
     * @param  Model  $model
     *
     * @return \Laravel\Nova\Resource
     */
    protected function resolveResource(Model $model): Resource
    {
        $resource = $this->resource;

        return new $resource($model);
    }

    /**
     * @param  Field  $field
     *
     * @return bool
     */
    protected function isExportableField(Field $field): bool
    {
        return ! $field instanceof Gravatar;
    }
}
