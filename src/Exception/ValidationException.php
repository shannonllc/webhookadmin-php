<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** 400, 413 or 422: the request body or parameters are invalid. See `fields`. */
class ValidationException extends WebhookAdminException
{
}
