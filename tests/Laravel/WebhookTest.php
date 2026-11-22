<?php

declare(strict_types=1);

namespace Reedapt\Tests\Laravel;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Reedapt\Exception\ReedaptException;
use Reedapt\Laravel\Events;
use Reedapt\Laravel\Http\Controllers\WebhookController;
use Reedapt\Laravel\Http\Middleware\VerifyWebhookSignature;

final class WebhookTest extends LaravelTestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('/reedapt/webhooks', WebhookController::class)->middleware('reedapt.webhook');
        Route::post('/verify-only', fn (\Illuminate\Http\Request $r) => response()->json($r->attributes->get(VerifyWebhookSignature::ATTRIBUTE)))->middleware('reedapt.webhook');
    }

    private const EVENT_CLASSES = [Events\WebhookReceived::class, Events\DubCompleted::class, Events\DubFailed::class, Events\DubChecked::class, Events\BatchCompleted::class, Events\WebhookTest::class];

    private static function body(string $type = 'dub.completed'): string
    {
        return json_encode(['id' => 'evt_1', 'type' => $type, 'created_at' => '2026-11-22T10:00:00Z', 'data' => ['dub' => ['id' => 'job_1']]], JSON_THROW_ON_ERROR);
    }

    /** @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function deliver(string $body, ?string $signature, string $uri = '/reedapt/webhooks')
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($signature !== null ? ['HTTP_REEDAPT_SIGNATURE' => $signature] : []);

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    #[Test]
    public function a_signed_delivery_is_accepted_and_dispatches_the_generic_and_the_typed_event(): void
    {
        Event::fake(self::EVENT_CLASSES);
        $body = self::body();
        $this->deliver($body, self::sign($body))->assertOk();

        Event::assertDispatched(Events\WebhookReceived::class, fn (Events\WebhookReceived $e): bool => $e->type() === 'dub.completed' && $e->id() === 'evt_1' && $e->data()['dub']['id'] === 'job_1');
        Event::assertDispatched(Events\DubCompleted::class, fn (Events\DubCompleted $e): bool => $e->payload['data']['dub']['id'] === 'job_1');
        Event::assertNotDispatched(Events\DubFailed::class);
    }

    #[Test]
    public function every_known_type_has_its_own_event_and_unknown_types_only_the_generic_one(): void
    {
        foreach (WebhookController::EVENTS as $type => $class) {
            Event::fake(self::EVENT_CLASSES);
            $body = self::body($type);
            $this->deliver($body, self::sign($body))->assertOk();
            Event::assertDispatched($class);
        }
        Event::fake(self::EVENT_CLASSES);
        $body = self::body('something.new');
        $this->deliver($body, self::sign($body))->assertOk();
        Event::assertDispatched(Events\WebhookReceived::class);
        Event::assertDispatchedTimes(Events\WebhookReceived::class, 1);
        foreach (WebhookController::EVENTS as $class) {
            Event::assertNotDispatched($class);
        }
    }

    #[Test]
    public function listeners_registered_the_normal_way_receive_the_event(): void
    {
        $seen = [];
        Event::listen(Events\DubChecked::class, function (Events\DubChecked $e) use (&$seen): void {
            $seen[] = $e->id();
        });
        $body = self::body('dub.checked');
        $this->deliver($body, self::sign($body))->assertOk();
        $this->assertSame(['evt_1'], $seen);
    }

    #[Test]
    public function a_bad_signature_is_a_400_and_dispatches_nothing(): void
    {
        Event::fake(self::EVENT_CLASSES);
        $body = self::body();
        $this->deliver($body, self::sign($body, null, 'whsec_other'))->assertStatus(400)->assertJsonStructure(['error']);
        $this->deliver($body.' ', self::sign($body))->assertStatus(400);
        $this->deliver($body, null)->assertStatus(400);
        $this->deliver($body, 'garbage')->assertStatus(400);
        $this->deliver($body, self::sign($body, time() - 3600))->assertStatus(400);
        Event::assertNotDispatched(Events\WebhookReceived::class);
    }

    #[Test]
    public function the_tolerance_comes_from_config(): void
    {
        $this->app['config']->set('reedapt.webhook.tolerance', 7200);
        $body = self::body();
        $this->deliver($body, self::sign($body, time() - 3600))->assertOk();
    }

    #[Test]
    public function the_middleware_alone_puts_the_parsed_event_on_the_request(): void
    {
        $body = self::body();
        $this->deliver($body, self::sign($body), '/verify-only')->assertOk()->assertJsonPath('type', 'dub.completed');
    }

    #[Test]
    public function a_missing_secret_is_a_loud_setup_error_not_a_400(): void
    {
        $this->app['config']->set('reedapt.webhook.secret', null);
        $this->withoutExceptionHandling();
        $this->expectException(ReedaptException::class);
        $body = self::body();
        $this->deliver($body, self::sign($body));
    }

    #[Test]
    public function the_controller_without_the_middleware_refuses_to_trust_the_request(): void
    {
        Route::post('/unprotected', WebhookController::class);
        $this->deliver(self::body(), null, '/unprotected')->assertStatus(500);
    }
}
