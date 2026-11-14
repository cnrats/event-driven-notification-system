<?php

declare(strict_types=1);

namespace App\Modules\Templates;

use App\Modules\Notifications\Contracts\RendersTemplates;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\TemplateProblem;
use App\Modules\Templates\Models\Template;

/**
 * Renders "Hi {{ name }}" style templates.
 *
 * Rendering happens when the notification is accepted, not when it is sent:
 * what was validated (length, SMS segments) is exactly what gets delivered,
 * and editing a template later never changes messages already queued.
 */
final class TemplateRenderer implements RendersTemplates
{
    private const string PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /**
     * Templates already looked up in this request. A 1000-item batch that
     * uses one template reads it once.
     *
     * @var array<string, Template|null>
     */
    private array $loaded = [];

    public function render(string $name, array $variables, Channel $channel): string
    {
        $template = $this->find($name);

        if (! $template instanceof Template) {
            throw new TemplateProblem(sprintf('The template "%s" does not exist.', $name));
        }

        if ($template->channel instanceof Channel && $template->channel !== $channel) {
            throw new TemplateProblem(sprintf(
                'The template "%s" is for the %s channel and cannot be used for %s.',
                $name,
                $template->channel->value,
                $channel->value,
            ));
        }

        $missing = array_values(array_diff(self::placeholders($template->body), array_keys($variables)));

        if ($missing !== []) {
            throw new TemplateProblem(sprintf(
                'The template "%s" needs these variables: %s.',
                $name,
                implode(', ', $missing),
            ));
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $match): string => $variables[$match[1]],
            $template->body,
        );
    }

    /**
     * @return list<string> placeholder names in order of first appearance
     */
    public static function placeholders(string $body): array
    {
        preg_match_all(self::PLACEHOLDER, $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function find(string $name): ?Template
    {
        if (! array_key_exists($name, $this->loaded)) {
            $this->loaded[$name] = Template::query()->where('name', $name)->first();
        }

        return $this->loaded[$name];
    }
}
