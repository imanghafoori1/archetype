<?php

namespace PHPFileManipulator\Factories;

use PHPFileManipulator\PHPFile;

class PHPFileFactory
{
    public function __call($method, $args)
    {
        return (new PHPFile)->$method(...$args);
    }    
}