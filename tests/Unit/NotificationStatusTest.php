<?php

declare(strict_types=1);

use App\Modules\Notifications\Domain\NotificationStatus as Status;

it('allows exactly the transitions of the lifecycle', function (Status $from, array $allowed): void {
    foreach (Status::cases() as $to) {
        expect($from->canTransitionTo($to))->toBe(in_array($to, $allowed, true), "{$from->value} -> {$to->value}");
    }
})->with([
    'scheduled' => [Status::Scheduled, [Status::Queued, Status::Cancelled, Status::Failed]],
    'queued' => [Status::Queued, [Status::Processing, Status::Cancelled, Status::Failed]],
    'processing' => [Status::Processing, [Status::Sent, Status::Retrying, Status::Failed, Status::Queued]],
    'retrying' => [Status::Retrying, [Status::Queued, Status::Cancelled, Status::Failed]],
    'failed' => [Status::Failed, [Status::Queued]],
    'sent' => [Status::Sent, []],
    'cancelled' => [Status::Cancelled, []],
]);

it('never lets a notification that is being sent be cancelled', function (): void {
    expect(Status::sourcesOf(Status::Cancelled))->toBe(['scheduled', 'queued', 'retrying']);
});

it('treats sent, failed and cancelled as final', function (): void {
    $terminal = array_filter(Status::cases(), static fn (Status $status): bool => $status->isTerminal());

    expect(array_values($terminal))->toBe([Status::Sent, Status::Failed, Status::Cancelled]);
});
