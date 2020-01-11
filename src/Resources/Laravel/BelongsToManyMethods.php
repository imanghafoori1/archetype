<?php

namespace PHPFileManipulator\Resources\Laravel;

use PHPFileManipulator\Resources\BaseResource;
use PHPFileManipulator\Support\Snippet;
use Illuminate\Support\Str;

class BelongsToManyMethods extends BaseResource
{
    public function add($targets)
    {
        $this->file->addClassMethods(
            collect($targets)->map(function($target) {
                return Snippet::___BELONGS_TO_MANY_METHOD___([
                    '___BELONGS_TO_MANY_METHOD___' => Str::belongsToManyMethodName($target),
                    '___TARGET_CLASS___' => collect(explode('\\', $target))->last(),
                    '___TARGET_IN_DOC_BLOCK___' => Str::belongsToManyDocBlockName($target)
                ]);
            })->toArray()
        );

        return $this->file;
    }
}