<?php

namespace KyPHP;

use Exception;

class KyPHP
{
    private string $url;
    private string $method = 'GET';
    private array $headers = [];
    private ?string $body = null;
    private string $queryString = '';
    private int $retry = 0;

    private $beforeHook = null;
    private $afterHook = null;

    // Shared persistent curl handle for HTTP/2 reuse
    private static $persistentHandle = null;

    // Async batch
    private static array $batch = [];

    public function __construct() {}

    // ----------------------
    // Chainable API
    // ----------------------
    public function get(string $url): self {
        $this->method = 'GET';
        $this->url = $url;
        return $this;
    }

    public function post(string $url): self {
        $this->method = 'POST';
        $this->url = $url;
        return $this;
    }

    public function header(string $key, string $value): self {
        $this->headers[$key] = $value;
        return $this;
    }

    public function query(array $q): self {
        $pairs = [];
        foreach ($q as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
        }
        $this->queryString = implode('&', $pairs);
        return $this;
    }

    public function json(mixed $data): self {
        $this->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->header('Content-Type', 'application/json');
        return $this;
    }

    public function retry(int $n): self {
        $this->retry = max(0, $n);
        return $this;
    }

    public function beforeRequest(callable $fn): self {
        $this->beforeHook = $fn;
        return $this;
    }

    public function afterResponse(callable $fn): self {
        $this->afterHook = $fn;
        return $this;
    }

    // ----------------------
    // Internal helper
    // ----------------------
    private function buildCurl(string $url) {
        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $this->method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TCP_FASTOPEN   => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0, // HTTP/2 for persistent connections
        ];

        if ($this->headers) {
            $opts[CURLOPT_HTTPHEADER] = array_map(
                fn($k, $v) => "$k: $v",
                array_keys($this->headers),
                $this->headers
            );
        }

        if ($this->body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $this->body;
        }

        curl_setopt_array($ch, $opts);
        return $ch;
    }

    // ----------------------
    // Single request with retries
    // ----------------------
    public function send(): array {
        $url = $this->queryString ? "{$this->url}?{$this->queryString}" : $this->url;
        $attempts = 0;
        $maxAttempts = $this->retry + 1;

        while ($attempts < $maxAttempts) {
            $attempts++;

            if ($this->beforeHook) ($this->beforeHook)($this);

            $ch = $this->buildCurl($url);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            $response = ['status' => $status, 'body' => $body];

            if (!$error && $status < 500) {
                if ($this->afterHook) ($this->afterHook)($response);
                return $response;
            }
        }

        throw new Exception("Request failed after {$this->retry} retries");
    }

    public function sendJson(): mixed {
        $res = $this->send();
        return json_decode($res['body'], true);
    }

    // ----------------------
    // Async batch with parallel retries
    // ----------------------
    public function addToBatch(): self {
        self::$batch[] = $this;
        return $this;
    }

    public static function sendBatch(): array {
        if (!self::$batch) return [];

        $responses = [];
        $pending = self::$batch;

        // Loop until all requests succeed or max retries exhausted
        while ($pending) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($pending as $i => $req) {
                if ($req->beforeHook) ($req->beforeHook)($req);

                $url = $req->queryString ? "{$req->url}?{$req->queryString}" : $req->url;
                $ch = $req->buildCurl($url);

                // Track retries
                $req->_attempts ??= 0;
                $req->_attempts++;

                curl_multi_add_handle($multi, $ch);
                $handles[$i] = $ch;
            }

            // Execute all handles in parallel
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 0.1);
            } while ($running && $status === CURLM_OK);

            $nextPending = [];
            foreach ($handles as $i => $ch) {
                $req = $pending[$i];
                $body = curl_multi_getcontent($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);

                $response = ['status' => $status, 'body' => $body];

                if ($req->afterHook) ($req->afterHook)($response);

                if ($status >= 500 && $req->_attempts <= $req->retry + 1) {
                    $nextPending[] = $req; // Retry failed request in next batch loop
                } else {
                    $responses[] = $response;
                }
            }

            curl_multi_close($multi);
            $pending = $nextPending;
        }

        self::$batch = [];
        return $responses;
    }

    public static function sendBatchJson(): array {
        $res = self::sendBatch();
        foreach ($res as &$r) {
            $r['body'] = json_decode($r['body'], true);
        }
        return $res;
    }
}
