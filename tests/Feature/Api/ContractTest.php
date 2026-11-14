<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorBuilder;

/*
 * The OpenAPI document is part of the deliverable, so it is tested: every
 * response below is validated against docs/openapi.yaml. A field that is
 * renamed, dropped or changes type in the code fails here.
 */

/**
 * @param  string  $path  the path as written in the document, e.g. /notifications/{id}
 */
function matchesSpec(TestResponse $response, string $method, string $path): void
{
    static $validator = null;
    $validator ??= ValidatorBuilder::fromYamlFile(base_path('docs/openapi.yaml'))->getValidator();

    expect($validator->validate($response->baseResponse, $path, $method))->toBeTrue();
}

beforeEach(function (): void {
    Queue::fake();
    $this->sms = ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello'];
});

it('documents creating a notification', function (): void {
    matchesSpec($this->postJson('/api/v1/notifications', $this->sms, ['Idempotency-Key' => 'k1'])->assertCreated(), 'post', '/notifications');
    matchesSpec($this->postJson('/api/v1/notifications', $this->sms, ['Idempotency-Key' => 'k1'])->assertOk(), 'post', '/notifications');
    matchesSpec($this->postJson('/api/v1/notifications', ['content' => 'Other'] + $this->sms, ['Idempotency-Key' => 'k1'])->assertConflict(), 'post', '/notifications');
    matchesSpec($this->postJson('/api/v1/notifications', ['channel' => 'fax'])->assertUnprocessable(), 'post', '/notifications');
    matchesSpec(
        $this->postJson('/api/v1/notifications', $this->sms + ['scheduled_at' => now()->addHour()->toIso8601String()])->assertCreated(),
        'post',
        '/notifications',
    );
});

it('documents reading notifications', function (): void {
    $notification = accept();
    providerResponds(503);
    deliver($notification);
    accept(['channel' => 'email', 'recipient' => 'a@example.com']);

    matchesSpec($this->getJson("/api/v1/notifications/{$notification->id}")->assertOk(), 'get', '/notifications/{id}');
    matchesSpec($this->getJson('/api/v1/notifications?per_page=1')->assertOk(), 'get', '/notifications');
    matchesSpec($this->getJson('/api/v1/notifications?status=nope')->assertUnprocessable(), 'get', '/notifications');
    matchesSpec(
        $this->getJson('/api/v1/notifications/01938c2e-7b1a-7c3d-9e4f-000000000000')->assertNotFound(),
        'get',
        '/notifications/{id}',
    );
});

it('documents cancelling and retrying', function (): void {
    $queued = accept();
    matchesSpec($this->postJson("/api/v1/notifications/{$queued->id}/cancel")->assertOk(), 'post', '/notifications/{id}/cancel');
    matchesSpec($this->postJson("/api/v1/notifications/{$queued->id}/cancel")->assertConflict(), 'post', '/notifications/{id}/cancel');

    $failed = accept();
    providerResponds(400);
    deliver($failed);
    matchesSpec($this->postJson("/api/v1/notifications/{$failed->id}/retry")->assertOk(), 'post', '/notifications/{id}/retry');
    matchesSpec($this->postJson("/api/v1/notifications/{$failed->id}/retry")->assertConflict(), 'post', '/notifications/{id}/retry');
});

it('documents batches', function (): void {
    $body = ['notifications' => [$this->sms, ['channel' => 'email', 'recipient' => 'a@example.com', 'content' => 'Hi']]];

    $accepted = $this->postJson('/api/v1/notifications/batch', $body, ['Idempotency-Key' => 'b1'])->assertAccepted();
    $batchId = $accepted->json('data.batch_id');

    matchesSpec($accepted, 'post', '/notifications/batch');
    matchesSpec($this->postJson('/api/v1/notifications/batch', $body, ['Idempotency-Key' => 'b1'])->assertOk(), 'post', '/notifications/batch');
    matchesSpec($this->postJson('/api/v1/notifications/batch', ['notifications' => [$this->sms]], ['Idempotency-Key' => 'b1'])->assertConflict(), 'post', '/notifications/batch');
    matchesSpec($this->postJson('/api/v1/notifications/batch', ['notifications' => [['channel' => 'sms']]])->assertUnprocessable(), 'post', '/notifications/batch');
    matchesSpec($this->getJson("/api/v1/batches/{$batchId}")->assertOk(), 'get', '/batches/{id}');
    matchesSpec($this->postJson("/api/v1/batches/{$batchId}/cancel")->assertOk(), 'post', '/batches/{id}/cancel');
    matchesSpec(
        $this->getJson('/api/v1/batches/01938c2e-7b1a-7c3d-9e4f-000000000000')->assertNotFound(),
        'get',
        '/batches/{id}',
    );
});

it('documents templates', function (): void {
    $template = ['name' => 'welcome', 'channel' => 'sms', 'body' => 'Hi {{ name }}'];

    matchesSpec($this->postJson('/api/v1/templates', $template)->assertCreated(), 'post', '/templates');
    matchesSpec($this->postJson('/api/v1/templates', $template)->assertUnprocessable(), 'post', '/templates');
    matchesSpec($this->postJson('/api/v1/templates', ['name' => 'any-channel', 'body' => 'Hello'])->assertCreated(), 'post', '/templates');
    matchesSpec($this->getJson('/api/v1/templates')->assertOk(), 'get', '/templates');
    matchesSpec($this->getJson('/api/v1/templates/welcome')->assertOk(), 'get', '/templates/{name}');
    matchesSpec($this->getJson('/api/v1/templates/missing')->assertNotFound(), 'get', '/templates/{name}');
});

it('documents health and metrics', function (): void {
    providerResponds();
    deliver(accept());

    matchesSpec($this->getJson('/api/v1/health')->assertOk(), 'get', '/health');
    matchesSpec($this->getJson('/api/v1/metrics')->assertOk(), 'get', '/metrics');
});

it('catches a response that does not match the document', function (): void {
    // Guards the guard: an undocumented status code must be reported.
    $response = $this->postJson('/api/v1/notifications', $this->sms)->assertCreated();

    expect(fn () => matchesSpec($response, 'get', '/templates'))->toThrow(ValidationException::class);
});
