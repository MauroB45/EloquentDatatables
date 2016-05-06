<?php

namespace MauroB\EloquentDatatables\Models;

class DataTable
{

    public $draw;

    public $totalRecords;

    public $filteredRecords;

    public $data = [
        'select' => [],
        'join'   => [],
        'where'  => [],
        'having' => [],
        'order'  => [],
        'union'  => [],
    ];

    public $error;

}