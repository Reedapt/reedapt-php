# Reedapt PHP library

The official PHP library for the [Reedapt API](https://docs.reedapt.com), with first-class Laravel support. Upload video or audio, dub it into other languages in each speaker's own voice, and get the files back.

- [Plain PHP](#plain-php)
- [Laravel](#laravel)

## Install

```bash
composer require reedapt/reedapt-php
```

PHP 8.1 or later. The library talks to the API through any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and [PSR-17](https://www.php-fig.org/psr/psr-17/) factories, found automatically with [php-http/discovery](https://docs.php-http.org/en/latest/discovery.html). If your project already has Guzzle or Symfony HttpClient, nothing else is needed. If it has neither, install one:

```bash
composer require guzzlehttp/guzzle
# or: composer require symfony/http-client nyholm/psr7
```

---

# Plain PHP

## Quickstart

Make a key under **Developers** in [Reedapt](https://dub.reedapt.com/developers) and keep it in `REEDAPT_API_KEY`. Test keys are free and dub the first 60 seconds with a watermark.

```php
<?php

require __DIR__.'/vendor/autoload.php';

$client = new Reedapt\Client(getenv('REEDAPT_API_KEY'));

['dubs' => $dubs, 'refused' => $refused] = $client->dub('./interview.mp4', [
    'source_language' => 'en',
    'target_languages' => ['fr', 'es'],
]);

foreach ($dubs as $started) {
    $dub = $client->dubs->waitUntilDone($started['id']);
    if ($dub['status'] === 'completed') {
        $files = $client->dubs->files($dub['id'])['files'];
        echo $dub['target_language'], ' ', $files[0]['url'], "\n";
    } else {
        echo $dub['target_language'], ' failed: ', $dub['error']['message'] ?? '', "\n";
    }
}
```

`dub()` takes a file path, an open stream (with `filename`), or a public `https://` link that Reedapt downloads itself. In production, use [webhooks](#webhooks) instead of `waitUntilDone`.

Responses are plain arrays, exactly as the API sends them. Their shapes are documented for PHPStan and your IDE in [`Reedapt\Types`](src/Types.php), and a field Reedapt adds later is already in the array. (Readonly objects would need a release for every new field.)

## Uploads

`uploads->uploadFile()` sends the file straight to storage in parts, signs more links as it needs them, and retries parts that fail. Each part is streamed from the file as it is sent, so memory stays flat for a multi-gigabyte film. Parts go one after another, because PHP requests are synchronous.

```php
$upload = $client->uploads->uploadFile('./film.mov', [
    'on_progress' => fn (int $done, int $total) => printf("%d%%\n", 100 * $done / $total),
]);

$created = $client->dubs->create([
    'upload_id' => $upload['id'],
    'source_language' => 'en',
    'target_languages' => ['yo'],
    'metadata' => ['order_id' => '1042'],
    'instructions' => "Keep the speaker's name, Adaeze, as it is. Warm, informal tone.", // paid plans
]);
```

A stream works too, if it is seekable. Give it a `filename` unless it is a plain file:

```php
$client->uploads->uploadFile($stream, ['filename' => 'sermon.mp3']);
```

For a file that's already online:

```php
$upload = $client->uploads->fromUrl('https://example.com/media/talk.mp4');
```

## Dubs

```php
$client->dubs->retrieve('job_3f9a2c7e1b4d');
$client->dubs->list(['limit' => 20]);
foreach ($client->dubs->listAll() as $dub) {
    echo $dub['id'], ' ', $dub['status'], "\n";
}
$client->dubs->files('job_3f9a2c7e1b4d');      // links last an hour
$client->dubs->cancel('job_3f9a2c7e1b4d');     // minutes are returned
$client->dubs->transcript('job_3f9a2c7e1b4d'); // every line, with timing, speaker and translation
$client->dubs->subtitles('job_3f9a2c7e1b4d', ['format' => 'vtt']); // SRT (default) or WebVTT text
$client->dubs->quality('job_3f9a2c7e1b4d');    // Reedapt's check: lines fixed and lines left to review

$client->languages->list();                    // ['target_languages' => [...]]
$client->usage->retrieve();                    // plan, key mode and minutes left
$client->usage->report(['month' => '2026-10']); // minutes and dubs per key and per language
```

## Ask Reedapt for changes

Early access: this is released to some paid accounts first, and other accounts get a `403` with the code `NOT_AVAILABLE`. Describe a change in plain words and Reedapt either answers, proposes a change, or makes it. With `autonomy` `ask` (the default) nothing changes until you say so:

```php
$run = $client->dubs->requests->create('job_3f9a2c7e1b4d', [
    'text' => 'Is line 3 natural? If not, make it sound calmer.',
    'autonomy' => 'ask',
]);
echo $run['reply'];

if ($run['status'] === 'needs_input') {
    echo $run['proposal']['summary'], ' ', $run['proposal']['lines']; // lines = how many it would change
    $run = $client->runs->answer($run['id'], ['choice' => 'apply']);  // or 'decline'
}
// status is done, applied, declined or failed; applied_lines counts the lines changed.
$client->runs->retrieve($run['id']);
```

You can also read and edit lines yourself. Edited lines are voiced again in the background:

```php
$lines = $client->dubs->lines->list('job_3f9a2c7e1b4d')['lines'];
$client->dubs->lines->edit('job_3f9a2c7e1b4d', [
    'lines' => [['line' => 3, 'text' => 'Bienvenue chez nous.', 'delivery_style' => 'warm']],
]); // ['dub_id' => ..., 'lines' => [3], 'status' => 'revoicing']
```

A request counts against your plan's allowance for these requests; when it's used up you get `AGENT_ALLOWANCE_USED` (429) until it renews.

## Webhooks

Add an endpoint under **Developers**, or with `$client->webhookEndpoints->create(['url' => ...])`, and keep its `whsec_` secret. `$client->webhookEndpoints->resendDelivery($endpointId, $deliveryId)` sends a past delivery again. Check every delivery against the raw body, exactly as it arrived:

```php
use Reedapt\Exception\WebhookVerificationException;
use Reedapt\Webhook;

$payload = file_get_contents('php://input');
$header = $_SERVER['HTTP_REEDAPT_SIGNATURE'] ?? null;

try {
    $event = Webhook::constructEvent($payload, $header, getenv('REEDAPT_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'dub.completed') {
    // $event['data']['dub'] is the finished dub. Queue the work, then answer quickly.
} elseif ($event['type'] === 'dub.checked') {
    // A few minutes later, on plans where Reedapt checks dubs:
    // $event['data']['quality'] lists the lines it fixed and the ones left to review.
} // Ignore types you don't know; every endpoint gets every event.

http_response_code(200);
```

`Webhook::verify($payload, $header, $secret)` only checks the signature and throws if it fails. Signatures older than 300 seconds are refused; pass a fourth argument to change that. An event can arrive more than once, so skip any `$event['id']` you've already handled.

## Errors

Failed requests throw a subclass of `Reedapt\Exception\ApiException` with the HTTP `status`, a stable `errorCode`, a plain-English message and the `requestId` to quote to support:

```php
use Reedapt\Exception\ApiException;
use Reedapt\Exception\RateLimitException;

try {
    $client->dubs->create(['upload_id' => $id, 'source_language' => 'en', 'target_languages' => ['fr']]);
} catch (RateLimitException $e) {
    if ($e->errorCode === 'TOO_MANY_DUBS_RUNNING') {
        // Start more once some finish.
    }
} catch (ApiException $e) {
    error_log("{$e->status} {$e->errorCode} {$e->getMessage()} {$e->requestId}");
}
```

| Class (in `Reedapt\Exception`) | When |
| --- | --- |
| `ReedaptException` | Base class of everything below. |
| `ApiException` | The API answered with an error. |
| `AuthenticationException` | 401: the key is missing, wrong or revoked. |
| `NotFoundException` | 404 |
| `ConflictException` | 409: the object isn't in a state that allows this. |
| `InvalidRequestException` | 400, 411, 413, 422. `$fields` lists invalid fields. |
| `RateLimitException` | 429. `retryAfter()` is in seconds. |
| `ServerException` | 5xx |
| `ConnectionException`, `TimeoutException` | No answer arrived. |
| `UploadException` | A part couldn't be sent to storage, or the file couldn't be read. |
| `WaitException` | A `waitUntil…` helper ran out of time, or the upload failed. |
| `WebhookVerificationException` | A delivery's signature didn't check out. |

The [error codes](https://docs.reedapt.com/errors) are listed in the docs.

## Retries and repeated requests

Network failures, `500`, `502`, `503`, `504` and per-minute rate limits are retried twice, with backoff, honouring `Retry-After`. Creating uploads and dubs always sends an `Idempotency-Key`, so a retry never makes a second dub. Pass your own to make a repeat safe across restarts:

```php
$client->dubs->create($params, ['idempotency_key' => "order-{$order->id}"]);
```

## Configuration

```php
$client = new Reedapt\Client($apiKey, [
    'base_url' => 'https://api.reedapt.com/v1', // or REEDAPT_BASE_URL
    'timeout' => 60,                             // seconds; see below
    'max_retries' => 2,
    'default_headers' => ['X-Request-Source' => 'billing'],
    'http_client' => $psr18Client,               // any PSR-18 client
    'request_factory' => $psr17,                 // PSR-17, found automatically
    'stream_factory' => $psr17,
]);

$client->dubs->retrieve($id, ['max_retries' => 0, 'headers' => ['X-Trace' => 'abc']]);
```

PSR-18 has no timeout setting. `timeout` is applied when the library builds a Guzzle or Symfony client itself. If you pass `http_client`, set the timeout on that client; give it a long one (ten minutes or more) if it also uploads large parts.

Keep keys on your server. Never put one in a web page or an app people can download.

---

# Laravel

Laravel 10, 11 and 12. The package registers itself through package discovery.

## Setup

```bash
composer require reedapt/reedapt-php
php artisan vendor:publish --tag=reedapt-config   # optional
```

Add to `.env`:

```dotenv
REEDAPT_API_KEY=rk_test_...
REEDAPT_WEBHOOK_SECRET=whsec_...
# Optional: REEDAPT_BASE_URL, REEDAPT_TIMEOUT, REEDAPT_MAX_RETRIES, REEDAPT_WEBHOOK_TOLERANCE
```

`config/reedapt.php` reads those. The client is bound as a singleton, so inject it or use the facade:

```php
use Reedapt\Client;
use Reedapt\Laravel\Facades\Reedapt;

class DubController
{
    public function store(Client $reedapt)
    {
        $created = $reedapt->dub(storage_path('app/interview.mp4'), [
            'source_language' => 'en',
            'target_languages' => ['fr', 'es'],
            'idempotency_key' => "order-{$order->id}",
        ]);
    }
}

// The facade exposes each resource as a method:
Reedapt::dubs()->retrieve($id);
Reedapt::usage()->report(['month' => '2026-10']);
Reedapt::dub($path, [...]);
```

Everything in [Plain PHP](#plain-php) works the same.

## Receiving webhooks

Register a route behind the `reedapt.webhook` middleware. It checks the `Reedapt-Signature` header against the raw body with `REEDAPT_WEBHOOK_SECRET` and answers `400` when it doesn't match. The package's controller then dispatches Laravel events and answers `200`:

```php
// routes/web.php
use Reedapt\Laravel\Http\Controllers\WebhookController;

Route::post('/reedapt/webhooks', WebhookController::class)->middleware('reedapt.webhook');
```

Exempt the route from CSRF checks (webhooks have no session). In Laravel 11 and 12, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['reedapt/webhooks']);
})
```

(Or put the route in `routes/api.php`, which has no CSRF check.) In Laravel 10, add the path to `$except` in `VerifyCsrfToken`.

Add the URL, `https://your-app.test/reedapt/webhooks`, as an endpoint under **Developers** and copy its `whsec_` secret into `.env`.

Each delivery dispatches `Reedapt\Laravel\Events\WebhookReceived` and, for the types Reedapt documents, a more specific event that extends it:

| Webhook type | Event |
| --- | --- |
| any | `WebhookReceived` |
| `dub.completed` | `DubCompleted` |
| `dub.failed` | `DubFailed` |
| `dub.checked` | `DubChecked` |
| `batch.completed` | `BatchCompleted` |
| `webhook.test` | `WebhookTest` |

An event has `id()`, `type()`, `data()` and `$payload` (the whole event as Reedapt sent it). Both the generic and the specific event are dispatched, so listen to one of them, not both:

```php
// app/Listeners/StoreFinishedDub.php
use Reedapt\Laravel\Events\DubCompleted;

class StoreFinishedDub implements ShouldQueue
{
    public function handle(DubCompleted $event): void
    {
        $dub = $event->data()['dub'];

        if (Delivery::where('event_id', $event->id())->exists()) {
            return; // An event can arrive more than once.
        }
        // Download $dub['id']'s files, mark the order as done...
    }
}
```

Laravel discovers listeners in `app/Listeners` by their `handle` type hint. Types Reedapt adds later only dispatch `WebhookReceived`, so a listener for it can handle them without a package update.

To handle the delivery yourself, use the middleware alone. The parsed event is on the request:

```php
Route::post('/reedapt/webhooks', function (Request $request) {
    $event = $request->attributes->get('reedapt.event');
    // ...
    return response()->noContent();
})->middleware('reedapt.webhook');
```

If `REEDAPT_WEBHOOK_SECRET` is missing the middleware throws, instead of answering `400`, so a setup mistake shows up in your logs.

## Testing your code

Fake the HTTP client by binding a client built on your own PSR-18 mock:

```php
$this->app->instance(Reedapt\Client::class, new Reedapt\Client('rk_test_x', [
    'http_client' => $mock, // any PSR-18 client, such as one from php-http/mock-client
]));
```

To test your listeners, use `Event::fake([DubCompleted::class])` and post a signed body to your route, or dispatch `new DubCompleted($payload)` directly.

---

## Development

```bash
composer install
composer test      # PHPUnit, with Orchestra Testbench for the Laravel parts
composer analyse   # PHPStan, level 8
composer format    # Pint
```

## Links

- [Documentation](https://docs.reedapt.com)
- [API reference](https://docs.reedapt.com/api-reference/introduction)
- [Status](https://status.reedapt.com)
- Support: support@reedapt.com

MIT licensed.
