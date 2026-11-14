<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

/**
 * Per-channel validation of recipient and content. Pure domain logic: the
 * limits are injected, nothing here knows about HTTP or the framework.
 */
final readonly class ChannelRules
{
    public function __construct(
        private int $smsMaxSegments,
        private int $emailMaxLength,
        private int $pushMaxLength,
    ) {}

    /**
     * @return string|null an error message, or null when the recipient is valid
     */
    public function recipientError(Channel $channel, string $recipient): ?string
    {
        return match ($channel) {
            Channel::Sms => preg_match('/^\+[1-9]\d{7,14}$/', $recipient) === 1
                ? null
                : 'The recipient must be a phone number in E.164 format, for example +905551234567.',
            Channel::Email => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false && strlen($recipient) <= 320
                ? null
                : 'The recipient must be a valid email address.',
            Channel::Push => preg_match('/^\S{8,255}$/', $recipient) === 1
                ? null
                : 'The recipient must be a device token of 8 to 255 characters without whitespace.',
        };
    }

    /**
     * @return string|null an error message, or null when the content is valid
     */
    public function contentError(Channel $channel, string $content): ?string
    {
        if (trim($content) === '') {
            return 'The content must not be empty.';
        }

        if ($channel === Channel::Sms) {
            $sms = SmsSegments::for($content);

            return $sms->segments <= $this->smsMaxSegments
                ? null
                : sprintf(
                    'The content needs %d SMS segments in %s encoding; the maximum is %d.',
                    $sms->segments,
                    $sms->encoding,
                    $this->smsMaxSegments,
                );
        }

        $limit = $channel === Channel::Email ? $this->emailMaxLength : $this->pushMaxLength;
        $length = mb_strlen($content);

        return $length <= $limit
            ? null
            : sprintf('The content is %d characters long; the maximum for %s is %d.', $length, $channel->value, $limit);
    }
}
