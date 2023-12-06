<?php


namespace Umino\Anime;


class Request
{
    protected static array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.2924.87 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
            'Connection: keep-alive',
            'Cache-Control: max-age=0',
            'Upgrade-Insecure-Requests: 1'
        ],
    ];

    public array $asyncRequests = [];

    private int $samples = 0;
    private static int $maxSamples = 5;
    private static float $sleep = 5.0;

    private array $result = [];

    private static function convertJson(string $json)
    {
        $result = json_decode($json, true);

        foreach ($result as $key => $value) {
            if (!is_null($value)) continue;
            unset($result[$key]);
        }

        return $result;
    }

    /**
     * @param string $url
     * @param bool $isJson
     * @return array|null
     */
    public static function getResponse(string $url, bool $isJson = true): ?array
    {
        $cacheKey = md5(serialize([$url,$isJson]));

        $result = Cache::get($cacheKey);

        if (isset($result)) return $result;

        $request = new Request();

        $request->addToAsyncQueue([$url], $isJson);

        $request->initAsyncRequest();

        $result = $request->getResult();

        $result = $result[array_key_first($result)];

        return Cache::set($result, $cacheKey);
    }

    /**
     * @param array $urls
     * @param bool $isJson
     * @return array
     */
    public static function getResponseAsync(array $urls, bool $isJson = true): array
    {
        $result = [];

        foreach ($urls as $key => $url)
        {
            $cacheKey = md5(serialize([$url,$isJson]));

            $cacheResult = Cache::get($cacheKey);

            if (!isset($cacheResult)) continue;

            $result[$key] = $cacheResult;

            unset($urls[$key]);
        }

        $request = new Request();

        $request->addToAsyncQueue($urls, $isJson);

        $request->initAsyncRequest();

        $requestResults = $request->getResult();

        foreach ($urls as $key => $url)
        {
            $cacheKey = md5(serialize([$url,$isJson]));

            $result[$key] = Cache::set($requestResults[$key], $cacheKey);
        }

        return $result;
    }

    /**
     * @param array $components
     * @param array $params
     * @return string
     */
    public static function buildURL(array $components, array $params = []): string
    {
        foreach ($components as &$component) {
            if (!is_array($component)) continue;
            $component = static::buildURL($component);
        }

        $result = preg_replace('/^http[a-z]{0,1}[:]{1}[\/]{1,}/', '', implode('/', $components));

        foreach ($params as &$param) {
            if (!is_array($param)) continue;
            $param = implode(',', $param);
        }

        if (!empty($params)) {
            $result .= '?' . http_build_query($params);
        }

        return 'https://' . str_replace('//', '/', $result);
    }

    public function addToAsyncQueue(array $urls, bool $isJson = true)
    {
        foreach ($urls as $key => $url) {
            $this->asyncRequests[$key] = [
                'URL' => $url,
                'JSON' => $isJson,
            ];
        }
    }

    public function initAsyncRequest(int $level = 20)
    {
        if (empty($this->asyncRequests)) return;

        $asyncRequests = array_chunk($this->asyncRequests, $level, true);

        $empty = true;

        foreach ($asyncRequests as $asyncRequestChunk) {

            $curls = curl_multi_init();

            foreach ($asyncRequestChunk as &$asyncRequest) {
                $resource = static::getHandle($asyncRequest['URL']);
                $asyncRequest['RESOURCE'] = $resource;
                curl_multi_add_handle($curls, $resource);
            } unset($asyncRequest);

            do {
                $status = curl_multi_exec($curls, $active);
                if ($active) curl_multi_select($curls);
            } while ($active && $status == CURLM_OK);

            foreach ($asyncRequestChunk as $key => $asyncRequest) {

                $code = curl_getinfo($asyncRequest['RESOURCE'], CURLINFO_HTTP_CODE);

                switch ($code) {

                    case 200:
                        unset($this->asyncRequests[$key]);
                        $empty = false;
                        $response = curl_multi_getcontent($asyncRequest['RESOURCE']);
                        $this->result[$key] = $asyncRequest['JSON'] && !empty($response) ? static::convertJson($response) : $response;
                        break;

                    case 404:
                        unset($this->asyncRequests[$key]);
                        $this->result[$key] = [];
                        break;

                    case 429:
                        break;

                    default:
                        unset($this->asyncRequests[$key]);
                        Logger::log([
                            'URL' => $asyncRequest['URL'],
                            'CODE' => $code,
                        ]);
                        break;
                }

                curl_multi_remove_handle($curls, $asyncRequest['RESOURCE']);
            }

            curl_multi_close($curls);
        }

        if ($this->asyncRequests && $this->samples < static::$maxSamples) {
            if ($empty) $this->samples++;
            usleep(static::$sleep * 1000 * 1000);
            $this->initAsyncRequest($level);
        } else if ($this->samples >= static::$maxSamples) {
            Logger::log([
                'URLS' => array_column($this->asyncRequests, 'URL'),
                'MESSAGE' => [
                    'The attempts are over!',
                    'Increase the timeout (now '.static::$sleep.' sec) or maximum number of retries (now max '.static::$maxSamples.' samples).',
                ],
            ]);
        }
    }

    private function getHandle(string $url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, static::$options);
        curl_setopt($ch, CURLOPT_URL, $url);
        return $ch;
    }

    public function getResult(): array
    {
        $result = $this->result;
        ksort($result);
        $this->result = [];
        return $result;
    }
}