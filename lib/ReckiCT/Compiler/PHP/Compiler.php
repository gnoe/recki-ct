<?php
/**
 * Copyright 2014 Google Inc. All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @copyright 2014 Google Inc. All rights reserved
 * @license http://www.apache.org/licenses/LICENSE-2.0.txt Apache-2.0
 * @package Compiler
 * @subpackage PHP
 */

namespace ReckiCT\Compiler\PHP;

use ReckiCT\Jit;
use ReckiCT\Compiler\Compiler as BaseCompiler;

class Compiler extends BaseCompiler
{

    protected static $binaryOps = [
        '+',
        '-',
        '*',
        '/',
        '%',
        '==',
        '===',
        '!=',
        '!==',
        '<',
        '<=',
        '>',
        '>=',
        '&',
        '|',
        '^',
        '<<',
        '>>',
    ];

    protected $compileCache = [];

    public function convertToClass(\StdClass $class) {
        $extends = $class->extends ? ' extends ' . $class->extends : '';
        $implements = empty($class->implements) ? '' : ' implements ' . implode(', ', $class->implements);
        $props = '';
        $methods = '';
        foreach ($class->properties as $prop) {
            $props .= $prop[2];
            if ($prop[3] == "static") {
                $props .= " static";
            }
            $props .= ' $' . $prop[1];
            if ($prop[5]) {
                $props .= " = ";
                if ($prop[4] == "string") {
                    $props .= "'" . addcslashes(base64_decode($prop[5])) . "'";
                } else {
                    $props .= $prop[5];
                }
            }
            $props .= ";\n";
        }
        foreach ($class->methods as $method) {
            $modifiers = $method[0][1];
            if ($method[0][2] == "static") {
                $modifiers .= " static";
            }
            if ($method[0][3] == "final") {
                $modifiers .= " final";
            }
            $method[0] = ["function", $method[0][4], $method[0][5]];
            $code = $this->convertToCallable($method);
            $methods .= $modifiers . ' ' .  $code . "\n";
        }


        return 'class ' . $class->name . $extends . $implements . " {\n" . $props . $methods . "\n}";
    }

    public function convertToCallable(array $instructions)
    {
        $func = $instructions[0];
        if (isset($this->compileCache[$func[1]])) {
            return $this->compileCache[$func[1]];
        }
        $argNames = [];
        $i = 1;
        
        $ctx = (object) [
            'compiler' => $this,
            'function' => '',
            'instructions' => $instructions,
            'i' => $i,
            'scope' => ['$this' => '$this'],
            'argNames' => $argNames,
            'labels' => [],
        ];

        $args = [];
        while ('begin' != $instructions[$ctx->i][0]) {
            $args[$instructions[$ctx->i][1]] = '$var' . (count($ctx->scope) + 1);
            $ctx->scope[$instructions[$ctx->i][1]] = '$var' . (count($ctx->scope) + 1);
            $ctx->i++;
        }
        $argString = implode(', ', $args);

        $count = count($ctx->instructions);
        while (++$ctx->i < $count) {
            $this->compileInstruction($ctx->instructions[$ctx->i], $ctx);
        }

        return 'function ' . $func[1] . '(' . $argString . ") {\n" . $ctx->function . "}";
    }

    public function compileInstruction(array $instruction, \StdClass $ctx)
    {
        switch ($instruction[0]) {
            case 'free':
                return;
            case 'const':
                $value = null;
                switch ($instruction[2]) {
                    case 'bool':
                    case 'long':
                    case 'numeric':
                        $value = (int) $instruction[3];
                        break;
                    case 'double':
                        $value = (double) $instruction[3];
                        break;
                    case 'string':
                        $value = "'" . addslashes(base64_decode($instruction[3])) . "'";
                        break;
                    default:
                        throw new \RuntimeException('Invalid constant type: ' . $instruction[2]);
                }
                $ctx->scope[$instruction[1]] = $value;

                return;
            case 'var':
                $ctx->scope[$instruction[1]] = '$var' . (count($ctx->scope) + 1);

                return;
            case 'assign':
                $ctx->function .= $ctx->scope[$instruction[2]] . ' = ' . $ctx->scope[$instruction[1]] . ";\n";

                return;
            case 'propertyfetch':
                $ctx->function .= $ctx->scope[$instruction[3]] . ' = ' . $ctx->scope[$instruction[2]] . '->' . $instruction[1] . ";\n";
                
                return;
            case 'label':
                if (!isset($ctx->labels[$instruction[1]])) {
                    $ctx->labels[$instruction[1]] = 'label_' . (count($ctx->labels) + 1);
                }
                $ctx->function .= $ctx->labels[$instruction[1]] . ":\n";

                return;
            case 'jump':
                if (!isset($ctx->labels[$instruction[1]])) {
                    $ctx->labels[$instruction[1]] = 'label_' . (count($ctx->labels) + 1);
                }
                $ctx->function .= "goto " . $ctx->labels[$instruction[1]] . ";\n";

                return;
            case 'jumpz':
                $var = $ctx->scope[$instruction[1]];

                if (!isset($ctx->labels[$instruction[2]])) {
                    $ctx->labels[$instruction[2]] = 'label_' . (count($ctx->labels) + 1);
                }
                $ctx->function .= "if (!" . $var . ") { goto " . $ctx->labels[$instruction[2]] . "; }\n";

                return;
            case 'return':
                if (isset($instruction[1])) {
                    $ctx->function .= "return " . $ctx->scope[$instruction[1]] . ";\n";
                } else {
                    $ctx->function .= "return;\n";
                }

                return;
            case '!':
                $ctx->function .= $ctx->scope[$instruction[2]] . ' = !' . $ctx->scope[$instruction[1]] . ";\n";

                return;
            case '~':
                $ctx->function .= $ctx->scope[$instruction[2]] . ' = ~' . $ctx->scope[$instruction[1]] . ";\n";

                return;
            case 'recurse':
                throw new \RuntimeException('Recursion is not supported yet');
            case 'functioncall':
                $name = $instruction[1];
                $args = [];
                for ($i = 2, $n = count($instruction); $i < $n - 1; $i++) {
                    $args[] = $ctx->scope[$instruction[$i]];
                }
                $ctx->function .= $ctx->instruction[$n - 1] . ' = ' . $name . '(' . implode(', ', $args) . ");";

                return;

        }
        if (in_array($instruction[0], self::$binaryOps)) {
            $ctx->function .= $ctx->scope[$instruction[3]] . ' = '
                . $ctx->scope[$instruction[1]] . ' ' . $instruction[0] . ' '
                . $ctx->scope[$instruction[2]] . ";\n";
            return;
        }
        throw new \RuntimeException('Invalid op found! ' . $instruction[0]);
    }

}
