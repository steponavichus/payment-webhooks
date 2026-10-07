<?php

namespace App\Webhooks\Exceptions;

use RuntimeException;

/**
 * Typical out-of-order delivery: a refund arrived before the payment event.
 * The job is retried with backoff and usually succeeds on the next attempt.
 */
class PaymentNotFoundException extends RuntimeException {}
