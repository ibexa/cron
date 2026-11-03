<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Integration\Cron;

use Ibexa\Bundle\Cron\IbexaCronBundle;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Ibexa\Bundle\Test\Core\IbexaTestCoreBundle;
use Ibexa\Contracts\Test\Core\IbexaTestKernel as BaseKernel;
use Symfony\Component\Config\Loader\LoaderInterface;

final class IbexaTestKernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new IbexaTestCoreBundle();
        yield new IbexaCronBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(__DIR__ . '/Resources/services.yaml');
    }

    protected static function getExposedServicesByClass(): iterable
    {
        yield from parent::getExposedServicesByClass();

        yield CronJobsRegistry::class;
    }
}
