<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\TemplateProblem;

/**
 * Port implemented by the Templates module.
 */
interface RendersTemplates
{
    /**
     * @param  array<string, string>  $variables
     *
     * @throws TemplateProblem when the template is unknown, bound to another
     *                         channel, or a variable is missing
     */
    public function render(string $name, array $variables, Channel $channel): string;
}
