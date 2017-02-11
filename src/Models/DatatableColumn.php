<?php

namespace MauroB45\EloquentDatatables\Models;

class DatatableColumn
{
    public $db;
    public $name;
    public $formatter;
    public $cast;

    /**
     * ColumnModel constructor.
     *
     * @param $db
     * @param $name
     * @param $formatter
     * @param $cast
     */
    public function __construct($db, $name, $formatter = null, $cast = null)
    {
        $this->db = $db;
        $this->name = $name;
        $this->formatter = $formatter;
        $this->cast = $cast;}
}