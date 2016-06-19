<?php

namespace MauroB\EloquentDatatables\Contracts;

interface DatatablesServiceInterface
{
    /**
     * Make a new instance of DataTablesService
     *
     * @param $builder
     * @return mixed
     */
    static function of($builder);

}