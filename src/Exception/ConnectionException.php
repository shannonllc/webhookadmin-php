<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** The request could not reach the API (DNS, TCP, TLS). */
class ConnectionException extends WebhookAdminException
{
}
