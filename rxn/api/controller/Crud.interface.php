<?php

namespace Rxn\Api\Controller;

use \Rxn\Container;
use \Rxn\Api\Request;
use \Rxn\Data\Database;

interface Crud
{
    public function create();

    public function read();

    public function update();

    public function delete();
}
