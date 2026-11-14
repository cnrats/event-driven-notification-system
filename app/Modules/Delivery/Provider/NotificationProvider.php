<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Provider;

/**
 * The external service that actually delivers a message. Implementations
 * must not throw for delivery failures: every outcome is a ProviderResult.
 */
interface NotificationProvider
{
    public function send(OutboundMessage $message): ProviderResult;
}
