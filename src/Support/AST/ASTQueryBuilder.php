<?php

namespace Archetype\Support\AST;

use InvalidArgumentException;
use LaravelFile;
use PhpParser\NodeFinder;
use Archetype\Support\AST\ShallowNodeFinder;
use Archetype\Traits\HasOperators;
use Archetype\Traits\PHPParserClassMap;
use Archetype\Support\AST\Killable;
use Archetype\Support\AST\Visitors\NodeReplacer;
use Archetype\Support\AST\Visitors\NodeRemover;
use Archetype\Support\AST\Visitors\HashInserter;
use Archetype\Support\AST\Visitors\StmtInserter;
use Archetype\Support\AST\Visitors\NodePropertyReplacer;
use PhpParser\Node\Stmt\Use_;
use Exception;
use PhpParser\ConstExprEvaluator;

class ASTQueryBuilder
{
    use HasOperators;
    
    use PHPParserClassMap;

    public $allowDeepQueries = true;

    public $currentDepth = 0;

    public $initialAST;

    public $resultingAST;

    public $file;

    public function __construct($ast)
    {
        $this->initialAST = $ast;
        $this->resultingAST = $ast;

        $this->tree = [
            [new Survivor(
                HashInserter::on($ast)
            )],
        ];
    }

    public static function fromFile($file)
    {
        $instance = new static($file->ast());
        $instance->file = $file;
        return $instance;
    }

    public function __call($method, $args)
    {
        // Can we find a corresponding PHPParser class to enter?
        $class = $this->classMap($method);
        if($class) return $this->traverseIntoClass($class);        

        throw new Exception("Could not find a method $method in the ASTQueryBuilder!");
    }

    public function __get($name)
    {
        // Can we find a corresponding PHPParser property to enter?
        $property = $this->propertyMap($name);
        if($property) return $this->traverseIntoProperty($property);        

        throw new Exception("Could not find a property $property in the ASTQueryBuilder!");
    }    

    public function traverseIntoClass($expectedClass, $finderMethod = 'findInstanceOf')
    {
        return $this->next(function($queryNode) use($expectedClass, $finderMethod) {
            // Search the abstract syntax tree
            $results = $this->nodeFinder()->$finderMethod($queryNode->result, $expectedClass);
            // Wrap matches in Survivor object
            return collect($results)->map(function($result) use($queryNode) {
                return Survivor::fromParent($queryNode)->withResult($result);
            })->toArray();
        });     
    }

    public function traverseIntoProperty($property)
    {
        return $this->next(function($queryNode) use($property) {
            if(!isset($queryNode->result->$property)) return new Killable;
            
            $value = $queryNode->result->$property;
            
            if(is_array($value)) {
                return collect($value)->map(function($item) use($value, $queryNode) {
                    return Survivor::fromParent($queryNode)->withResult($item);
                })->toArray();
            }

            return Survivor::fromParent($queryNode)->withResult($value);
        });
    }

    public function shallow()
    {
        $this->allowDeepQueries = false;
        return $this;
    }

    public function deep()
    {
        $this->allowDeepQueries = true;
        return $this;
    }

    public function remember($key, $callback)
    {
        $this->currentNodes()->each(function($queryNode) use($key, $callback) {
            
            if($queryNode instanceof Killable) return;
            
            $queryNode->memory[$key] = $callback($queryNode->result);
        });

        return $this;
    }

    public function where($arg1, $arg2 = null)
    {
        return is_callable($arg1) ? $this->whereCallback($arg1) : $this->wherePath($arg1, $arg2);
    }

    protected function next($callback)
    {
        $next = $this->currentNodes()->map($callback)->flatten()->toArray();

        array_push($this->tree, $next);

        $this->currentDepth++;

        return $this;
    }

    protected function nodeFinder()
    {
        return $this->allowDeepQueries ? new NodeFinder : new ShallowNodeFinder;
    }

    protected function wherePath($path, $expected)
    {
        return $this->next(function($queryNode) use($path, $expected) {
            $steps = collect(explode('->', $path));

            $result = $steps->reduce(function($result, $step) {
                return is_object($result) && isset($result->$step) ? $result->$step : new Killable;
            }, $queryNode->result);

            return $result == $expected ? $queryNode : new Killable;
        });
    }

    protected function whereCallback($callback)
    {
        return $this->next(function($queryNode) use($callback) {
            $query = new static(
                [(clone $queryNode)->result]
            );            
            return $callback($query) ? $queryNode : new Killable;
        });
    }  

    // public function whereChainingOn($name)
    // {
    //     return $this->next(function($queryNode) use($name) {
    //         $current = $queryNode->result;
    //         do {
    //             $current = $current->var ?? false;
    //         } while($current && '\\' . get_class($current) == $this->classMap('methodCall'));

    //         return $current->name == $name ? $queryNode : new Killable;
    //     });
    // }

    // public function flattenChain()
    // {
    //     $flattened = $this->currentNodes()->map(function($queryNode) {
    //         $results = collect();
    //         $current = $queryNode->result[0];

    //         do {
    //             $results->push($current);
    //             $current = $current->var ?? false;
                
    //         } while($current && '\\' . get_class($current) == $this->classMap('methodCall'));

    //         return $results->reverse();
            
    //     })->flatten();

    //     return $flattened->flatMap(function($methodCall) {
    //         $var = $methodCall->var->name;
    //         $name = $methodCall->name;
    //         $args = $methodCall->args;

    //         return [
    //             $methodCall->name->name => collect($args)->map(function($arg) {
    //                 return $arg->value->value;
    //             })->values()->toArray()
    //         ];
    //     })->toArray();
    // }

    public function recall()
    {
        return collect(end($this->tree))->filter(function($item) {
            return $item->result;
        })->map(function($item) {
            return (object) $item->memory;
        });
    }

    public function get()
    {
        return collect(end($this->tree))->pluck('result')->flatten();
    }

    public function first()
    {
        return $this->get()->first();
    }    

    public function getEvaluated()
    {
        return $this->get()->map(function($item) {
            return (new ConstExprEvaluator())->evaluateSilently($item);
        });
    }

    public function remove()
    {
        $this->currentNodes()->each(function($node) {
            
            if(!isset($node->result->__object_hash)) return;
            
            $this->resultingAST = NodeRemover::remove(
                $node->result->__object_hash,
                $this->resultingAST
            );
        });
        
        return $this;
    }    

    public function replaceProperty($key, $value)
    {
        $this->currentNodes()->each(function($node) use($key, $value) {
            if(!isset($node->result->__object_hash)) return;

            $this->resultingAST = NodePropertyReplacer::replace(
                $node->result->__object_hash,
                $key,
                $value,
                $this->resultingAST
            );
        });

        return $this;        
    }

    public function replace($arg1)
    {
        return is_callable($arg1) ? $this->replaceWithCallback($arg1) : $this->replaceWithNode($arg1);
    }

    protected function replaceWithCallback($callback)
    {
        $this->currentNodes()->each(function($node) use($callback) {
            if(!isset($node->result->__object_hash)) return;

            $this->resultingAST = NodeReplacer::replace(
                $node->result->__object_hash,
                $callback($node->result),
                $this->resultingAST
            );
        });

        return $this;        
    }

    protected function replaceWithNode($newNode)
    {
        $this->currentNodes()->each(function($node) use($newNode) {
            if(!isset($node->result->__object_hash)) return;

            $this->resultingAST = NodeReplacer::replace(
                $node->result->__object_hash,
                $newNode,
                $this->resultingAST
            );
        });

        return $this;
    }

    public function insertStmts($newNodes)
    {
        collect($newNodes)->each(function($newNode) {
            $this->insertStmt($newNode);
        });

        return $this;
    }

    public function insertStmt($newNode)
    {
        $this->currentNodes()->each(function($node) use($newNode) {

            $target = $node->result;

            // Assume insertion targets namespace stmts (if present at index 0)
            if(is_array($target) && !empty($target) && get_class($target[0]) == 'PhpParser\\Node\\Stmt\Namespace_') {
                $target = $target[0];
            }

            $this->resultingAST = StmtInserter::insertStmt(
                $target->__object_hash ?? null,
                $newNode,
                $this->resultingAST
            );
        });

        return $this;
    }   

    public function dd()
    {
        dd($this->get());
    }
    
    public function commit()
    {
        $this->file->ast(
            $this->resultingAST
        );

        return $this;
    }

    public function end()
    {
        return $this->file;
    }    

    protected function currentNodes()
    {
        return collect($this->tree[$this->currentDepth]);
    }
}