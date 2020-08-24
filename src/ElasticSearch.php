<?php

namespace Phenix\Core;

use Elasticsearch\Client;
use Phenix\Core\Traits\ElasticSearchHelper;

/**
 * Class ElasticSearch
 * @package Phenix\Core
 */
class ElasticSearch
{
    use ElasticSearchHelper;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array|\Illuminate\Config\Repository|mixed
     */
    protected $config = [];

    /**
     * ElasticSearch constructor.
     */
    public function __construct()
    {
        // set config first, before setting client
        $this->config = config('elasticsearch');
        $this->client = $this->buildClient();
    }

    /**
     * @param array $aggs
     * @param array $query
     * @param array $options
     * @param string $type
     * @param string $index
     * @return array|null
     * @throws \Exception
     */
    private function aggregations(array $aggs, array $query = [], array $options = [], $type, $index)
    {
        $params = [
            'index' => $index,
            'type' => $type,
            'body' => array_merge([
                'size' => 0,
                'aggs' => $aggs
            ], $options),
        ];

        // create query filters
        $filters = $this->setSearchQueryFilters(collect($query), [], $type);

        // set bool query if filters not empty
        if (!empty($filters)) {
            $params['body']['query'] = [
                'bool' => array_merge_recursive($params['body']['query']['bool'] ?? [], $filters)
            ];
        }

        $aggregations = null;

        try {
            $aggregations = $this->client->search($params);
        } catch (\Exception $exception) {
            if (config('app.debug')) {
                throw $exception;
            }
        }

        return $aggregations;
    }

    /**
     * @param array $query
     * @param array $options
     * @param array $range
     * @param $type
     * @param $index
     * @param string|null $scrollTime
     * @return |null
     * @throws \Exception
     */
    private function search(array $query = [],
                            array $options = [],
                            array $range = [],
                            $type,
                            $index,
                            string $scrollTime = null)
    {
        $params = [
            'index' => $index,
            'type' => $type,
            'body' => $options,
        ];

        if (isset($scrollTime)) {
            $params['scroll'] = $scrollTime;
        }

        // create query filters
        $filters = $this->setSearchQueryFilters(collect($query), [], $type);

        // set date range if not empty
        if (!empty($range)) {
            foreach ($range as $field => $value) {
                $filters['must'][] = ['range' => [$field => $value]];
            }
        }

        // set bool query if filters not empty
        if (!empty($filters)) {
            $params['body']['query'] = [
                'bool' => array_merge_recursive($params['body']['query']['bool'] ?? [], $filters)
            ];
        }

        $hits = null;
        $method = 'search';

        try {
            $hits = $this->client->$method($params);
        } catch (\Exception $exception) {
            if (config('app.debug')) {
                throw $exception;
            }
        }

        return $hits;
    }

    /**
     * @param array $query
     * @param array $range
     * @param string $type
     * @param string $index
     * @return int
     * @throws \Exception
     */
    private function count(array $query = [], array $range = [], $type, $index)
    {
        $hits = $this->search($query, ['size' => 0], $range, $type, $index);

        return $hits['count'] ?? 0;
    }

    /**
     * @param array $body
     * @param string $type
     * @param string $index
     * @return array
     */
    private function indexDocument(array $body, $type, $index)
    {
        $params = array_filter(compact('index', 'type', 'body'));

        return $this->client->index($params);
    }

    /**
     * @param string $id
     * @param string $type
     * @param string $index
     * @return array
     */
    private function getDocument($id, $type, $index)
    {
        $params = array_filter(compact('index', 'type', 'id'));

        return $this->client->get($params);
    }

    /**
     * @param array $fields
     * @param string $id
     * @param string $type
     * @param string $index
     * @return array
     */
    private function updateDocument(array $fields, $id, $type, $index)
    {
        $body = [
            'doc' => $fields
        ];

        $params = array_filter(compact('index', 'type', 'id', 'body'));

        return $this->client->update($params);
    }

    /**
     * @param string $id
     * @param string $type
     * @param string $index
     * @return array
     */
    private function deleteDocument($id, $type, $index)
    {
        $params = array_filter(compact('index', 'type', 'id'));

        return $this->client->delete($params);
    }

    /**
     * @param string|array $index
     * @return array
     */
    private function getSettings($index)
    {
        return $this->client->indices()->getSettings(compact('index'));
    }

    /**
     * @param array $settings
     * @param string $index
     * @return array
     */
    private function updateSettings(array $settings, $index)
    {
        $params = [
            'index' => $index,
            'body' => [
                'settings' => $settings
            ]
        ];

        return $this->client->indices()->putSettings($params);
    }

    /**
     * @param string|array $index
     * @param string $type
     * @return array
     */
    private function getMapping($index, $type)
    {
        $params = array_filter(compact('index', 'type'));

        return $this->client->indices()->getMapping($params);
    }

    /**
     * @param array $properties
     * @param string $type
     * @param string $index
     * @return array
     */
    private function updateMapping(array $properties, $type, $index)
    {
        // Set the index and type
        $params = [
            'index' => $index,
            'type' => $type,
            'body' => [
                $type => [
                    '_source' => [
                        'enabled' => true
                    ],
                    'properties' => $properties
                ]
            ]
        ];

        return $this->client->indices()->putMapping($params);
    }

    /**
     * @param array $mappings
     * @param array $settings
     * @param string $index
     * @return array
     */
    private function createIndex(array $mappings, array $settings, $index)
    {
        $params = [
            'index' => $index,
            'body' => [
                'settings' => $settings,
                'mappings' => $mappings
            ]
        ];

        return $this->client->indices()->create($params);
    }

    /**
     * @param string $index
     * @return bool
     */
    private function getIndex($index)
    {
        return $this->client->indices()->get(compact('index'));
    }

    /**
     * @param string $index
     * @return array
     */
    private function deleteIndex($index)
    {
        return $this->client->indices()->delete(compact('index'));
    }

    /**
     * @return Client
     * @credits https://github.com/aws/aws-sdk-php/issues/848#issuecomment-164592902
     */
    protected function buildClient()
    {
        // create builder
        $client_builder = \Elasticsearch\ClientBuilder::create()
            ->setHosts([$this->config['host']]);

        if ($this->config['aws']) {
            $psr7Handler = \Aws\default_http_handler();
            $signer = new \Aws\Signature\SignatureV4('es', config('aws.region', env('AWS_REGION')));
            $credentialProvider = \Aws\Credentials\CredentialProvider::defaultProvider();

            $handler = function (array $request) use ($psr7Handler, $signer, $credentialProvider) {
                // Create a PSR-7 request from the array passed to the handler
                $psr7Request = new \GuzzleHttp\Psr7\Request(
                    $request['http_method'],
                    (new \GuzzleHttp\Psr7\Uri($request['uri']))
                        ->withScheme($request['scheme'])
                        ->withHost($request['headers']['Host'][0]),
                    $request['headers'],
                    $request['body']
                );

                // Sign the PSR-7 request with credentials from the environment
                $signedRequest = $signer->signRequest(
                    $psr7Request,
                    call_user_func($credentialProvider)->wait()
                );

                // Send the signed request to Amazon ES
                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $psr7Handler($signedRequest)->then(function (\Psr\Http\Message\ResponseInterface $response) {
                    return $response;
                }, function ($error) {
                    return $error['response'];
                })->wait();

                // Convert the PSR-7 response to a RingPHP response
                return new \GuzzleHttp\Ring\Future\CompletedFutureArray([
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $response->getBody()->detach(),
                    'transfer_stats' => ['total_time' => 0],
                    'effective_url' => (string)$psr7Request->getUri(),
                ]);
            };

            $client_builder->setHandler($handler);
        }

        return $client_builder->build();
    }
}
