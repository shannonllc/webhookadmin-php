<?php

declare(strict_types=1);

namespace WebhookAdmin\Exception;

/** 402: the plan's limit was reached (monthly messages, or a feature not in the plan). */
class PlanLimitException extends WebhookAdminException
{
}
