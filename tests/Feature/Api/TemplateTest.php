<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->postJson('/api/v1/templates', [
        'name' => 'order-shipped',
        'channel' => 'sms',
        'body' => 'Hi {{ name }}, order {{order}} is on its way.',
    ])->assertCreated()->assertJsonPath('data.variables', ['name', 'order']);
});

it('renders a template into the content when the notification is accepted', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'template' => 'order-shipped',
        'variables' => ['name' => 'Caner', 'order' => 1042],
    ])->assertCreated();

    expect($response->json('data.content'))->toBe('Hi Caner, order 1042 is on its way.');
});

it('rejects a template with a missing variable and names it', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'order-shipped', 'variables' => ['name' => 'Caner'],
    ])->assertUnprocessable()
        ->assertJsonPath('errors.template.0', 'The template "order-shipped" needs these variables: order.');

    expect(Notification::query()->count())->toBe(0);
});

it('rejects a template that belongs to another channel', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'email', 'recipient' => 'a@example.com', 'template' => 'order-shipped', 'variables' => ['name' => 'C', 'order' => 1],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('template');
});

it('rejects an unknown template', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'nope',
    ])->assertUnprocessable()->assertJsonPath('errors.template.0', 'The template "nope" does not exist.');
});

it('validates the rendered content against the channel limits', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'template' => 'order-shipped',
        'variables' => ['name' => str_repeat('ş', 300), 'order' => 1],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('content');
});

it('uses one template for every item of a batch', function (): void {
    $items = array_map(static fn (int $i): array => [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'template' => 'order-shipped',
        'variables' => ['name' => "User {$i}", 'order' => $i],
    ], range(1, 50));

    $this->postJson('/api/v1/notifications/batch', ['notifications' => $items])->assertAccepted()->assertJsonPath('data.created', 50);

    expect(Notification::query()->where('content', 'Hi User 50, order 50 is on its way.')->exists())->toBeTrue();
});

it('lists and shows templates, and refuses duplicate names', function (): void {
    $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/templates/order-shipped')->assertOk()->assertJsonPath('data.channel', 'sms');
    $this->postJson('/api/v1/templates', ['name' => 'order-shipped', 'body' => 'x'])->assertUnprocessable();
    $this->getJson('/api/v1/templates/missing')->assertNotFound();
});
