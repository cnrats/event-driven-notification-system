<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Contracts\RendersTemplates;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\ChannelRules;
use App\Modules\Notifications\Domain\Priority;
use App\Modules\Notifications\Domain\TemplateProblem;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns raw request input into a validated NotificationDraft.
 *
 * Validation is written by hand instead of with wildcard validator rules so
 * that a 1000-item batch is checked in one linear pass and single and batch
 * requests share exactly the same rules.
 */
final readonly class DraftFactory
{
    private const int MAX_SCHEDULE_DAYS = 365;

    public function __construct(
        private ChannelRules $rules,
        private RendersTemplates $templates,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function make(array $input, CarbonImmutable $now): DraftResult
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        $channel = is_string($input['channel'] ?? null) ? Channel::tryFrom($input['channel']) : null;
        if (! $channel instanceof Channel) {
            $errors['channel'][] = 'The channel must be one of: '.implode(', ', Channel::values()).'.';
        }

        $priority = Priority::Normal;
        if (($input['priority'] ?? null) !== null) {
            $given = is_string($input['priority']) ? Priority::tryFrom($input['priority']) : null;
            if ($given instanceof Priority) {
                $priority = $given;
            } else {
                $errors['priority'][] = 'The priority must be one of: '.implode(', ', Priority::values()).'.';
            }
        }

        $recipient = $input['recipient'] ?? null;
        if (! is_string($recipient) || $recipient === '') {
            $errors['recipient'][] = 'The recipient field is required.';
            $recipient = null;
        }

        $scheduledAt = $this->scheduledAt($input['scheduled_at'] ?? null, $now, $errors);
        $idempotencyKey = $this->idempotencyKey($input['idempotency_key'] ?? null, $errors);
        $content = $this->content($input, $channel, $errors);

        if ($channel instanceof Channel && $recipient !== null) {
            $problem = $this->rules->recipientError($channel, $recipient);
            if ($problem !== null) {
                $errors['recipient'][] = $problem;
            }
        }

        if ($channel instanceof Channel && $content !== null) {
            $problem = $this->rules->contentError($channel, $content);
            if ($problem !== null) {
                $errors['content'][] = $problem;
            }
        }

        if ($errors !== [] || ! $channel instanceof Channel || $recipient === null || $content === null) {
            return new DraftResult(null, $errors);
        }

        return new DraftResult(
            new NotificationDraft($channel, $recipient, $content, $priority, $scheduledAt, $idempotencyKey),
            [],
        );
    }

    /**
     * The message body: given directly, or rendered from a template.
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, list<string>>  $errors
     */
    private function content(array $input, ?Channel $channel, array &$errors): ?string
    {
        $content = $input['content'] ?? null;
        $template = $input['template'] ?? null;

        if ($content !== null && $template !== null) {
            $errors['content'][] = 'Provide either content or template, not both.';

            return null;
        }

        if ($template === null) {
            if (! is_string($content)) {
                $errors['content'][] = 'The content field is required unless a template is given.';

                return null;
            }

            return $content;
        }

        if (! is_string($template) || $template === '') {
            $errors['template'][] = 'The template must be the name of an existing template.';

            return null;
        }

        $variables = $this->variables($input['variables'] ?? [], $errors);

        if ($variables === null || ! $channel instanceof Channel) {
            return null;
        }

        try {
            return $this->templates->render($template, $variables, $channel);
        } catch (TemplateProblem $problem) {
            $errors['template'][] = $problem->getMessage();

            return null;
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>|null
     */
    private function variables(mixed $given, array &$errors): ?array
    {
        if (! is_array($given)) {
            $errors['variables'][] = 'The variables must be an object of name/value pairs.';

            return null;
        }

        $variables = [];

        foreach ($given as $name => $value) {
            if (! is_string($name) || ! is_scalar($value)) {
                $errors['variables'][] = 'Each variable must have a name and a string, number or boolean value.';

                return null;
            }

            $variables[$name] = (string) $value;
        }

        return $variables;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function scheduledAt(mixed $given, CarbonImmutable $now, array &$errors): ?CarbonImmutable
    {
        if ($given === null) {
            return null;
        }

        try {
            $scheduledAt = is_string($given) && $given !== '' ? CarbonImmutable::parse($given)->utc() : null;
        } catch (Throwable) {
            $scheduledAt = null;
        }

        if (! $scheduledAt instanceof CarbonImmutable) {
            $errors['scheduled_at'][] = 'The scheduled_at field must be an ISO 8601 date-time, for example 2026-12-01T09:00:00+03:00.';

            return null;
        }

        if ($scheduledAt->lessThanOrEqualTo($now)) {
            $errors['scheduled_at'][] = 'The scheduled_at field must be in the future.';

            return null;
        }

        if ($scheduledAt->greaterThan($now->addDays(self::MAX_SCHEDULE_DAYS))) {
            $errors['scheduled_at'][] = sprintf('The scheduled_at field must be within %d days.', self::MAX_SCHEDULE_DAYS);

            return null;
        }

        return $scheduledAt;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function idempotencyKey(mixed $given, array &$errors): ?string
    {
        if ($given === null) {
            return null;
        }

        if (! is_string($given) || $given === '' || strlen($given) > 255) {
            $errors['idempotency_key'][] = 'The idempotency_key must be a string of 1 to 255 characters.';

            return null;
        }

        return $given;
    }
}
