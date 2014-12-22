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
 * @package Graph
 * @subpackage Vertex
 */

namespace ReckiCT\Graph\Vertex;

use ReckiCT\Graph\Traits;
use ReckiCT\Graph\Assignment;
use ReckiCT\Graph\Vertex;
use ReckiCT\Graph\Variable;

class PropertyFetch extends Vertex implements Assignment
{

    use Traits\Assignment;

    protected $object;
    protected $name;

    public function __construct(Variable $object, $name, Variable $result)
    {
        $this->object = $object;
        $this->name = $name;
        $this->setResult($result);
    }

    public function __toString()
    {
        return $this->result . ' = ' . $this->object . '->' . $this->name;
    }

    public function getPropertyName() {
        return $this->name;
    }

    public function getVariables()
    {
        return [$this->object, $this->result];
    }

    public function replaceVariable(Variable $from, Variable $to)
    {
        if ($this->object === $from) {
            $this->object = $to;
        }
    }

}
