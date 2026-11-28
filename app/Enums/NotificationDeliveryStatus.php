<?php

namespace App\Enums;

/**
 * Durable outbox delivery lifecycle states for incident notifications.
 */
enum NotificationDeliveryStatus: string
{
    case PENDING = 'pending';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
