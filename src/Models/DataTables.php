<?php

namespace MauroB\EloquentDatatables\Model;

class DataTables
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