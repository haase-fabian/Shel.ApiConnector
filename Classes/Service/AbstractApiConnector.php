<?php
declare(strict_types=1);

namespace Shel\ApiConnector\Service;

/*                                                                        *
 * This script belongs to the Flow package "Shel.ApiConnector".           *
 *                                                                        */

use GuzzleHttp\Psr7\Uri;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Psr\Http\Message\ResponseInterface;
use Shel\ApiConnector\Log\ApiConnectorLoggerInterface;

/**
 * Abstract base class for api connectors.
 *
 * Requires settings like:
 *
 * Vendor:
 *   Package:
 *     <implementationName>:
 *       apiUrl: 'https://my.rest.api/v2'
 *       timeout: 30
 *       parameters: # Are optional and will be added to each request
 *         api_key: 'xyz'
 *         format: 'json'
 *       actions:
 *         xyz: 'my_action.php'
 */
abstract class AbstractApiConnector
{
    /**
     * @var array
     */
    protected $requestEngineOptions = [];

    /**
     * This should be overriden for the implementation.
     *
     * @Flow\Inject(setting="<implementationName>")
     *
     * @var array
     */
    protected $apiSettings;

    /**
     * @Flow\Inject
     *
     * @var VariableFrontend
     */
    protected $apiCache;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $fallbackApiCache;

    /**
     * @var array
     */
    protected $objectCache = [];

    /**
     * @Flow\Inject
     *
     * @var ApiConnectorLoggerInterface
     */
    protected $logger;

    /**
     * Retrieves data from the api.
     *
     * @param string $actionName
     * @param array $additionalParameters
     * @return array
     * @throws CacheException
     */
    public function fetchData($actionName, array $additionalParameters = []): array
    {
        $requestUri = $this->buildRequestUri($actionName, $additionalParameters);
        $fallbackCacheKey = $this->getCacheKey($requestUri);
        $response = false;

        if ($this->apiSettings['useFallbackCache']) {
            $response = $this->fallbackApiCache->get($fallbackCacheKey);
        }

        if ($response === false) {
            // Without a fallback cache wait for data retrieval
            $response = $this->fetchDataInternal($requestUri);
        }

        return $response !== false ? json_decode($response, true) : [];
    }

    /**
     * @param string $actionName
     * @param array $additionalParameters
     *
     * @return Uri
     */
    protected function buildRequestUri($actionName, array $additionalParameters = []): Uri
    {
        $requestUri = new Uri($this->apiSettings['apiUrl']);
        $requestUri = $requestUri->withPath($requestUri->getPath() . $this->apiSettings['actions'][$actionName])
            ->withQuery(http_build_query(array_merge($this->apiSettings['parameters'], $additionalParameters)));
        return $requestUri;
    }

    /**
     * Creates a valid cache identifier.
     *
     * @param $identifier
     *
     * @return string
     */
    protected function getCacheKey($identifier): string
    {
        return sha1(self::class . '__' . $identifier);
    }

    /**
     * @param string $requestUri
     * @return bool|ResponseInterface
     * @throws CacheException
     */
    protected function fetchDataInternal($requestUri)
    {
        $browser = $this->getBrowser();
        try {
            $response = $browser->request($requestUri, 'GET');
        } catch (\Exception $e) {
            $this->logger->error('Get request to Api failed with exception', [$e]);
            $response = false;
        }

        if ($response !== false && $response->getStatusCode() !== 200) {
            $this->logger->error('Get request to Api failed with code', [$response->getStatusCode()]);
        }

        // Store new data in fallback cache if it's valid
        if ($this->apiSettings['useFallbackCache'] && $response !== false) {
            $this->fallbackApiCache->set($this->getCacheKey($requestUri), $response);
        }

        return $response;
    }

    /**
     * Returns a browser instance with curlengine and authentication parameters set.
     * Authorization parameters will be added if defined in the configuration.
     *
     * @return Browser
     */
    protected function getBrowser(): Browser
    {
        $browser = new Browser();
        $curlEngine = new CurlEngine();
        foreach ($this->requestEngineOptions as $option => $value) {
            $curlEngine->setOption($option, $value);
        }
        $curlEngine->setOption(CURLOPT_CONNECTTIMEOUT, (int)$this->apiSettings['timeout']);
        $browser->setRequestEngine($curlEngine);

        if (array_key_exists('username', $this->apiSettings) && !empty($this->apiSettings['username'])
            && array_key_exists('password', $this->apiSettings) && !empty($this->apiSettings['password'])
        ) {
            $browser->addAutomaticRequestHeader('Authorization',
                'Basic ' . base64_encode($this->apiSettings['username'] . ':' . $this->apiSettings['password']));
        }

        return $browser;
    }

    /**
     * Json encodes data and posts it to the api
     *
     * @param string $actionName
     * @param array $additionalParameters
     * @param array $data
     * @return bool
     */
    public function postJsonData(
        $actionName,
        array $additionalParameters = [],
        array $data = []
    ): bool {
        $browser = $this->getBrowser();
        $browser->addAutomaticRequestHeader('Content-Type', 'application/json');
        $requestUri = $this->buildRequestUri($actionName, $additionalParameters);
        try {
            $response = $browser->request($requestUri, 'POST', [], [], [], json_encode($data));
        } catch (InfiniteRedirectionException $e) {
            $this->logger->error('Post request to Api failed with an infinite redirection', [$e]);
            return false;
        }

        if (!in_array($response->getStatusCode(), [200, 204])) {
            $this->logger->error('Post request to Api failed with message', [$response->getStatusCode()]);
            return false;
        }
        return true;
    }

    /**
     * @param string $cacheKey
     *
     * @return mixed
     */
    protected function getItem($cacheKey)
    {
        if (array_key_exists($cacheKey, $this->objectCache)) {
            return $this->objectCache[$cacheKey];
        }
        $item = $this->apiCache->get($cacheKey);
        $this->objectCache[$cacheKey] = $item;

        return $item;
    }

    /**
     * @param string $cacheKey
     * @param mixed $value
     * @param array $tags
     * @throws CacheException
     */
    protected function setItem($cacheKey, $value, $tags = []): void
    {
        $this->objectCache[$cacheKey] = $value;
        $this->apiCache->set($cacheKey, $value, $tags);
    }

    /**
     * @param string $cacheKey
     */
    protected function unsetItem($cacheKey): void
    {
        unset($this->objectCache[$cacheKey]);
        $this->apiCache->remove($cacheKey);
    }
}
