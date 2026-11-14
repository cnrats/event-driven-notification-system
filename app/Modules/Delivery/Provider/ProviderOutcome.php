<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Provider;

enum ProviderOutcome: string
{
    /** 2xx: the provider took the message. */
    case Accepted = 'accepted';

    /** 5xx, timeout, connection error: worth trying again later. */
    case Retryable = 'retryable';

    /** 429: the provider is fine but wants us to slow down. */
    case Throttled = 'throttled';

    /** Other 4xx: the provider will never accept this message. */
    case Permanent = 'permanent';
}
