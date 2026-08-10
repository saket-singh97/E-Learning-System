<?php

namespace App\Support\Security;

/**
 * Strips disallowed HTML from fields listed in the model's $sanitizable
 * array before every save. Applies to any user-supplied rich-text field.
 */
trait SanitizesHtml
{
    protected static function bootSanitizesHtml(): void
    {
        static::saving(function ($model) {
            foreach ($model->sanitizable ?? [] as $field) {
                if (is_string($model->{$field})) {
                    $model->{$field} = strip_tags($model->{$field}, static::allowedHtmlTags());
                }
            }
        });
    }

    protected static function allowedHtmlTags(): string
    {
        return '<p><br><b><i><strong><em><ul><ol><li>';
    }
}
