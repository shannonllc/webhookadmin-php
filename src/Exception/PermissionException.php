<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** 403: the API key does not have the scope for this operation. */
class PermissionException extends WebhookAdminException
{
}
