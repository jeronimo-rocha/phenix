<?php

namespace Phenix\Core\Traits;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use ReflectionException;
use ReflectionMethod;

/**
 * Trait ElasticSearchHelper
 * @package Phenix\Core\Traits
 */
trait ElasticSearchHelper
{
    /**
     * @param Request $request
     * @param array $defaults
     * @param string $type
     * @return array
     */
    private function setSearchParams(Request $request, array $defaults = [], $type)
    {
        $properties = $this->getMappingProperties($type)->all();
        $fillables = array_keys($properties);
        $query = array_filter($request->only($fillables));

        if ($query && !empty($query['src']) && $query['src'] == 'all') {
            unset($query['src']);
        }

        // set date range
        $date_range = [];
        $range = $request->input('range', 'all-time');

        if ($range == 'custom') {
            $start = $request->input('start');
            $end = $request->input('end');
        } elseif ($range && $range != 'all-time') {
            extract($this->getDateRange($range));
        }

        // get default time filter field
        $time_filter_field = $this->defaultTimeFilterField();

        // set default values
        $defaults = array_merge([
            'sort' => $time_filter_field,
            'order' => 'desc',
            'size' => 30
        ], $defaults);

        // set sorting and paging options
        $sort = $request->input('sort', $defaults['sort']);
        $order = $request->input('order', $defaults['order']);
        $size = (int)$request->input('size', $defaults['size']);

        // parse and set start date if valid
        if (!empty($start)) {
            try {
                $date_range[$time_filter_field]['gte'] = Carbon::parse($start)->startOfDay()->toDateTimeString();
            } catch (Exception $e) {
                $invalid_start = true;
                $start = Carbon::now()->subWeek()->toDateString();
            }
        }

        // parse and set end date if valid
        if (!empty($end)) {
            try {
                $date_range[$time_filter_field]['lte'] = Carbon::parse($end)->endOfDay()->toDateTimeString();
            } catch (Exception $e) {
                $invalid_end = true;
                $end = Carbon::yesterday()->toDateString();
            }
        }

        // set time filter field format and timezone if any
        if (!empty($date_range[$time_filter_field])) {
            $date_range[$time_filter_field]['format'] = 'yyyy-MM-dd HH:mm:ss';
            $date_range[$time_filter_field]['time_zone'] = config('app.timezone');
        }

        // validate sort
        if (($invalid_sort = !in_array($sort, $fillables))) {
            $sort = $defaults['sort'];
        }

        // if sort field type is text, check for a keyword type field if any
        if ($properties[$sort]['type'] == 'text' && $invalid_sort = true && !empty($properties[$sort]['fields'])) {
            foreach ($properties[$sort]['fields'] as $key => $field) {
                if ($field['type'] == 'keyword') {
                    $sort = $sort . '.' . $key;
                    $invalid_sort = false;
                    break;
                }
            }
        }

        // validate order
        if ($invalid_order = !in_array($order, ['desc', 'asc'])) {
            $order = $defaults['order'];
        }

        // validate size
        if ($invalid_size = $size < 1) {
            $size = $defaults['size'];
        }

        $filters = array_filter(compact('range', 'start', 'end', 'sort', 'order', 'size'));

        // search_after
        if (($search_after = $request->input('search_after')) && !is_array($search_after)) {
            $search_after = explode(',', $search_after);
        }

        $options = array_merge(
            array_filter(compact('search_after', 'size')),
            [
                'from' => -1,
                'sort' => [
                    $sort => compact('order'),
                    '_uid' => [
                        'order' => 'asc'
                    ]
                ]
            ]
        );

        // add errors for invalid filters
        $errors = compact('invalid_start', 'invalid_end', 'invalid_sort', 'invalid_order', 'invalid_size');

        return compact('query', 'date_range', 'filters', 'options', 'errors');
    }

    /**
     * @param string $range
     * @param string|null $format
     * @return array
     */
    protected function getDateRange($range, $format = null)
    {
        switch ($range) {
            case 'today':
                $start = Carbon::now()->startOfDay();
                $end = Carbon::now()->endOfDay();
                break;

            case 'yesterday':
                $start = Carbon::yesterday()->startOfDay();
                $end = Carbon::yesterday()->endOfDay();
                break;

            case 'this-month':
                $start = Carbon::now()->startOfMonth()->startOfDay();
                $end = Carbon::now()->endOfDay();
                break;

            case 'last-month':
                $start = Carbon::now()->subMonth()->startOfMonth()->startOfDay();
                $end = Carbon::now()->subMonth()->endOfMonth()->endOfDay();
                break;

            case 'last-2-months':
                $start = Carbon::now()->subMonths(2)->startOfMonth()->startOfDay();
                $end = Carbon::now()->subMonth()->endOfMonth()->endOfDay();
                break;

            case 'last-3-months':
                $start = Carbon::now()->subMonths(3)->startOfMonth()->startOfDay();
                $end = Carbon::now()->subMonth()->endOfMonth()->endOfDay();
                break;

            default: // last-7-days
                $start = Carbon::now()->subWeek()->startOfDay();
                $end = Carbon::yesterday()->endOfDay();
                break;
        }

        if ($format) {
            $start = $start->format($format);
            $end = $end->format($format);
        }

        return compact('start', 'end');
    }

    /**$format
     * @param string|Carbon $start
     * @param string|Carbon $end
     * @param string|null $format
     * @return array
     */
    protected function setAggregationDailyDateRanges($start, $end, $format = null)
    {
        $date_ranges = [];

        try {
            if (!$start instanceof Carbon) {
                $start = Carbon::parse($start);
            }

            if (!$end instanceof Carbon) {
                $end = Carbon::parse($end);
            }

            // make sure to reset the dates time
            $start->startOfDay();
            $end->startOfDay();

            // count number of days
            $num_of_days = $start->diffInDays($end);

            // set date format if any
            $format_date = function ($date) use ($format) {
                return $format ? $date->format($format) : $date;
            };

            // add date range (from, to)
            $add_date_range = function ($date) use ($format_date) {
                return [
                    'from' => $format_date($date->startOfDay()),
                    'to' => $format_date($date->endOfDay())
                ];
            };

            // set start date as first range
            $date_ranges[] = $add_date_range($start);

            for ($day = 1; $day < $num_of_days; $day++) {
                $date_ranges[] = $add_date_range($start->copy()->addDays($day));
            }

            // set end date as last range
            $date_ranges[] = $add_date_range($end);
        } catch (Exception $e) {
        }

        return $date_ranges;
    }

    /**
     * @return array
     */
    protected function defaultAggregationNames()
    {
        return $this->config['defaults']['aggregation_names'];
    }

    /**
     * @return string
     */
    protected function defaultIndex()
    {
        return $this->config['defaults']['index'];
    }

    /**
     * @return string
     */
    protected function defaultType()
    {
        return $this->config['defaults']['type'];
    }

    /**
     * @return string
     */
    protected function defaultTimeFilterField()
    {
        return $this->config['defaults']['time_filter_field'];
    }

    /**
     * @param Collection $query
     * @param array $bool_clauses
     * @param string $type
     * @return array
     */
    protected function setSearchQueryFilters(Collection $query, array $bool_clauses = [], $type)
    {
        $filters = [];

        if (!$query->isEmpty()) {

            // get properties
            $properties = $this->getMappingProperties($type);

            // get text type properties
            $text_type_props = $this->getMappingPropertiesByDataType($properties, 'text');

            // get keyword type properties
            // included types: keyword, ip, integer, short, long
            $keyword_type_props = $this->getMappingPropertiesByDataType($properties, ['keyword', 'ip', 'integer', 'short', 'long']);

            // get boolean type properties
            $bool_type_props = $this->getMappingPropertiesByDataType($properties, 'boolean');

            // prepare keyword data type filter
            $bool_clauses[] = $this->setBoolQueryClause($query, $keyword_type_props, 'term', 'filter');

            // prepare text data type matching
            $bool_clauses[] = $this->setBoolQueryClause($query, $text_type_props, 'match', 'must');

            // prepare boolean data type filter
            $bool_clauses[] = $this->setBoolQueryClause($query, $bool_type_props, 'term', 'filter', function ($value) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            });
        }

        foreach ($bool_clauses as $clause) {
            foreach ($clause as $occur => $context) {
                foreach ($context as $field) {
                    $filters[$occur][] = $field;
                }
            }
        }

        return $filters;
    }

    /**
     * @param Collection $query
     * @param array $properties
     * @param string $context
     * @param string $occur
     * @param callable|null $callback
     * @return array
     */
    protected function setBoolQueryClause(Collection $query, array $properties, $context, $occur, callable $callback = null)
    {
        $data = [];

        $query->only($properties)->each(function ($value, $key) use ($context, $occur, $callback, &$data) {
            $belongs = $occur;

            // all string values that starts with exclamation mark (!) is treated as not equal
            if (is_string($value) && $value[0] == '!') {
                $belongs = 'must_not';

                $value = ltrim($value, '!');
            }

            if (is_array($value) && $context == 'term') {
                $context = 'terms';
            }

            $data[$belongs][] = [$context => [$key => is_callable($callback) ? $callback($value) : $value]];
        });

        return $data;
    }

    /**
     * @param Collection $properties
     * @param string|array $data_type
     * @return array
     */
    protected function getMappingPropertiesByDataType(Collection $properties, $data_type)
    {
        $data_types = is_string($data_type) ? [$data_type] : $data_type;

        return $properties->filter(function ($field) use ($data_types) {
            return in_array($field['type'], $data_types);
        })->keys()->all();
    }

    /**
     * @param string $type
     * @return Collection
     */
    protected function getMappingProperties($type)
    {
        return collect($this->config['mappings'][$type]['properties']);
    }

    /**
     * @param string $method
     * @param array|null $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (method_exists($this, $method)) {
            try {
                $reflection_method = new ReflectionMethod($this, $method);
                foreach ($reflection_method->getParameters() as $param) {
                    $position = $param->getPosition();

                    if (isset($args[$position])) {
                        continue;
                    }

                    if (!$param->isDefaultValueAvailable()) {
                        switch ($param->name) {
                            case 'type':
                                $arg_value = $this->defaultType();
                                break;

                            case 'index':
                                $arg_value = $this->defaultIndex();
                                break;

                            case 'settings':
                                $arg_value = $this->config['settings'];
                                break;

                            case 'mappings':
                                $arg_value = $this->config['mappings'];
                                break;

                            default:
                                $arg_value = null;
                                break;
                        }
                    } else {
                        $arg_value = $param->getDefaultValue();
                    }

                    $args[$position] = $arg_value;
                }
            } catch (ReflectionException $e) {

            }

            return call_user_func_array([$this, $method], $args);
        } elseif (method_exists($this->client, $method)) {

            return call_user_func_array([$this->client, $method], $args);
        }
    }
}
