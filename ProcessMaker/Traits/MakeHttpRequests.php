<?php

namespace ProcessMaker\Traits;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Mustache_Engine;
use ProcessMaker\Exception\HttpResponseException;
use ProcessMaker\Models\FormalExpression;
use Psr\Http\Message\ResponseInterface;

trait MakeHttpRequests
{
    private $authTypes = [
        'BASIC' => 'basicAuthorization',
        'OAUTH2_BEARER' => 'bearerAuthorization',
        'OAUTH2_PASSWORD' => 'passwordAuthorization',
    ];

    /**
     * Verify certificate ssl
     *
     * @var bool
     */
    protected $verifySsl = true;

    private $mustache = null;

    private function getMustache() {
       if ($this->mustache === null)  {
           $this->mustache = app(Mustache_Engine::class);
       }
       return $this->mustache;
    }

    /**
     * Send a HTTP request based on the datasource, configuration
     * and the process request data.
     *
     * @param array $data
     * @param array $config
     *
     * @return array
     *
     * @throws GuzzleException
     * @throws HttpResponseException
     */
    public function request(array $data = [], array $config = [])
    {
        try {
            $request = $this->prepareRequestWithOutboundConfig($data, $config);
            return $this->responseWithHeaderData($this->call(...$request), $data, $config);
        } catch (ClientException $exception) {
            throw new HttpResponseException($exception->getResponse());
        }
    }

    /**
     * Prepares data for the http request replacing mustache with pm instance
     *
     * @param array $data, request data
     * @param array $config, datasource configuration
     *
     * @return array
     */
    private function prepareRequest(array &$data, array &$config)
    {
        $endpoint = $this->endpoints[$config['endpoint']];
        $method = $this->getMustache()->render($endpoint['method'], $data);

        $url = $this->addQueryStringsParamsToUrl($endpoint, $config, $data);

        $this->verifySsl = array_key_exists('verify_certificate', $this->credentials)
            ? $this->credentials['verify_certificate']
            : true;

        $headers = $this->addHeaders($endpoint, $config, $data);

        $body = $this->getMustache()->render($endpoint['body'], $data);
        $bodyType = null;
        if (isset($endpoint['body_type'])) {
            $bodyType = $this->getMustache()->render($endpoint['body_type'], $data);
        }
        $request = [$method, $url, $headers, $body, $bodyType];
        $request = $this->addAuthorizationHeaders(...$request);
        return $request;
    }

    /**
     * Prepare data to be used in body (mustache)
     *
     * @param array $requestData
     * @param array $outboundConfig
     * @param string $type PARAM HEADER BODY
     *
     * @return array
     */
    private function prepareData(array $requestData, array $outboundConfig, $type)
    {
        $data = $requestData;
        foreach ($outboundConfig as $outbound) {
            if ($outbound['type'] === $type) {
                // Default format for mapping input is { Mustache }
                if (isset($outbound['format']) && $outbound['format'] === 'feel') {
                    $data[$outbound['key']] = $this->evalExpression($outbound['value'], $requestData);
                } else {
                    $data[$outbound['key']] = $this->evalMustache($outbound['value'], $requestData);
                }
            }
        }
        return $data;
    }

    /**
     * Evaluate a BPMN FEEL expression
     * ex.
     *      foo             => bar
     *      _request.id     => 1001
     *      _user.id        => 101
     *      10              => 10
     *      {{ form.age }}  => 21
     *      "{{ form.lastname }} {{ form.firstname }}" => John Doe
     *
     * @return mixed
     */
    private function evalExpression($expression, array $data)
    {
        try {
            $formal = new FormalExpression();
            $formal->setBody($expression);
            return $formal($data);
        } catch (Exception $exception) {
            return "{$expression}: " . $exception->getMessage();
        }
    }

    /**
     * Evaluate a mustache expression
     * ex.
     *      foo             => "foo"
     *      10              => "10"
     *      {{ user.id }}  => "101"
     *      {{ form.age }}  => "21"
     *      {{ form.lastname }} {{ form.firstname }} => "John Doe"
     * @return string
     */
    private function evalMustache($expression, array $data)
    {
        try {
            return $this->getMustache()->render($expression, $data);
        } catch (Exception $exception) {
            return "{$expression}: " . $exception->getMessage();
        }
    }

    /**
     * Prepares data for the http request replacing mustache with pm instance and OutboundConfig
     *
     * @param array $data, request data
     * @param array $config, datasource configuration
     *
     * @return array
     */
    private function prepareRequestWithOutboundConfig(array $requestData, array &$config)
    {
        $outboundConfig = $config['outboundConfig'] ?? [];
        $endpoint = $this->endpoints[$config['endpoint']];
        $this->verifySsl = array_key_exists('verify_certificate', $this->credentials)
            ? $this->credentials['verify_certificate']
            : true;

        // Prepare URL
        $data = $this->prepareData($requestData, $outboundConfig, 'PARAM');
        $method = $this->evalMustache($endpoint['method'], $data);
        $url = $this->addQueryStringsParamsToUrl($endpoint, $config, $data);

        // Prepare Headers
        $data = $this->prepareData($requestData, $outboundConfig, 'HEADER');
        $headers = $this->addHeaders($endpoint, $config, $data);

        // Prepare Body
        $data = $this->prepareData($requestData, $outboundConfig, 'BODY');
        $body = $this->getMustache()->render($endpoint['body'], $data);
        $bodyType = null;
        if (isset($endpoint['body_type'])) {
            $bodyType = $this->getMustache()->render($endpoint['body_type'], $data);
        }

        $request = [$method, $url, $headers, $body, $bodyType];
        $request = $this->addAuthorizationHeaders(...$request);
        return $request;
    }

    /**
     * Add authorization parameters
     *
     * @param array ...$config
     *
     * @return array
     */
    private function addAuthorizationHeaders(...$config)
    {
        if (isset($this->authTypes[$this->authtype])) {
            $callable = [$this, $this->authTypes[$this->authtype]];
            return call_user_func_array($callable, $config);
        }
        return $config;
    }

    /**
     * Add basic authorization to header
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param $bodyType
     *
     * @return array
     */
    private function basicAuthorization($method, $url, $headers, $body, $bodyType)
    {
        if (isset($this->credentials) && is_array($this->credentials)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->credentials['username'] . ':' . $this->credentials['password']);
        }
        return [$method, $url, $headers, $body, $bodyType];
    }

    /**
     * Add bearer authorization to header
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param $bodyType
     *
     * @return array
     */
    private function bearerAuthorization($method, $url, $headers, $body, $bodyType)
    {
        if (isset($this->credentials) && is_array($this->credentials)) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        }
        return [$method, $url, $headers, $body, $bodyType];
    }

    /**
     * Get token with credentials
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param $bodyType
     *
     * @return array
     */
    private function passwordAuthorization($method, $url, $headers, $body, $bodyType)
    {
        if (isset($this->credentials) && is_array($this->credentials)) {
            //todo enable mustache
            $config = [
                'username' => $this->credentials['username'],
                'password' => $this->credentials['password'],
                'grant_type' => 'password',
                'client_id' => $this->credentials['client_id'],
                'client_secret' => $this->credentials['client_secret'],
            ];

            $token = $this->response($this->call('POST', $this->credentials['url'], ['Accept' => 'application/json'], json_encode($config), 'form-data'), [], ['dataMapping' => []], new Mustache_Engine());
            $headers['Authorization'] = 'Bearer ' . $token['response']['access_token'];
        }
        return [$method, $url, $headers, $body, $bodyType];
    }

    /**
     * Prepare the response, using the mapping configuration
     *
     * @param Response $response
     * @param array $data
     * @param array $config
     * @param Mustache_Engine $mustache
     *
     * @return array
     * @throws HttpResponseException
     */
    private function response($response, array $data = [], array $config = [])
    {
        $status = $response->getStatusCode();
        $bodyContent = $response->getBody()->getContents();
        if (!$this->isJson($bodyContent)) {
            return ["response" => $bodyContent, "status" => $status];
        }

        switch (true) {
            case $status == 200:
                $content = json_decode($bodyContent, true);
                break;
            case $status > 200 && $status < 300:
                $content = [];
                break;
            default:
                throw new HttpResponseException($response);
        }
        $mapped = [];
        !is_array($content) ?: $merged = array_merge($data, $content);
        $mapped['status'] = $status;
        $mapped['response'] = $content;

        if (isset($config['dataMapping'])) {
            foreach ($config['dataMapping'] as $map) {
                if ($map['value']) {
                    $value = Arr::get($merged, $map['value'], '');
                } else {
                    $value = $content;
                }
                Arr::set($mapped, $map['key'], $value);
            }
        }
        return $mapped;
    }

    private function responseWithHeaderData($response, array $data = [], array $config = [])
    {
        $status = $response->getStatusCode();
        $bodyContent = $response->getBody()->getContents();
        if (!$this->isJson($bodyContent)) {
            return ["response" => $bodyContent, "status" => $status];
        }

        switch (true) {
            case $status == 200:
                $content = json_decode($bodyContent, true);
                break;
            case $status > 200 && $status < 300:
                $content = [];
                break;
            default:
                throw new HttpResponseException($response);
        }

        $mapped = [];
        $mapped['status'] = $status;
        $mapped['response'] = $content;

        if (!isset($config['dataMapping'])) {
            return $mapped;
        }

        $headers = array_map(function ($item) {
            if (count($item) > 0) {
                return $item[0];
            }
        }, $response->getHeaders());

        $merged = array_merge($data, $content, $headers);
        $responseData = array_merge($content, $headers);

        foreach ($config['dataMapping'] as $map) {
            $processVar = $this->getMustache()->render($map['key'], $data);
            $value = $map['value'];
            $url = $this->endpoints[$config['endpoint']]['url'];

            // if value is empty all the response is mapped
            if (trim($value) === '') {
                $mapped[$processVar] = $responseData;
                continue;
            }

            // if is a collection connector, by default it is not necessary to send data.data and we add it by default
            if (preg_match('/\/api\/[0-9\.]+\/collections/m', $url) === 1) {
                $value = $this->addCollectionsRootObject($value);
            }

            // Default format for mapping response is FEEL unless $value contains {{}}
            $format = $map['format'] ?? (str_contains($value, '{{') ? 'mustache' : 'feel');
            if ($format === 'mustache') {
                $evaluatedApiVar = $this->evalMustache($map['value'], $merged);
            } else {
                $evaluatedApiVar = $this->evalExpression($map['value'], $merged);
            }
            $mapped[$processVar] = $evaluatedApiVar;
        }

        return $mapped;
    }

    /**
     * Call an HTTP request
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param $body
     * @param string $bodyType
     *
     * @return mixed|ResponseInterface
     *
     * @throws GuzzleException
     */
    private function call($method, $url, array $headers, $body, $bodyType)
    {
        $client = new Client(['verify' => $this->verifySsl]);
        $options = [];
        if ($bodyType === 'form-data') {
            $options['form_params'] = json_decode($body, true);
        }
        $request = new Request($method, $url, $headers, $body);
        return $client->send($request, $options);
    }

    /**
     * @param string $url
     * @param $endpoint
     * @param array $config
     * @param array $data
     *
     * @return string
     */
    private function addQueryStringsParamsToUrl($endpoint, array $config, array $data)
    {
        // Note that item['key'] corresponds to an endpoint property (in the header, querystring, etc.)
        //           item['value'] corresponds to a PM request variable or mustache expression
        //           item['type'] location of the property defined in 'key'. It can be BODY, PARAM (in the query string), HEADER

        $url = $endpoint['url'];
        // If url does not include the protocol and server name complete it with the local server
        if (substr($url, 0,1) === '/') {
            $url = url($url);
        }

        // If exists a query string in the call, add it to the URL
        if (array_key_exists('queryString', $config)) {
            $separator = strpos($url, '?') ? '&' : '?';
            $url .= $separator . $config['queryString'];
        }

        // Evaluate mustache expressions in URL
        $url = $this->evalMustache($url, $data);
        // Add params from datasource configuration
        $parsedUrl = $this->parseUrl($url);
        $query = [];
        parse_str($parsedUrl['query'] ?? '', $query);
        if (array_key_exists('params', $endpoint)) {
            foreach ($endpoint['params'] as $param) {
                $key = $this->evalMustache($param['key'], $data);
                $value = $this->evalMustache($param['value'], $data);
                if ($value !== '' || $param['required']) {
                    $query[$key] = $value;
                }
            }
        }
        $parsedUrl['query'] = http_build_query($query);
        $url = $this->unparseUrl($parsedUrl);
        return $url;
    }

    private function addHeaders($endpoint, array $config,  array $data)
    {
        $headers = ['Accept' => 'application/json'];

        // evaluate headers defined in the data source
        if (!array_key_exists('outboundConfig', $config)) {
            if (isset($endpoint['headers']) && is_array($endpoint['headers'])) {
                foreach ($endpoint['headers'] as $header) {
                    $headers[$this->getMustache()->render($header['key'], $data)] = $this->getMustache()->render($header['value'], $data);
                }
            }
            return $headers;
        }

        // mix data source and connector config headers
        $outboundConfig = $config['outboundConfig'];
        $dataSourceParams = array_filter($endpoint['headers'], function ($item) {
            return $item['required'] === true;
        });
        $configParams = array_filter($outboundConfig, function ($item) {
            return $item['type'] === 'HEADER';
        });

        foreach ($configParams as $cfgParam) {
            $existsInDataSourceParams = false;
            foreach ($dataSourceParams as &$param) {
                if ($param['key'] === $cfgParam['key']) {
                    $param['value'] = $cfgParam['value'];
                    $existsInDataSourceParams = true;
                }
            }
            if (!$existsInDataSourceParams) {
                array_push($dataSourceParams, [
                    'key' => $cfgParam['key'],
                    'value' => $cfgParam['value']
                ]);
            }
        }
        foreach ($dataSourceParams as $header) {
            $headers[$this->getMustache()->render($header['key'], $data)] = $this->getMustache()->render($header['value'], $data);
        }
        return $headers;
    }

    function isJson($str) {
        json_decode($str);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    private function addCollectionsRootObject($value)
    {
        preg_match_all('/\{\{(.*?)\}\}/m', $value, $matches, PREG_SET_ORDER, 0);
        if (count($matches) > 0) {
            $matchesWithNewVal = [];
            foreach ($matches as $match) {
                $val = $match[1];
                if (strpos($val, 'data.data') === false && strpos($val, 'data') === false) {
                    $match[] = 'data.data.' . trim($val);
                } else {
                    $match[] = trim($val);
                }
                $matchesWithNewVal[] = $match;
            }

            foreach ($matchesWithNewVal as $match) {
                $value = str_replace($match[0], '{{' . $match[2] . '}}', $value);
            }
        } else {
            if (strpos($value, 'data.data') === false && strpos($value, 'data') === false) {
                $value = 'data.data.' . trim($value);
            } else {
                $value = trim($value);
            }
        }
        return $value;
    }

    /**
     * Multibyte parse_url
     *
     * @param string $url
     * @return array
     */
    public function parseUrl($url)
    {
        $enc_url = preg_replace_callback(
            '%[^:/@?&=#]+%usD',
            function ($matches) {
                return urlencode($matches[0]);
            },
            $url
        );
        $parts = parse_url($enc_url);
        if ($parts === false) {
            throw new InvalidArgumentException('Malformed URL: ' . $url);
        }
        foreach ($parts as $name => $value) {
            $parts[$name] = urldecode($value);
        }
        return $parts;
    }

    /**
     * Unparse url array
     *
     * @param array $parsed_url
     * @return string
     */
    private function unparseUrl(array $parsed_url)
    {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }
}
