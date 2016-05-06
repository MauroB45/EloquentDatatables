<?php
/**
 * Created by PhpStorm.
 * User: maurobernal
 * Date: 24/02/2016
 * Time: 10:54 PM
 */

namespace MauroB\EloquentDatatables\Models;


class Request extends \Illuminate\Http\Request
{

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
            $orderBy   = [];
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
     * Get orderable columns
     *
     * @return array
     */
    public function orderableColumns()
    {
        if ( ! $this->hasOrdering()) {
            return [];
        }

        $order = [];
        for ($i = 0, $c = count($this->get('order')); $i < $c; $i++) {
            $order_col = (int)$this->get('order')[$i]['column'];
            $order_dir = $this->get('order')[$i]['dir'];
            if ($this->isColumnOrderable($order_col)) {
                $order[] = ['column' => $order_col, 'direction' => $order_dir];
            }
        }

        return $order;
    }

    /**
     * Check if Datatables ordering is enabled.
     *
     * @return bool
     */
    public function hasOrdering()
    {
        return $this->get('order') && count($this->get('order')) > 0;
    }

    /**
     * Check if a column is orderable.
     *
     * @param  integer $index
     * @return bool
     */
    public function isColumnOrderable($index)
    {
        return $this->get('columns')[$index]['orderable'] == 'true';
    }

    /**
     * Get column identity from input or database.
     *
     * @param integer $i
     * @return string
     */
    public function columnName($i)
    {
        $column = $this->get('columns')[$i];

        return isset($column['name']) && $column['name'] <> '' ? $column['name'] : $column['data'];
    }

    /**
     * Check if Datatables is searchable.
     *
     * @return bool
     */
    public function isSearchable()
    {
        return $this->get('search')['value'] != '';
    }

    /**
     * Get column's search value.
     *
     * @param integer $index
     * @return string
     */
    public function columnKeyword($index)
    {
        return $this->columns[$index]['search']['value'];
    }

    /**
     * Check if a column is searchable.
     *
     * @param integer $i
     * @param bool    $column_search
     * @return bool
     */
    public function isColumnSearchable($i, $column_search = true)
    {
        $columns = $this->get('columns');
        if ($column_search) {
            return $columns[$i]['searchable'] == 'true' && $columns[$i]['search']['value'] != '';
        }

        return $columns[$i]['searchable'] == 'true';
    }


}