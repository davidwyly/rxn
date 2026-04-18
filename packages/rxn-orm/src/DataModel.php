<?php declare(strict_types=1);

namespace Rxn\Orm;

abstract class DataModel {

    protected $table = null;

    public function __construct() {
        $this->validate();
    }

    public function find() {
        $query = new Builder();
    }

    public function where() {

    }

    private function validate() {
        $this->validateTable();
    }

    private function validateTable() {
        if (is_null($this->table)) {
            throw new \Exception("");
        }
    }

}
