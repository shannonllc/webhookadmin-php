<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** 401: the API key is missing, wrong, revoked or expired. */
class AuthenticationException extends WebhookAdminException
{
}
