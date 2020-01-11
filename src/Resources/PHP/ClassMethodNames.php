<?php

namespace PHPFileManipulator\Resources\PHP;

use PHPFileManipulator\Resources\BaseResource;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\ClassMethod;

class ClassMethodNames extends BaseResource
{
    public function get()
    {
        return collect(
            (new NodeFinder)->findInstanceOf($this->ast(), ClassMethod::class)
        )->map(function($method) {
            return $method->name->name;
        })->toArray();
    }    
}