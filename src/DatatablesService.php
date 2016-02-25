<?php

namespace MauroB\EloquentDatatables;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MauroB\EloquentDatatables\Contracts\DatatablesServiceInterface;

/**
 * Class DatatablesService
 *  Helper class for jQuery DataTables
 *
 *
 * @package MauroB\EloquentDatatables
 */
class DatatablesService implements DatatablesServiceInterface
{
    protected $datatable;

    protected $request;

    /**
     * DatatablesService constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request->request->count() ? $request : Request::capture();
    }

    /**
     * Return Instance
     *
     * @param $builder
     * @return \Yajra\Datatables\Engines\CollectionEngine|\Yajra\Datatables\Engines\EloquentEngine|\Yajra\Datatables\Engines\QueryBuilderEngine
     */
    static function of($builder)
    {
        $datatablesService = app('datatables');

        $datatablesService->builder = $builder;
        if ($builder instanceof QueryBuilder) {
            $ins = $datatablesService->usingQueryBuilder($builder);
        } else {
            $ins = $builder instanceof Collection ? $datatablesService->usingCollection($builder) : $datatablesService->usingEloquent($builder);
        }
        return $ins;
    }

    /**
     * Resolve DataTable Request and return response
     */
    public function get()
    {

    }

    /**
     * Create the data output array for the DataTables rows
     *
     * @param  array $columns Column information array
     * @param  array $data Data from the SQL get
     * @return array          Formatted data in a row based format
     */
    static function data_output($columns, $data)
    {
        $out = [];

        for ($i = 0, $ien = count($data); $i < $ien; $i++) {
            $row = [];

            for ($j = 0, $jen = count($columns); $j < $jen; $j++) {
                $column = $columns[ $j ];

                // Is there a formatter?
                if (isset($column[ 'formatter' ])) {
                    $row[ $column[ 'dt' ] ] = $column[ 'formatter' ]($data[ $i ]->$column[ 'dt' ], $data[ $i ]);
                } else {
                    $row[ $column[ 'dt' ] ] = $data[ $i ]->$column[ 'dt' ];
                }
            }

            $out[] = $row;
        }

        return $out;
    }


    /**
     * Database connection
     *
     * Obtain an PHP QueryBuilder from a connection details array
     *
     * @param  Builder $db
     * @param  Array $columns Columns information [ 'db' = database name ,
     *                                               'dt' = column name]
     * @return resource QueryBuilder
     */
    static function db($table, $columns)
    {
        $db = DB::table($table);

        return self::select($db, $columns);
    }

    /**
     * Build the select from $columns
     *
     * @param  Builder $db
     * @param  array $columns
     * @return Builder
     */
    static function select($db, $columns)
    {
        foreach ($columns as $column) {
            $db = $db->addSelect(
                DB::raw($column[ 'db' ] . ' as ' . $column[ 'dt' ])
            );
        }

        return $db;
    }

    /**
     * Perform the SQL queries needed for an server-side processing requested,
     * utilizing the helper functions of this class, limit(), order() and
     * filter() among others. The returned array is ready to be encoded as JSON
     * in response to an SSP request.
     *
     * @param  array $request Data sent to server by DataTables
     * @param  string $table SQL table to query
     * @param  array $columns Column information array
     * @param  array $groupBy GroupBy information
     * @return array          Server-side processing response array
     */
    static function simple($request, $table, $columns, $groupBy = null)
    {
        // Build the SQL query string from the request
        $sub = self::db($table, $columns);
        $sub = self::group($sub, $groupBy);
        // Total data set length
        $recordsTotal = sizeof($sub->get());
        $sub = self::filter($sub, $request, $columns, $bindings);
        // Data set length after filtering
        $recordsFiltered = sizeof($sub->get());

        $db = DB::table(DB::raw("({$sub->toSql()}) as sub"));
        $db = self::order($db, $request, $columns);
        $db = self::limit($db, $request, $columns);

        // Data
        $data = $db->get();

        /*
         * Output
         */

        return [
            "draw"            => intval($request[ 'draw' ]),
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
        ];
    }

    /**
     * Perform the SQL queries needed for an server-side processing requested,
     * utilizing the helper functions of this class, limit(), order() and
     * filter() among others. The returned array is ready to be encoded as JSON
     * in response to an SSP request.
     *
     * @param  array $request Data sent to server by DataTables
     * @param  string $table SQL table to query
     * @param  array $columns Column information array
     * @param  array $groupBy GroupBy information
     * @return array          Server-side processing response array
     */
    static function complex($request, $table, $columns, $groupBy = null, $filterFunction)
    {
        // Build the SQL query string from the request
        $sub = self::db($table, $columns);
        $sub = self::group($sub, $groupBy);
        $sub = $filterFunction($sub);
        // Total data set length
        $recordsTotal = sizeof($sub->get());
        $sub = self::filter($sub, $request, $columns, $bindings);
        // Data set length after filtering
        $recordsFiltered = sizeof($sub->get());

        $db = DB::table(DB::raw("({$sub->toSql()}) as sub"));
        $db = self::order($db, $request, $columns);
        $db = self::limit($db, $request, $columns);

        // Data
        $data = $db->get();

        /*
         * Output
         */

        return [
            "draw"            => intval($request[ 'draw' ]),
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
        ];
    }

    /**
     * @param       $request
     * @param       $table
     * @param array $columns
     * @param array $groupBy
     * @param null $filterFunction
     * @return array
     */
    static function custom($request, $table, $columns, $groupBy = null, $filterFunction = null)
    {
        // Build the SQL query string from the request
        $sub = self::select($table, $columns);
        $sub = self::group($sub, $groupBy);
        $sub = $filterFunction !== null ? $filterFunction($sub) : $sub;

        // Total data set length
        $recordsTotal = sizeof($sub->get());
        $sub = self::filter($sub, $request, $columns, $bindings);
        // Data set length after filtering
        $recordsFiltered = sizeof($sub->get());

        $db = DB::table(DB::raw("({$sub->toSql()}) as sub"));
        $db = self::order($db, $request, $columns);
        $db = self::limit($db, $request, $columns);

        // Data
        $data = $db->get();

        /*
         * Output
         */

        return [
            "draw"            => intval($request[ 'draw' ]),
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
        ];
    }

    /**
     * Pull a particular property from each assoc. array in a numeric array,
     * returning and array of the property values from each item.
     *
     * @param  array $a Array to get data from
     * @param  string $prop Property to read
     * @return array        Array of property values
     */
    static function pluck($a, $prop)
    {
        $out = [];

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $out[] = $a[ $i ][ $prop ];
        }

        return $out;
    }

}
