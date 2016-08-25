<?php

namespace MauroB45\EloquentDatatables\Contracts;

interface DatatablesServiceInterface
{
    /**
     * Make a new instance of DataTablesService
     *
     * @param $builder
     * @return mixed
     */
    static function of($builder);

    /**
     * Perform the SQL queries needed for an server-side processing requested,
     * utilizing the helper functions of this class, limit(), order() and
     * filter() among others. The returned array is ready to be encoded as JSON
     * in response to an SSP request.
     *
     * @param  array  $request Data sent to server by DataTables
     * @param  string $table   SQL table to query
     * @param  array  $columns Column information array
     * @param  array  $groupBy GroupBy information
     * @return array          Server-side processing response array
     */
    static function simple($request, $table, $columns, $groupBy = null);

    /**
     * Perform the SQL queries needed for an server-side processing requested,
     * utilizing the helper functions of this class, limit(), order() and
     * filter() among others. The returned array is ready to be encoded as JSON
     * in response to an SSP request.
     *
     * @param  array  $request  Data sent to server by DataTables
     * @param  string $table    SQL table to query
     * @param  array  $columns  Column information array
     * @param  array  $groupBy  GroupBy information
     * @param         $filterFunction $filterFunction
     * @return array          Server-side processing response array
     */
    static function complex($request, $table, $columns, $groupBy = null, $filterFunction);

    /**
     * @param       $request
     * @param       $table
     * @param array $columns
     * @param array $groupBy
     * @param null  $filterFunction
     * @return array
     */
    static function custom($request, $table, $columns, $groupBy = null, $filterFunction);

}