<?php

namespace KyPHP;

use Exception;

class KyPHP
{
    private string $url;
    private string $method = 'GET';
    private string $headersRaw = '';
    private ?string $body = null;
    private string $queryString = '';
    private int $retry = 0;
    private $beforeHook = null;
    private $afterHook = null;

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
        $this->headersRaw .= "$key: $value\r\n";
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
        $this->body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $this->header('Content-Type', 'application/json');
        return $this;
    }

    public function retry(int $n): self {
        $this->retry = $n;
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

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $this->method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TCP_FASTOPEN   => true,
        ]);

        if ($this->headersRaw) {
            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                explode("\r\n", rtrim($this->headersRaw))
            );
        }

        if ($this->body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->body);
        }

        return $ch;
    }

    // ----------------------
    // Send single request
    // ----------------------
    public function send(): array {
        $attempts = $this->retry + 1;
        $url = $this->queryString
            ? "{$this->url}?{$this->queryString}"
            : $this->url;

        while ($attempts-- > 0) {
            if ($this->beforeHook) {
                ($this->beforeHook)($this);
            }

            $ch = $this->buildCurl($url);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $response = [
                'status' => $status,
                'body'   => $body
            ];

            if ($this->afterHook) {
                ($this->afterHook)($response);
            }

            if (!$error) {
                return $response;
            }
        }

        throw new Exception(
            "Request failed after {$this->retry} retries"
        );
    }

    public function sendJson(): mixed {
        $res = $this->send();
        return json_decode($res['body'], true);
    }

    // ----------------------
    // Async batch
    // ----------------------
    public function addToBatch(): self {
        self::$batch[] = $this;
        return $this;
    }

    public static function sendBatch(): array {
        if (!self::$batch) return [];

        $multi = curl_multi_init();
        $handles = [];
        $responses = [];

        foreach (self::$batch as $i => $req) {
            if ($req->beforeHook) {
                ($req->beforeHook)($req);
            }

            $url = $req->queryString
                ? "{$req->url}?{$req->queryString}"
                : $req->url;

            $ch = $req->buildCurl($url);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            $response = [
                'status' => $status,
                'body'   => $body
            ];

            $req = self::$batch[$i];
            if ($req->afterHook) {
                ($req->afterHook)($response);
            }

            $responses[] = $response;
        }

        curl_multi_close($multi);
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
