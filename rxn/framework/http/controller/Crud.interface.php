<?php

namespace Rxn\Framework\Http\Controller;

interface Crud
{
    public function create();

    public function read();

    public function update();

    public function delete();
}
