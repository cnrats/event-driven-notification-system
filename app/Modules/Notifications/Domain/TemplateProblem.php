<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

use DomainException;

/**
 * A template could not be turned into content (unknown name, wrong channel,
 * missing variable). The message is safe to show to the API client.
 */
final class TemplateProblem extends DomainException {}
