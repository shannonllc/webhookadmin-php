<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** The request did not complete within the timeout. */
class TimeoutException extends ConnectionException
{
}
