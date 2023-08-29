<?php


namespace Bloom;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class QueryHelper
{
    protected Builder $builder;
    protected array $searchParams;

    public function __construct(Builder $builder, array $searchParams)
    {
        $this->builder = $builder;
        $this->searchParams = $searchParams;
    }

    /**
     * 字符串逗号分隔查询(中英文逗号)
     *
     * @param array $columns
     * @return $this
     */
    public function commaSearch(array $columns): static
    {
        foreach ($columns as $column) {
            $arr = array_filter(array_map('trim', explode(',', str_replace(' ', ',', str_replace('，', ',', data_get($this->searchParams, $this->_columnSplit($column), ''))))));

            if ($arr) {
                if (count($arr) == 1) {
                    $this->builder->where($this->_columnSplit($column, false), Arr::first($arr));
                } else {
                    $this->builder->whereIn($this->_columnSplit($column, false), $arr);
                }
            }
        }

        return $this;
    }

    /**
     * 字符串精确查询
     *
     * @param array $columns
     * @return $this
     */
    public function exactSearch(array $columns): static
    {
        foreach ($columns as $column) {
            $paramValue = Arr::get($this->searchParams, $this->_columnSplit($column));

            if ($paramValue || $paramValue === 0 || $paramValue === '0') {
                $this->builder->where($this->_columnSplit($column, false), $paramValue);
            }
        }

        return $this;
    }

    /**
     * 模糊查询
     * @param array $columns
     * @param bool $leftFuzzy
     * @return $this
     */
    protected function fuzzySearch(array $columns, bool $leftFuzzy = false): static
    {
        foreach ($columns as $column) {
            $paramValue = data_get($this->searchParams, $this->_columnSplit($column));

            if ($paramValue) {
                $this->builder->where($this->_columnSplit($column, false), 'like', ($leftFuzzy ? '%' : '') . "{$paramValue}%");
            }
        }

        return $this;
    }

    /**
     * 范围查询
     *
     * @param array $columns
     * @return $this
     */
    protected function betweenSearch(array $columns): static
    {
        foreach ($columns as $column) {
            $paramValue = Arr::get($this->searchParams, $this->_columnSplit($column));

            if ($paramValue) {
                $this->builder->whereBetween($this->_columnSplit($column, false), $paramValue);
            }
        }

        return $this;
    }

    /**
     * in查询
     *
     * @param array $columns
     * @return $this
     */
    protected function inSearch(array $columns): static
    {
        foreach ($columns as $column) {
            $paramValue = Arr::get($this->searchParams, $this->_columnSplit($column));

            if ($paramValue) {
                if (is_array($paramValue)) {
                    $this->builder->whereIn($this->_columnSplit($column, false), $paramValue);
                } else {
                    $this->builder->where($this->_columnSplit($column, false), $paramValue);
                }
            }
        }

        return $this;
    }

    private function _relationKeyExist(array $methodAndColumns): bool
    {
        $columns = array_values($methodAndColumns);

        if (!$columns) {
            return false;
        }

        $paramFields = [];
        foreach ($columns as $paramName) {
            if (!is_array($paramName)) {
                $multi = [$paramName];
            } else {
                $multi = array_values($paramName);
            }

            // 兼容别名 name:cn_name  前端传递参数为cn_name,表字段为cn_name,
            $paramFields = array_merge($paramFields, $multi);
        }

        return $this->_keyExist($paramFields);
    }

    /**
     * 关联查询(whereHasIn)
     * 注意:需要引入“dcat/laravel-wherehasin” composer包
     *
     *  COMMA = 逗号分割查询,FUZZY = 模糊查询, IN in查询, BETWEEN 范围查询
     *  传参例子 :
     *  [
     *     'category' => ['COMMA'=>['category_sn:sn'], 'FUZZY' => ['category_name:name','value','desc']],
     *  ]
     *
     * @param array $columns
     * @return $this
     */
    public function whereHasInSearch(array $columns): static
    {
        foreach ($columns as $relation => $methodAndColumns) {
            if ($this->_relationKeyExist($methodAndColumns)) {
                $this->builder->whereHasIn($relation, function ($query) use ($methodAndColumns) {
                    foreach ($methodAndColumns as $method => $fields) {
                        if (!is_array($fields)) {
                            $fields = (array)$fields;
                        }

                        foreach ($fields as $field) {
                            if (!$this->_keyExist([$field])) {
                                continue;
                            }

                            $paramValue = Arr::get($this->searchParams, $this->_columnSplit($field));
                            $dbField = $this->_columnSplit($field, false);

                            if ($method === 'COMMA') {
                                $arr = array_filter(array_map('trim', explode(',', str_replace(' ', ',', str_replace('，', ',', $paramValue)))));

                                if ($arr) {
                                    if (count($arr) == 1) {
                                        $query->where($dbField, Arr::first($arr));
                                    } else {
                                        $query->whereIn($dbField, $arr);
                                    }
                                }
                            } elseif ($method === 'FUZZY') {
                                $query->where($dbField, 'like', "{$paramValue}%");
                            } elseif ($method === 'FUZZY_LEFT') {
                                $query->where($dbField, 'like', "%{$paramValue}%");
                            } elseif ($method === 'IN') {
                                $query->whereIn($dbField, $paramValue);
                            } elseif ($method === 'BETWEEN') {
                                if (!is_array($paramValue)) {
                                    $query->where($dbField, $paramValue);
                                } else {
                                    $query->whereBetween($dbField, $paramValue);
                                }
                            } else {
                                $query->where($dbField, $paramValue);
                            }
                        }
                    }
                });
            }
        }

        return $this;
    }


    /**
     * 关联查询(whereHas)
     * COMMA = 逗号分割查询,FUZZY = 模糊查询, IN in查询, BETWEEN 范围查询
     * 传参例子 :
     * [
     *    'category' => ['COMMA'=>['category_sn:sn'], 'FUZZY' => ['category_name:name','value','desc']],
     * ]
     * @param array $columns
     * @return $this
     */
    public function whereHasSearch(array $columns): static
    {
        foreach ($columns as $relation => $methodAndColumns) {
            if ($this->_relationKeyExist($methodAndColumns)) {
                $this->builder->whereHas($relation, function ($query) use ($methodAndColumns) {
                    foreach ($methodAndColumns as $method => $fields) {
                        if (!is_array($fields)) {
                            $fields = (array)$fields;
                        }

                        foreach ($fields as $field) {
                            if (!$this->_keyExist([$field])) {
                                continue;
                            }

                            $paramValue = Arr::get($this->searchParams, $this->_columnSplit($field));
                            $dbField = $this->_columnSplit($field, false);

                            if ($method === 'COMMA') {
                                $arr = array_filter(array_map('trim', explode(',', str_replace(' ', ',', str_replace('，', ',', $paramValue)))));

                                if ($arr) {
                                    if (count($arr) == 1) {
                                        $query->where($dbField, Arr::first($arr));
                                    } else {
                                        $query->whereIn($dbField, $arr);
                                    }
                                }
                            } elseif ($method === 'FUZZY') {
                                $query->where($dbField, 'like', "{$paramValue}%");
                            } elseif ($method === 'FUZZY_LEFT') {
                                $query->where($dbField, 'like', "%{$paramValue}%");
                            } elseif ($method === 'IN') {
                                $query->whereIn($dbField, $paramValue);
                            } elseif ($method === 'BETWEEN') {
                                if (!is_array($paramValue)) {
                                    $query->where($dbField, $paramValue);
                                } else {
                                    $query->whereBetween($dbField, $paramValue);
                                }
                            } else {
                                $query->where($dbField, $paramValue);
                            }
                        }
                    }
                });
            }
        }

        return $this;
    }

    /**
     * 列表支持组合排序
     *
     * @param array $columns
     * @return $this
     */
    public function sort(array $columns): static
    {
        $listSorts = $this->searchParams['orderBy'] ?? [];

        if (!$listSorts) {
            return $this;
        }

        // 按顺序组合排序
        foreach ($listSorts as $listSort) {

            foreach ($columns as $column) {
                $paramName = $this->_columnSplit($column);

                if ($listSort['field'] == $paramName && $listSort['order']) {
                    $dbField = $this->_columnSplit($column, false);

                    $this->builder->orderBy($dbField, $listSort['order']);
                }

            }
        }

        return $this;
    }

    /**
     * 建议:前端传参和db字段一致,否则需要调用此方法用于别名
     * 格式:前端字段 + 冒号 + db字段
     * 例子:['menu_name:name','shop_id:id']
     *
     * @param $column
     * @param bool $first
     * @return mixed
     */
    private function _columnSplit($column, bool $first = true): mixed
    {
        $arr = explode(':', $column);

        if ($first) {
            return Arr::first($arr);
        }

        return Arr::last($arr);
    }

    /**
     * 检查参数是否存在
     *
     * @param array $fields
     *
     * @return bool
     */
    private function _keyExist(array $fields): bool
    {
        foreach ($fields as $field) {
            $paramValue = Arr::get($this->searchParams, $this->_columnSplit($field));

            if ($paramValue || $paramValue === 0 || $paramValue === '0') {
                return true;
            }
        }

        return false;
    }
}
