<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** 409: conflicts with the current state (duplicate external_id, delivery still in progress). */
class ConflictException extends WebhookAdminException
{
}
