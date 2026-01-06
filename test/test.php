<?php

require __DIR__ . '/../vendor/autoload.php';

use KyPHP\KyPHP;

echo "==== KyPHP FULL TEST START ====\n\n";

/**
 * Test 1: Simple GET
 */
echo "[1] GET request\n";

$res = (new KyPHP())
    ->get('https://httpbin.org/get')
    ->sendJson();

assert(isset($res['url']));
echo "✔ GET OK\n\n";

/**
 * Test 2: Query params
 */
echo "[2] Query params\n";

$res = (new KyPHP())
    ->get('https://httpbin.org/get')
    ->query(['a' => 1, 'b' => 'test'])
    ->sendJson();

assert($res['args']['a'] == '1');
assert($res['args']['b'] == 'test');
echo "✔ Query OK\n\n";

/**
 * Test 3: Headers
 */
echo "[3] Custom headers\n";

$res = (new KyPHP())
    ->get('https://httpbin.org/headers')
    ->header('X-Test', 'KyPHP')
    ->sendJson();

assert($res['headers']['X-Test'] === 'KyPHP');
echo "✔ Headers OK\n\n";

/**
 * Test 4: POST JSON
 */
echo "[4] POST JSON body\n";

$res = (new KyPHP())
    ->post('https://httpbin.org/post')
    ->json(['name' => 'KyPHP', 'speed' => 'fast'])
    ->sendJson();

assert($res['json']['name'] === 'KyPHP');
assert($res['json']['speed'] === 'fast');
echo "✔ JSON POST OK\n\n";

/**
 * Test 5: Hooks
 */
echo "[5] Hooks\n";

$beforeCalled = false;
$afterCalled  = false;

$res = (new KyPHP())
    ->get('https://httpbin.org/get')
    ->beforeRequest(function () use (&$beforeCalled) {
        $beforeCalled = true;
    })
    ->afterResponse(function () use (&$afterCalled) {
        $afterCalled = true;
    })
    ->send();

assert($beforeCalled === true);
assert($afterCalled === true);
echo "✔ Hooks OK\n\n";

/**
 * Test 6: Retry logic (intentional failure first)
 */
echo "[6] Retry logic\n";

$attempts = 0;

try {
    (new KyPHP())
        ->get('https://httpbin.org/status/500')
        ->retry(2)
        ->afterResponse(function () use (&$attempts) {
            $attempts++;
        })
        ->send();
} catch (Exception $e) {}

assert($attempts === 3);
echo "✔ Retry OK\n\n";

/**
 * Test 7: Async batch
 */
echo "[7] Async batch\n";

(new KyPHP())->get('https://httpbin.org/get')->addToBatch();
(new KyPHP())->get('https://httpbin.org/uuid')->addToBatch();
(new KyPHP())->get('https://httpbin.org/ip')->addToBatch();

$responses = KyPHP::sendBatch();

assert(count($responses) === 3);
echo "✔ Async batch OK\n\n";

/**
 * Test 8: Async batch JSON
 */
echo "[8] Async batch JSON\n";

(new KyPHP())->get('https://httpbin.org/get')->addToBatch();
(new KyPHP())->get('https://httpbin.org/uuid')->addToBatch();

$responses = KyPHP::sendBatchJson();

assert(is_array($responses[0]['body']));
assert(is_array($responses[1]['body']));
echo "✔ Async batch JSON OK\n\n";

/**
 * Test 9: Chainability
 */
echo "[9] Chainability\n";

$res = (new KyPHP())
    ->get('https://httpbin.org/get')
    ->header('X-One', '1')
    ->query(['chain' => 'yes'])
    ->retry(1)
    ->sendJson();

assert($res['args']['chain'] === 'yes');
echo "✔ Chainability OK\n\n";

echo "==== ALL TESTS PASSED ✅ ====\n";
