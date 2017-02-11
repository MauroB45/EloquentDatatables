<?php

namespace MauroB45\EloquentDatatables;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use MauroB45\EloquentDatatables\Contracts\DataTablesInterface;
use MauroB45\EloquentDatatables\Models\DataTable;
use MauroB45\EloquentDatatables\Models\DatatableColumn;
use MauroB45\EloquentDatatables\Models\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Datatables Eloquent Engine
 *
 * @package MauroB\EloquentDatatables
 */
class EloquentManager implements DataTablesInterface
{
    /**
     * @var
     */
    protected $filterCallback;
    /**
     * @var
     */
    protected $filterCallbackParameters;
    /**
     * @var DataTable
     */
    protected $response;
    /**
     * @var null
     */
    protected $rawQuery = null;
    /**
     * @var \Eloquent|Builder
     */
    protected $query;
    /**
     * @var Request
     */
    protected $request;
    /**
     * @var \Illuminate\Support\Collection|DatatableColumn[]
     */
    protected $columns;
    /**
     * @var \Illuminate\Database\Connection|\Illuminate\Database\ConnectionInterface
     */
    protected $connection;
    /**
     * @var string
     */
    protected $database;
    /**
     * @var string
     */
    protected $prefix;
    /**
     * @var
     */
    protected $orderCallback;
    /**
     * @var bool
     */
    protected $isFilterApplied = false;
    /***
     * @var \Illuminate\Support\Collection
     */
    protected $exactSearchColumns = [];
    /**
     * @var bool
     */
    protected $select;


    /**
     * EloquentManager constructor.
     *
     * @param Builder|\Eloquent $model
     * @param Request           $request
     */
    public function __construct($model, Request $request)
    {
        $this->response = new DataTable();
        $this->query = $model instanceof Builder ? $model : $model->getQuery();
        $this->request = $request;
//        $this->columns    = $this->query->columns;
        $this->connection = $model->getConnection();
        $this->prefix = $this->connection->getTablePrefix();
        $this->database = $this->connection->getDriverName();
    }


    /**
     * @param mixed $orderCallback
     *
     * @return EloquentManager
     */
    public function order($orderCallback)
    {
        $this->orderCallback = $orderCallback;

        return $this;
    }

    /**
     * Get global search keyword
     *
     * @return string
     */
    public function keyword()
    {
        return $this->get('search')[ 'value' ];
    }

    /**
     * Resolve DataTable Request and return response
     *
     * @param bool $orderFirst
     *
     * @return JsonResponse
     */
    public function get($orderFirst = true)
    {
        $this->query->addSelect($this->getColumnSelect());

        $this->response->recordsTotal = $this->count();

        if ($this->response->recordsTotal) {
            if ( ! $orderFirst) {
                $this->rawQuery = $this->query;
                $this->orderRecords();
            }
            $this->filterRecords();
            $this->response->recordsFiltered = $this->isFilterApplied ? $this->count() : $this->response->recordsTotal;
            if ($orderFirst) {
                $this->rawQuery = $this->query;
                $this->orderRecords();
            }
            $this->paging();
        }

        return $this->resolve();
    }

    /***
     * @return array
     */
    private function getColumnSelect()
    {
        return $this->columns->map(function ($column) {
            /* @var $column DatatableColumn */
            return $column->db . ' AS ' . $column->name;
        })->toArray();
    }

    /**
     * Return current $query count
     *
     * @return int
     */
    public function count()
    {
        $query = clone ($this->rawQuery == null ? $this->query : $this->rawQuery);

        if ( ! Str::contains(Str::lower($query->toSql()), ['union', 'having', 'distinct', 'order by', 'group by'])) {
            $row_count = $this->connection->getQueryGrammar()->wrap('row_count');
            $query->select($this->connection->raw("'1' as {$row_count}"));
        }

        return $this->connection->table($this->connection->raw('(' . $query->toSql() . ') count_row_table'))
                                ->setBindings($query->getBindings())->count();
    }

    /**
     * Add Order callback and Datatables request ordering to $query
     */
    public function orderRecords()
    {
        $this->query = \DB::table(\DB::raw("({$this->query->toSql()}) as sub"))
                          ->mergeBindings($this->query);

        if ($this->orderCallback) {
            call_user_func($this->orderCallback, $this->query);

            return;
        }

        foreach ($this->request->orderableColumns() as $orderable) {
            $column = $this->getColumnName($orderable[ 'column' ], true);
//            $column = $this->columns[ array_search($column, array_column($this->columns, 'name')) ][ 'name' ];
            if (isset($this->columnDef[ 'order' ][ $column ])) {
                $method = $this->columnDef[ 'order' ][ $column ][ 'method' ];
                $parameters = $this->columnDef[ 'order' ][ $column ][ 'parameters' ];
                $this->compileColumnQuery(
                    $this->query, $method, $parameters, $column, $orderable[ 'direction' ]
                );
            } else {
                $this->query->orderBy($column, $orderable[ 'direction' ]);
            }
        }
    }

    /**
     * Get column name to be use for filtering and sorting.
     *
     * @param integer $index
     * @param bool    $wantsAlias
     *
     * @return string
     */
    protected function getColumnName($index, $wantsAlias = false)
    {
        $column = $this->request->columnName($index);

        // DataTables is using make(false)
        if (is_numeric($column)) {
            $column = $this->getColumnNameByIndex($index);
        }

        if (Str::contains(Str::upper($column), ' AS ')) {
            $column = $this->extractColumnName($column, $wantsAlias);
        }

        return $column;
    }

    /**
     * Get column name from string.
     *
     * @param string $str
     * @param bool   $wantsAlias
     *
     * @return string
     */
    protected function extractColumnName($str, $wantsAlias)
    {
        $matches = explode(' as ', Str::lower($str));

        if ( ! empty($matches)) {
            if ($wantsAlias) {
                return array_pop($matches);
            } else {
                return array_shift($matches);
            }
        } elseif (strpos($str, '.')) {
            $array = explode('.', $str);

            return array_pop($array);
        }

        return $str;
    }

    /**
     * Perform necessary filters.
     *
     * @return void
     */
    protected function filterRecords()
    {
        if ($this->request->isSearchable()) {
            $this->filtering();
        } else {
            if (is_callable($this->filterCallback)) {
                call_user_func($this->filterCallback, $this->filterCallbackParameters);
            }
        }

        $this->columnSearch();
    }

    /**
     * Get eager loads keys if eloquent.
     *
     * @return array
     */
    protected function getEagerLoads()
    {

        return [];

    }

    /**
     * Perform global search.
     *
     * @return void
     */
    public function filtering()
    {


//        $eagerLoads = $this->getEagerLoads();
//
//        $this->query->where(
//            function ($query) use ($eagerLoads) {
//                $keyword = $this->setupKeyword($this->request->keyword());
//                foreach ($this->request->searchableColumnIndex() as $index) {
//                    $columnName = $this->getColumnName($index);
//
//                    if (isset($this->columnDef[ 'filter' ][ $columnName ])) {
//                        $method = Helper::getOrMethod($this->columnDef[ 'filter' ][ $columnName ][ 'method' ]);
//                        $parameters = $this->columnDef[ 'filter' ][ $columnName ][ 'parameters' ];
//                        $this->compileColumnQuery(
//                            $this->query($query),
//                            $method,
//                            $parameters,
//                            $columnName,
//                            $keyword
//                        );
//                    } else {
//                        if (count(explode('.', $columnName)) > 1) {
//                            $parts = explode('.', $columnName);
//                            $relationColumn = array_pop($parts);
//                            $relation = implode('.', $parts);
//                            if (in_array($relation, $eagerLoads)) {
//                                $this->compileRelationSearch(
//                                    $this->query($query),
//                                    $relation,
//                                    $relationColumn,
//                                    $keyword
//                                );
//                            } else {
//                                $this->compileGlobalSearch($this->query($query), $columnName, $keyword);
//                            }
//                        } else {
//                            $this->compileGlobalSearch($this->query($query), $columnName, $keyword);
//                        }
//                    }
//
//                    $this->isFilterApplied = true;
//                }
//            }
//        );
    }

    /**
     * Setup search keyword.
     *
     * @param  string $value
     *
     * @return string
     */
    public function setupKeyword($value)
    {
        $keyword = '%' . $value . '%';
        $keyword = str_replace('\\', '%', $keyword);

        return $keyword;
    }

    /**
     * Perform column search.
     *
     * @return void
     */
    public function columnSearch()
    {
        $columns = collect($this->request->get('columns'));

        $res = $columns->filter(function ($value, $key) {
            return $this->request->isColumnSearchable($key);
        })->each(function ($item, $key) {
            $alias = $this->getColumnName($key);
            $column = $this->getDatabaseColumnName($alias);

            $this->compileColumnSearch(
                $key,
                strstr($this->castColumn($column), '(') ? $this->connection->raw($column) : $column,
                collect($this->exactSearchColumns)->contains($alias),
                true);

            $this->isFilterApplied = true;
        });

    }

    /**
     * @param $columnAlias
     *
     * @return mixed
     */
    private function getDatabaseColumnName($columnAlias)
    {
        return $this->columns[ array_search($columnAlias, array_column($this->columns, 'name')) ][ 'db' ];
    }

    /**
     * Compile queries for column search.
     *
     * @param int    $i
     * @param mixed  $column
     * @param string $keyword
     * @param bool   $caseSensitive
     */
    protected function compileColumnSearch($i, $column, $exactSearch, $caseSensitive = true)
    {
        $keyword = $this->getSearchKeyword($i);
        $keyword = $caseSensitive ? $keyword : Str::lower($keyword);

        if ($exactSearch) {
            $sql = $caseSensitive ? $column . ' = ?' : 'LOWER(' . $column . ') = ?';
            $this->query->whereRaw($sql, [$keyword]);
        } else {
            $search = $this->setupKeyword($keyword);
            $sql = $caseSensitive ? $column . ' LIKE ?' : 'LOWER(' . $column . ') LIKE ?';
            $this->query->whereRaw($sql, [$search]);
        }
    }

    /**
     * Get proper keyword to use for search.
     *
     * @param int $i
     *
     * @return string
     */
    private function getSearchKeyword($i)
    {
//        if ($this->request->isRegex($i)) {
//            return $this->request->columnKeyword($i);
//        }

        return $this->request->columnKeyword($i);
    }

    /**
     * Wrap a column and cast in pgsql.
     *
     * @param  string $column
     *
     * @return string
     */
    public function castColumn($column)
    {
        $column = $this->connection->getQueryGrammar()->wrap($column);
        if ($this->database === 'pgsql') {
            $column = 'CAST(' . $column . ' as TEXT)';
        }

        return $column;
    }

    /**
     * Perform pagination
     *
     * @return void
     */
    public function paging()
    {
        $this->query->skip($this->request[ 'start' ])
                    ->take((int)$this->request[ 'length' ] > 0 ? $this->request[ 'length' ] : 10);
    }

    /***
     * @return JsonResponse
     */
    public function resolve()
    {
        $data = $this->query->get();
        $out = [];
        for ($i = 0, $ien = count($data); $i < $ien; $i++) {
            $row = [];
            for ($j = 0, $jen = count($this->columns); $j < $jen; $j++) {
                $column = $this->columns[ $j ];

                // Is there a formatter?
                if ($column->formatter) {
                    $row[ ($column->name) ] = $column->formatter($data[ $i ]->{$column[ 'name' ]}, $data[ $i ]);
                } else {
                    $row[ ($column->name) ] = $data[ $i ]->{$column->name};
                }

                // Is there a cast?
                if (isset($column->cast)) {
                    settype($row[ $column->name ], $column->cast);
                }
            }

            $out[] = $row;
        }

        $this->response->draw = intval($this->request->get("draw"));
        $this->response->data = $out;

        return new JsonResponse($this->response);
    }

    /**
     * @param $array
     *
     * @return $this
     */
    public function exactColumnSearch($array)
    {
        $this->exactSearchColumns = $array;

        return $this;
    }

    /**
     * @param $columns
     *
     * @return EloquentManager $this
     */
    public function columns($columns)
    {
        $this->columns = $this->standarizeColumns($columns);

        return $this;
    }

    /***
     * @param $columns
     *
     * @return \Illuminate\Support\Collection
     */
    private function standarizeColumns($columns)
    {
        return collect($columns)->map(function ($column, $i) {
            return $this->standatizeColum($column, $i);
        })->values();
    }

    /**
     * @param $column
     *
     * @return ColumnModel
     */
    private function standatizeColum($column, $i)
    {
        if ($column instanceof DatatableColumn) {
            return $column;
        }

        if (is_int($i)) {
            return new DatatableColumn(
                $column,
                $column
            );
        }

        return new DatatableColumn($column, $i);
    }

    /***
     * @param $fun
     *
     * @return EloquentManager $this
     */
    public function filter($fun)
    {
        $this->query = $fun($this->query);

        return $this;
    }

    /***
     * @param $search
     *
     * @return Collection
     */
    public function distinct($search)
    {
        $this->query->select($search);

        $this->filterRecords();

        return $this->query->distinct()->get();
    }

}