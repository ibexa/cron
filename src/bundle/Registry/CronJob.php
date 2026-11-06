<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cron\Registry;

use Cron\CronExpression;

final readonly class CronJob
{
    public function __construct(
        public CronExpression $schedule,
        public string $command
    ) {}
}
