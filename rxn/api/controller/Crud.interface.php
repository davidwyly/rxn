<?php

namespace Rxn\Api\Controller;

use \Rxn\Container;
use \Rxn\Api\Request;
use \Rxn\Data\Database;

interface Crud
{
    public function create_vx(Request $request, Container $container, Database $database);

    public function read_vx(Request $request, Container $container, Database $database);

    public function update_vx(Request $request, Container $container, Database $database);

    public function delete_vx(Request $request, Container $container, Database $database);
}