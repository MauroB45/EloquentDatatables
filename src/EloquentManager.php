<?php
/**
 * Created by PhpStorm.
 * User: Mauricio.Ruiz
 * Date: 25/02/2016
 * Time: 8:30 PM
 */

namespace MauroB\EloquentDatatables;


use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use MauroB\EloquentDatatables\Contracts\DataTablesInterface;
use MauroB\EloquentDatatables\Models\DataTable;
use MauroB\EloquentDatatables\Models\Request;
use MauroB\EloquentDatatables\Processors\DataProcessor;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Datatables Eloquent Engine
 *
 * @package MauroB\EloquentDatatables
 */
class EloquentManager implements DataTablesInterface
{
    protected $filterCallback;
    protected $filterCallbackParameters;
    protected $response;
    protected $rawQuery        = null;
    protected $query;
    protected $request;
    protected $columns;
    protected $connection;
    protected $database;
    protected $prefix;
    protected $orderCallback;
    protected $isFilterApplied = false;


    /**
     * EloquentManager constructor.
     *
     * @param Builder|\Eloquent $model
     * @param Request           $request
     */
    public function __construct($model, Request $request)
    {
        $this->response   = new DataTable();
        $this->query      = $model instanceof Builder ? $model : $model->getQuery();
        $this->request    = $request;
        $this->columns    = $this->query->columns;
        $this->connection = $model->getConnection();
        $this->prefix     = $this->connection->getTablePrefix();
        $this->database   = $this->connection->getDriverName();
    }


    /**
     * @param mixed $orderCallback
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
        return $this->get('search')['value'];
    }

    /**
     * Resolve DataTable Request and return response
     *
     * @param bool $orderFirst
     * @return JsonResponse
     */
    public function get($orderFirst = true)
    {
        $this->response->totalRecords = $this->count();

        if ($this->response->totalRecords) {
            if ( ! $orderFirst) {
                $this->rawQuery = $this->query;
                $this->orderRecords();
            }
            $this->filterRecords();
            if ($orderFirst) {
                $this->rawQuery = $this->query;
                $this->orderRecords();
            }
            $this->paging();
        }

        return $this->resolve();
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
        if ($this->orderCallback) {
            call_user_func($this->orderCallback, $this->query);

            return;
        }

        foreach ($this->request->orderableColumns() as $orderable) {
            $column = $this->getColumnName($orderable['column'], true);
            if (isset($this->columnDef['order'][$column])) {
                $method     = $this->columnDef['order'][$column]['method'];
                $parameters = $this->columnDef['order'][$column]['parameters'];
                $this->compileColumnQuery(
                    $this->query, $method, $parameters, $column, $orderable['direction']
                );
            } else {
                $this->query->orderBy($column, $orderable['direction']);
            }
        }
    }

    /**
     * Get column name to be use for filtering and sorting.
     *
     * @param integer $index
     * @param bool    $wantsAlias
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
    public function filterRecords()
    {
        if ($this->request->isSearchable()) {
            $this->filtering();
        } else {
            if (is_callable($this->filterCallback)) {
                call_user_func($this->filterCallback, $this->filterCallbackParameters);
            }
        }

        $this->columnSearch();
        $this->response->filteredRecords = $this->isFilterApplied ? $this->count() : $this->response->filteredRecords;
    }

    /**
     * Perform global search.
     *
     * @return void
     */
    public function filtering()
    {
        $eagerLoads = $this->getEagerLoads();

        $this->query->where(
            function ($query) use ($eagerLoads) {
                $keyword = $this->setupKeyword($this->request->keyword());
                foreach ($this->request->searchableColumnIndex() as $index) {
                    $columnName = $this->getColumnName($index);

                    if (isset($this->columnDef['filter'][$columnName])) {
                        $method     = Helper::getOrMethod($this->columnDef['filter'][$columnName]['method']);
                        $parameters = $this->columnDef['filter'][$columnName]['parameters'];
                        $this->compileColumnQuery(
                            $this->query($query),
                            $method,
                            $parameters,
                            $columnName,
                            $keyword
                        );
                    } else {
                        if (count(explode('.', $columnName)) > 1) {
                            $parts          = explode('.', $columnName);
                            $relationColumn = array_pop($parts);
                            $relation       = implode('.', $parts);
                            if (in_array($relation, $eagerLoads)) {
                                $this->compileRelationSearch(
                                    $this->query($query),
                                    $relation,
                                    $relationColumn,
                                    $keyword
                                );
                            } else {
                                $this->compileGlobalSearch($this->query($query), $columnName, $keyword);
                            }
                        } else {
                            $this->compileGlobalSearch($this->query($query), $columnName, $keyword);
                        }
                    }

                    $this->isFilterApplied = true;
                }
            }
        );
    }

    /**
     * Setup search keyword.
     *
     * @param  string $value
     * @return string
     */
    public function setupKeyword($value)
    {
        $keyword = '%' . $value . '%';
//        if ($this->isWildcard()) {
//            $keyword = $this->wildcardLikeString($value);
//        }
        // remove escaping slash added on js script request
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
        $columns = $this->request->get('columns');
        for ($i = 0, $c = count($columns); $i < $c; $i++) {
            if ($this->request->isColumnSearchable($i)) {
                $column  = $this->getColumnName($i);
                $keyword = $this->getSearchKeyword($i);

                if (isset($this->columnDef['filter'][$column])) {
                    $method     = $this->columnDef['filter'][$column]['method'];
                    $parameters = $this->columnDef['filter'][$column]['parameters'];
                    $this->compileColumnQuery($this->query, $method, $parameters, $column, $keyword);
                } else {
                    $column = $this->castColumn($column);
//                    if ($this->isCaseInsensitive()) {
//                        $this->compileColumnSearch($i, $column, $keyword, false);
//                    } else {
                    $col = strstr($column, '(') ? $this->connection->raw($column) : $column;
                    $this->compileColumnSearch($i, $col, $keyword, true);
//                    }
                }

                $this->isFilterApplied = true;
            }
        }
    }

    /**
     * Get proper keyword to use for search.
     *
     * @param int $i
     * @return string
     */
    private function getSearchKeyword($i)
    {
//        if ($this->request->isRegex($i)) {
//            return $this->request->columnKeyword($i);
//        }

        return $this->setupKeyword($this->request->columnKeyword($i));
    }

    /**
     * Wrap a column and cast in pgsql.
     *
     * @param  string $column
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
     * Compile queries for column search.
     *
     * @param int    $i
     * @param mixed  $column
     * @param string $keyword
     * @param bool   $caseSensitive
     */
    protected function compileColumnSearch($i, $column, $keyword, $caseSensitive = true)
    {
//        if ($this->request->isRegex($i)) {
//            $this->regexColumnSearch($column, $keyword, $caseSensitive);
//        } else {
        $sql     = $caseSensitive ? $column . ' LIKE ?' : 'LOWER(' . $column . ') LIKE ?';
        $keyword = $caseSensitive ? $keyword : Str::lower($keyword);
        $this->query->whereRaw($sql, [$keyword]);
//        }
    }

    /**
     * Perform pagination
     *
     * @return void
     */
    public function paging()
    {
        $this->query->skip($this->request['start'])
            ->take((int)$this->request['length'] > 0 ? $this->request['length'] : 10);
    }

    public function resolve()
    {
        $this->response->draw = intval($this->request->get("draw"));
        $this->response->data = $this->query->get();

        return new JsonResponse($this->response);
    }

    /**
     * @param $columns
     * @return EloquentManager $this
     */
    public function columns($columns)
    {
        $this->query->addSelect($columns);

        return $this;
    }

}