<?php

declare(strict_types=1);

use App\Modules\Delivery\Queue\QueueRouter;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\Priority;

it('names a queue after its channel and priority', function (): void {
    expect(QueueRouter::queueFor(Channel::Sms, Priority::High))->toBe('sms-high')
        ->and(QueueRouter::queueFor(Channel::Email, Priority::Low))->toBe('email-low');
});

it('lists high before normal before low for the priority pool', function (): void {
    expect(QueueRouter::priorityOrder(Channel::Push))->toBe(['push-high', 'push-normal', 'push-low']);
});

it('lists low first for the fairness pool', function (): void {
    expect(QueueRouter::fairnessOrder(Channel::Push))->toBe(['push-low', 'push-normal', 'push-high']);
});

it('has one queue per channel and priority', function (): void {
    expect(QueueRouter::all())->toHaveCount(count(Channel::cases()) * count(Priority::cases()));
});
