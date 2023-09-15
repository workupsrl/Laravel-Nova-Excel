<?php

namespace Workup\Nova\LaravelNovaExcel\Requests;

trait WithHeadingFinder
{
    /**
     * @param  string  $attribute
     * @param  string|null  $default
     * @return string|null
     */
    public function findHeading(string $attribute, string $default = null)
    {
        // In case attribute is used multiple times, grab last Field.
        $field = $this
            ->newResource()
            ->indexFields($this)
            ->where('attribute', $attribute)
            ->last();

        if (null === $field) {
            return $default;
        }

        return $field->name;
    }

    /**
     * Get a new instance of the resource being requested.
     *
     * @return \Laravel\Nova\Resource
     */
    abstract public function newResource();
}
