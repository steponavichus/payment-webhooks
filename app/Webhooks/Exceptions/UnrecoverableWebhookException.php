<?php

namespace App\Webhooks\Exceptions;

use RuntimeException;

/**
 * The event can never be processed successfully (bad data, impossible state
 * transition). Retrying would only waste time, so the job fails immediately.
 */
class UnrecoverableWebhookException extends RuntimeException {}
