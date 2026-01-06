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
    private bool $debug = false;

    private $beforeHook = null;
    private $afterHook = null;

    // Async batch
    private static array $batch = [];

    public function __construct() {}

    // ----------------------
    // Chainable API
    // ----------------------
    public function get(string $url): self { $this->method='GET'; $this->url=$url; return $this; }
    public function post(string $url): self { $this->method='POST'; $this->url=$url; return $this; }
    public function header(string $k,string $v): self { $this->headers[$k]=$v; return $this; }
    public function query(array $q): self { 
        $pairs=[];
        foreach($q as $k=>$v) $pairs[]=rawurlencode($k).'='.rawurlencode((string)$v);
        $this->queryString=implode('&',$pairs);
        return $this;
    }
    public function json(mixed $data): self {
        $this->body=json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->header('Content-Type','application/json');
        return $this;
    }
    public function retry(int $n): self { $this->retry=max(0,$n); return $this; }
    public function debug(bool $flag=true): self { $this->debug=$flag; return $this; }
    public function beforeRequest(callable $fn): self { $this->beforeHook=$fn; return $this; }
    public function afterResponse(callable $fn): self { $this->afterHook=$fn; return $this; }

    // ----------------------
    // Build cURL handle
    // ----------------------
    private function buildCurl(string $url){
        $ch=curl_init($url);
        $opts=[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>$this->method,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_MAXREDIRS=>3,
            CURLOPT_TCP_FASTOPEN=>true,
            CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_2_0,
        ];
        if($this->headers){
            $opts[CURLOPT_HTTPHEADER]=array_map(fn($k,$v)=>"$k: $v", array_keys($this->headers), $this->headers);
        }
        if($this->body!==null) $opts[CURLOPT_POSTFIELDS]=$this->body;
        curl_setopt_array($ch,$opts);
        return $ch;
    }

    // ----------------------
    // Single request
    // ----------------------
    public function send(): array{
        $url=$this->queryString ? "{$this->url}?{$this->queryString}" : $this->url;

        for($attempt=0;$attempt<=$this->retry;$attempt++){
            if(is_callable($this->beforeHook)) ($this->beforeHook)($this);

            $ch=$this->buildCurl($url);
            $body=curl_exec($ch);
            $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $error=curl_error($ch);

            if(PHP_VERSION_ID<80500) curl_close($ch);

            if($this->debug){
                echo "[KyPHP DEBUG] Attempt: $attempt, URL: $url, Status: $status, Error: $error\n";
            }

            if(!$error && $status<500){
                $res=['status'=>$status,'body'=>$body];
                if(is_callable($this->afterHook)) ($this->afterHook)($res);
                return $res;
            }
        }

        throw new Exception("Request failed after {$this->retry} retries for URL: {$url}");
    }

    public function sendJson(): mixed{
        $res=$this->send();
        $json=json_decode($res['body'],true);
        if(json_last_error()!==JSON_ERROR_NONE){
            throw new Exception("Failed to decode JSON: ".json_last_error_msg());
        }
        return $json;
    }

    // ----------------------
    // Batch requests
    // ----------------------
    public function addToBatch(): self{
        self::$batch[]=$this;
        return $this;
    }

    public static function sendBatch(): array{
        if(!self::$batch) return [];

        $responses=[];
        $pending=self::$batch;

        while($pending){
            $multi=curl_multi_init();
            $handles=[];

            foreach($pending as $i=>$req){
                if(is_callable($req->beforeHook)) ($req->beforeHook)($req);
                $url=$req->queryString ? "{$req->url}?{$req->queryString}" : $req->url;
                $ch=$req->buildCurl($url);
                curl_multi_add_handle($multi,$ch);
                $handles[$i]=['ch'=>$ch,'req'=>$req,'attempt'=>0];
            }

            do{
                $status=curl_multi_exec($multi,$running);
                if($running) curl_multi_select($multi,0.01);
            }while($running && $status===CURLM_OK);

            $nextPending=[];
            foreach($handles as $h){
                $ch=$h['ch'];
                $req=$h['req'];
                $body=curl_multi_getcontent($ch);
                $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);

                if(PHP_VERSION_ID<80500){
                    curl_multi_remove_handle($multi,$ch);
                    curl_close($ch);
                }

                $res=['status'=>$status,'body'=>$body];
                if(is_callable($req->afterHook)) ($req->afterHook)($res);

                if($status>=500 && $h['attempt']<=$req->retry){
                    $nextPending[]=$req;
                }else{
                    $responses[]=$res;
                }
            }

            curl_multi_close($multi);
            $pending=$nextPending;
        }

        self::$batch=[];
        return $responses;
    }

    public static function sendBatchJson(): array{
        $res=self::sendBatch();
        foreach($res as &$r) $r['body']=json_decode($r['body'],true);
        return $res;
    }
}
