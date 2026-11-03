<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Integration\Cron\Stubs;

use Symfony\Component\Console\Command\Command;

final class ReportsCommand extends Command
{
    protected static $defaultName = 'test:cron:reports';
}
