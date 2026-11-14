<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

/**
 * Port implemented by the Delivery module. The Notifications module decides
 * *that* something should be delivered; it does not know about queues.
 */
interface DispatchesNotifications
{
    /**
     * Hand notifications to the delivery pipeline and record that the push
     * was confirmed. Must be called after the rows are committed.
     *
     * @param  list<QueueTicket>  $tickets
     */
    public function dispatch(array $tickets): void;
}
