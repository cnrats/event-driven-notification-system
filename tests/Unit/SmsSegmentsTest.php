<?php

declare(strict_types=1);

use App\Modules\Notifications\Domain\SmsSegments;

it('fits 160 GSM-7 characters in one segment', function (): void {
    $sms = SmsSegments::for(str_repeat('a', 160));

    expect($sms->encoding)->toBe('GSM-7')->and($sms->segments)->toBe(1);
});

it('splits longer GSM-7 text into 153-character segments', function (int $length, int $segments): void {
    expect(SmsSegments::for(str_repeat('a', $length))->segments)->toBe($segments);
})->with([
    [161, 2],
    [306, 2],
    [307, 3],
]);

it('counts GSM extension characters as two', function (): void {
    // 159 basic characters plus one euro sign (2 septets) is 161: two segments.
    $sms = SmsSegments::for(str_repeat('a', 159).'€');

    expect($sms->encoding)->toBe('GSM-7')
        ->and($sms->units)->toBe(161)
        ->and($sms->segments)->toBe(2);
});

it('switches to UCS-2 when one character is outside the GSM alphabet', function (string $text): void {
    expect(SmsSegments::for($text)->encoding)->toBe('UCS-2');
})->with([
    'Turkish ş' => ['Siparişiniz yola çıktı'],
    'Turkish ğ' => ['Doğrulama kodunuz 1234'],
    'emoji' => ['Sale starts now 🎉'],
]);

it('allows only 70 characters per message in UCS-2', function (): void {
    expect(SmsSegments::for(str_repeat('ş', 70))->segments)->toBe(1)
        ->and(SmsSegments::for(str_repeat('ş', 71))->segments)->toBe(2)
        ->and(SmsSegments::for(str_repeat('ş', 135))->segments)->toBe(3);
});

it('counts an emoji as two UCS-2 units', function (): void {
    expect(SmsSegments::for('🎉')->units)->toBe(2);
});
