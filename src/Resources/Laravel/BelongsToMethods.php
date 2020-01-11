<?php

namespace PHPFileManipulator\Resources\Laravel;

use PHPFileManipulator\Resources\BaseResource;
use PHPFileManipulator\Support\Snippet;
use Illuminate\Support\Str;

class BelongsToMethods extends BaseResource
{
    public function add($targets)
    {
        $this->file->addClassMethods(
            collect($targets)->map(function($target) {
                return Snippet::___BELONGS_TO_METHOD___([
                    '___BELONGS_TO_METHOD___' => Str::belongsToMethodName($target),
                    '___TARGET_CLASS___' => collect(explode('\\', $target))->last(),
                    '___TARGET_IN_DOC_BLOCK___' => Str::belongsToDocBlockName($target)
                ]);
            })->toArray()
        );

        return $this->file;
    }
}