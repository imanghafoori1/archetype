<?php

namespace PHPFileManipulator\Traits;

use PHPFileManipulator\Support\QueryBuilder;

trait HasQueryBuilder
{
    public function all()
    {
        return collect();
    }

    public function in($args)
    {
        return collect();
    }

    public static function where($args)
    {
        // resource query
        // filename query
        // function query
    }    
}