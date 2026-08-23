<?php

namespace Tests\Unit\Notification;

use App\Services\Notification\NotificationEvent;
use App\Services\Notification\NotificationServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceClientTest extends TestCase
{
    protected NotificationServiceClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new NotificationServiceClient();

        config()->set('services.notification.url', 'http://notification-service:4002');
        config()->set('services.notification.api_key', 'test-api-key');
        config()->set('services.notification.timeout', 5);
    }

    private function event(): NotificationEvent
    {
        return new NotificationEvent(
            eventId: 'event-123',
            eventType: 'REMITTANCE_CREATED',
            userId: 'user-123',
            source: 'household-service',
            timestamp: '2026-08-22T12:00:00+00:00',
            data: [
                'remittanceId' => 'remittance-123',
                'householdId' => 'household-123',
                'amountSent' => 100,
                'amountReceived' => 60000,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Successful delivery
    |--------------------------------------------------------------------------
    */

    public function test_sends_notification_event_to_configured_url(): void
    {
        Http::fake();

        $event = $this->event();

        $this->client->send($event);

        Http::assertSent(function ($request) use ($event): bool {
            return $request->url() === 'http://notification-service:4002'
                && $request->method() === 'POST'
                && $request->data() === [
                    'eventId' => $event->eventId,
                    'eventType' => $event->eventType,
                    'userId' => $event->userId,
                    'source' => $event->source,
                    'timestamp' => $event->timestamp,
                    'data' => $event->data,
                ];
        });
    }

    public function test_sends_correct_event_payload(): void
    {
        Http::fake();

        $event = $this->event();

        $this->client->send($event);

        Http::assertSent(function ($request) use ($event): bool {
            $data = $request->data();

            return $data['eventId'] === 'event-123'
                && $data['eventType'] === 'REMITTANCE_CREATED'
                && $data['userId'] === 'user-123'
                && $data['source'] === 'household-service'
                && $data['timestamp'] === '2026-08-22T12:00:00+00:00'
                && $data['data']['remittanceId'] === 'remittance-123'
                && $data['data']['householdId'] === 'household-123'
                && $data['data']['amountSent'] === 100
                && $data['data']['amountReceived'] === 60000;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_sends_service_api_key_when_configured(): void
    {
        Http::fake();

        $this->client->send($this->event());

        Http::assertSent(function ($request): bool {
            return $request->header('X-Service-API-Key') === ['test-api-key'];
        });
    }

    public function test_does_not_send_service_api_key_when_not_configured(): void
    {
        config()->set('services.notification.api_key', '');

        Http::fake();

        $this->client->send($this->event());

        Http::assertSent(function ($request): bool {
            return ! $request->hasHeader('X-Service-API-Key');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Request format
    |--------------------------------------------------------------------------
    */

    public function test_request_accepts_json(): void
    {
        Http::fake();

        $this->client->send($this->event());

        Http::assertSent(function ($request): bool {
            return in_array(
                'application/json',
                $request->header('Accept'),
                true
            );
        });
    }

    public function test_request_sends_json_content_type(): void
    {
        Http::fake();

        $this->client->send($this->event());

        Http::assertSent(function ($request): bool {
            return in_array(
                'application/json',
                $request->header('Content-Type'),
                true
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    public function test_uses_configured_notification_url(): void
    {
        config()->set(
            'services.notification.url',
            'http://custom-notification-service/events'
        );

        Http::fake();

        $this->client->send($this->event());

        Http::assertSent(function ($request): bool {
            return $request->url() ===
                'http://custom-notification-service/events';
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Missing configuration
    |--------------------------------------------------------------------------
    */

    public function test_does_not_make_request_when_notification_url_is_empty(): void
    {
        config()->set('services.notification.url', '');

        Http::fake();

        $this->client->send($this->event());

        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | Failure isolation
    |--------------------------------------------------------------------------
    */

    public function test_connection_exception_does_not_escape_client(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Notification service unavailable.');
        });

        $this->expectNotToPerformAssertions();

        $this->client->send($this->event());
    }

    public function test_unexpected_exception_does_not_escape_client(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('Unexpected notification failure.');
        });

        $this->expectNotToPerformAssertions();

        $this->client->send($this->event());
    }
}
