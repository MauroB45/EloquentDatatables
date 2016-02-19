<?php namespace MauricioBernal\DatatablesLaravel;

use Illuminate\Support\Facades\DB;

/*
 * Helper functions for building a DataTables server-side processing SQL query
 *
 */

class DatatablesService implements DatatablesServiceInterface
{
    /**
     * Create the data output array for the DataTables rows
     *
     * @param  array $columns Column information array
     * @param  array $data    Data from the SQL get
     * @return array          Formatted data in a row based format
     */
    static function data_output($columns, $data)
    {
        $out = array();

        for ($i = 0, $ien = count($data); $i < $ien; $i++) {
            $row = array();

            for ($j = 0, $jen = count($columns); $j < $jen; $j++) {
                $column = $columns[$j];

                // Is there a formatter?
                if (isset($column['formatter'])) {
                    $row[$column['dt']] = $column['formatter']($data[$i]->$column['dt'], $data[$i]);
                } else {
                    $row[$column['dt']] = $data[$i]->$column['dt'];
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
     * @param  Array   $columns                      Columns information [ 'db' = database name ,
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
     * @param  Array   $columns
     * @return Builder
     */
    static function select($db, $columns)
    {
        foreach ($columns as $column) {
            $db = $db->addSelect(
                DB::raw($column['db'] . ' as ' . $column['dt'])
            );
        }

        return $db;
    }

    /**
     * Paging
     *
     * Construct the LIMIT clause for server-side processing SQL query
     *
     * @param  Builder $db      Builder object to be built
     * @param  array   $request Data sent to server by DataTables
     * @param  array   $columns Column information array
     * @return DB SQL limit clause
     */
    static function limit($db, $request, $columns)
    {
        if (isset($request['start']) && $request['length'] != -1) {
            $db = $db->take(intval($request['length']))
                ->offset(intval($request['start']));
        }

        return $db;
    }


    /**
     * Ordering
     *
     * Construct the ORDER BY clause for server-side processing SQL query
     *
     * @param  Builder $db      Builder object to be built
     * @param  array   $request Data sent to server by DataTables
     * @param  array   $columns Column information array
     * @return string  SQL order by clause
     */
    static function order($db, $request, $columns)
    {
        if (isset($request['order']) && count($request['order'])) {
            $orderBy   = array();
            $dtColumns = self::pluck($columns, 'dt');

            for ($i = 0, $ien = count($request['order']); $i < $ien; $i++) {
                // Convert the column index into the column data property
                $columnIdx     = intval($request['order'][$i]['column']);
                $requestColumn = $request['columns'][$columnIdx];

                $columnIdx = array_search($requestColumn['data'], $dtColumns);
                $column    = $columns[$columnIdx];

                if ($requestColumn['orderable'] == 'true') {
                    $dir = $request['order'][$i]['dir'] === 'asc' ?
                        'ASC' :
                        'DESC';

                    $db = $db->orderBy($column['dt'], $dir);
                }
            }
        }

        return $db;
    }

    /**
     * Group by option
     *
     * @param  Builder $db      Builder object to be built
     * @param  array   $groupBy Array with the columns to use in a groupBy
     * @return Builder $db
     */
    static function group($db, $groupBy)
    {
        for ($i = 0, $ien = count($groupBy); $i < $ien; $i++) {
            $db = $db->groupBy($groupBy[$i]);
        }

        return $db;
    }


    /**
     * Searching / Filtering
     *
     * Construct the WHERE clause for server-side processing SQL query.
     *
     * NOTE this does not match the built-in DataTables filtering which does it
     * word by word on any field. It's possible to do here performance on large
     * databases would be very poor
     *
     * @param  array $request  Data sent to server by DataTables
     * @param  array $columns  Column information array
     * @param  array $bindings Array of values for PDO bindings, used in the
     *                         sql_exec() function
     * @return string SQL where clause
     */
    static function filter($db, $request, $columns, &$bindings)
    {
        $dtColumns = self::pluck($columns, 'dt');

        // General column search
        if (isset($request['search']) && $request['search']['value'] != '') {
            $str = $request['search']['value'];

            $db = $db->where(function ($db) use ($request, $dtColumns, $columns, $str) {
                for ($i = 0, $ien = count($request['columns']); $i < $ien; $i++) {
                    $requestColumn = $request['columns'][$i];
                    $columnIdx     = array_search($requestColumn['data'], $dtColumns);
                    $column        = $columns[$columnIdx];

                    if ($requestColumn['searchable'] == 'true') {
                        $db = $db->orWhereRaw($column['db'] . ' like ' . "'%" . $str . "%'");
                    }
                }

                return $db;
            });
        }

        // Individual column filtering
        $db = $db->where(function ($db) use ($request, $dtColumns, $columns) {
            for ($i = 0, $ien = count($request['columns']); $i < $ien; $i++) {
                $requestColumn = $request['columns'][$i];
                $columnIdx     = array_search($requestColumn['data'], $dtColumns);
                $column        = $columns[$columnIdx];

                $str = $requestColumn['search']['value'];

                if ($requestColumn['searchable'] == 'true' && $str != '') {
                    $db = $db->whereRaw($column['db'] . ' like ' . "'%" . $str . "%'");
                }
            }

            return $db;
        });

        return $db;
    }

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
    static function simple($request, $table, $columns, $groupBy = null)
    {
        // Build the SQL query string from the request
        $sub = self::db($table, $columns);
        $sub = self::group($sub, $groupBy);
        // Total data set length
        $recordsTotal = sizeof($sub->get());
        $sub          = self::filter($sub, $request, $columns, $bindings);
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

        return array(
            "draw"            => intval($request['draw']),
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
        );
    }

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
        $sub          = self::filter($sub, $request, $columns, $bindings);
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

        return array(
            "draw"            => intval($request['draw']),
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
        );
    }

    /**
     * @param       $request
     * @param       $table
     * @param array $columns
     * @param array $groupBy
     * @param null  $filterFunction
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
        $sub          = self::filter($sub, $request, $columns, $bindings);
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

        return array(
            "draw"            => intval($request['draw']),
            "recordsTotal"    => intval($recordsTotal),
            "recordsFiltered" => intval($recordsFiltered),
            "data"            => self::data_output($columns, $data)
        );
    }

    /**
     * Pull a particular property from each assoc. array in a numeric array,
     * returning and array of the property values from each item.
     *
     * @param  array  $a    Array to get data from
     * @param  string $prop Property to read
     * @return array        Array of property values
     */
    static function pluck($a, $prop)
    {
        $out = array();

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $out[] = $a[$i][$prop];
        }

        return $out;
    }


    /**
     * Return a string from an array or a string
     *
     * @param  array|string $a    Array to join
     * @param  string       $join Glue for the concatenation
     * @return string Joined string
     */
    static function _flatten($a, $join = ' AND ')
    {
        if ( ! $a) {
            return '';
        } else if ($a && is_array($a)) {
            return implode($join, $a);
        }

        return $a;
    }
}
