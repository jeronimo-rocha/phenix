<?php

namespace Phenix\Core\Aggregations;

use Phenix\Core\Enumerators\ElasticSearchAggregationTypeEnum;

/**
 * Class ElasticSearchValueCountAggregation
 * @package Phenix\Core\Aggregations
 */
class ElasticSearchValueCountAggregation extends ElasticSearchAggregation
{
    /**
     * ElasticSearchValueCountAggregation constructor.
     * @param string $name
     * @param string $value
     */
    public function __construct(string $name, string $value)
    {
        $this->type = ElasticSearchAggregationTypeEnum::VALUE_COUNT;
        $this->name = $name;
        $this->value = $value;

        return $this;
    }

    /**
     * @return array
     */
    public function buildForRequest(): array
    {
        return [
            $this->getSyntaxOfAggregation() => [
                'field' => $this->value
            ]
        ];
    }

    /**
     * @param array $values
     * @return mixed
     */
    public function treatResponse(array $values)
    {
        return $values['value'];
    }
}
