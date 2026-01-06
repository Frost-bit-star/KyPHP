### KyPHP

KyPHP is a minimal, fast, and chainable PHP HTTP client inspired by Ky
 from the JavaScript ecosystem.

**It supports:**

- Chainable API for GET/POST requests

- Automatic JSON handling (sendJson())

- Query string builder

- Request hooks (beforeRequest / afterResponse)

- Retry logic

- Async batch requests (sendBatch())

- Clean error handling

- All native PHP, no external dependencies required (just cURL).

## Installation

**Install via Composer:**
```
composer require frost-bit-star/kyphp
```

**Autoload in your project:**
```
require 'vendor/autoload.php';

use KyPHP\KyPHP;
```
## Usage
Basic GET request
```
$ky = new KyPHP();

$response = $ky->get('https://api.example.com/data')
               ->header('Accept', 'application/json')
               ->send();

print_r($response);

GET with query parameters
$response = $ky->get('https://api.example.com/users')
               ->query(['page' => 1, 'limit' => 10])
               ->sendJson();

print_r($response);

POST JSON request
$response = $ky->post('https://api.example.com/users')
               ->json(['name' => 'John', 'email' => 'john@example.com'])
               ->sendJson();

print_r($response);

Retry logic
$response = $ky->get('https://unstable-api.com/data')
               ->retry(3) // Retry 3 times if fails
               ->sendJson();
```
## Hooks
```
$ky->beforeRequest(function($request) {
    echo "Sending request to {$request->url}\n";
})->afterResponse(function($response) {
    echo "Received HTTP status {$response['status']}\n";
});

Async Batch Requests
$req1 = (new KyPHP())->get('https://api.example.com/data1')->addToBatch();
$req2 = (new KyPHP())->get('https://api.example.com/data2')->addToBatch();

$responses = KyPHP::sendBatchJson();

print_r($responses);
```
## Features

**Chainable API: get()->post()->header()->query()->json()**

- Automatic JSON decoding: sendJson()

- Query builder: Simplify GET query params

**Hooks: Before and after request hooks for logging or modification**

- Retry: Automatic retries for failed requests

- Async batch requests: Send multiple requests concurrently

- Minimal and fast: Pure PHP using cURL

**Example Project Structure**
```
kyphp/
├── composer.json
├── src/
│   └── KyPHP.php
└── README.md
```
License

MIT © morgan miller
