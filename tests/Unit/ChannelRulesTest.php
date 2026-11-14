<?php

declare(strict_types=1);

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\ChannelRules;

beforeEach(function (): void {
    $this->rules = new ChannelRules(smsMaxSegments: 3, emailMaxLength: 1000, pushMaxLength: 100);
});

it('accepts valid recipients', function (Channel $channel, string $recipient): void {
    expect($this->rules->recipientError($channel, $recipient))->toBeNull();
})->with([
    [Channel::Sms, '+905551234567'],
    [Channel::Email, 'user@example.com'],
    [Channel::Push, 'fcm-device-token-abc123'],
]);

it('rejects recipients that do not fit the channel', function (Channel $channel, string $recipient): void {
    expect($this->rules->recipientError($channel, $recipient))->toBeString();
})->with([
    'sms without plus' => [Channel::Sms, '905551234567'],
    'sms with letters' => [Channel::Sms, '+90555abc4567'],
    'sms too short' => [Channel::Sms, '+12345'],
    'email as phone' => [Channel::Email, '+905551234567'],
    'email without domain' => [Channel::Email, 'user@'],
    'push with spaces' => [Channel::Push, 'token with spaces'],
    'push too short' => [Channel::Push, 'abc'],
]);

it('limits SMS by segments, not characters', function (): void {
    // 201 characters with Turkish letters need 3 UCS-2 segments (allowed),
    // 202 need 4 (rejected); the same length in plain ASCII is 2 segments.
    expect($this->rules->contentError(Channel::Sms, str_repeat('ş', 201)))->toBeNull()
        ->and($this->rules->contentError(Channel::Sms, str_repeat('ş', 202)))->toContain('4 SMS segments in UCS-2')
        ->and($this->rules->contentError(Channel::Sms, str_repeat('a', 202)))->toBeNull();
});

it('limits email and push by length', function (): void {
    expect($this->rules->contentError(Channel::Email, str_repeat('a', 1000)))->toBeNull()
        ->and($this->rules->contentError(Channel::Email, str_repeat('a', 1001)))->toContain('maximum for email is 1000')
        ->and($this->rules->contentError(Channel::Push, str_repeat('a', 101)))->toContain('maximum for push is 100');
});

it('rejects blank content on every channel', function (Channel $channel): void {
    expect($this->rules->contentError($channel, "  \n"))->toBe('The content must not be empty.');
})->with(Channel::cases());
