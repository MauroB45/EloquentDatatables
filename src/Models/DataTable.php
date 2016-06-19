<?php

namespace MauroB\EloquentDatatables\Models;

class DataTable
{

    public $draw;

    public $recordsTotal;

    public $recordsFiltered;

    public $data = [
        'select' => [ ],
        'join'   => [ ],
        'where'  => [ ],
        'having' => [ ],
        'order'  => [ ],
        'union'  => [ ],
    ];

    public $error;

}