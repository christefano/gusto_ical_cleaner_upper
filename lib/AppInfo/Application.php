<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\AppInfo;

use OCA\GustoIcalCleanerUpper\Dashboard\GustoCelebrationsWidget;
use OCA\GustoIcalCleanerUpper\Dashboard\GustoOutTodayWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The background job is registered via <background-jobs> in appinfo/info.xml,
 * and all services autowire from lib/. The only bootstrap wiring is registering
 * the dashboard widgets, which is cheap (no DB access at registration time).
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'gusto_ical_cleaner_upper';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(GustoOutTodayWidget::class);
        $context->registerDashboardWidget(GustoCelebrationsWidget::class);
    }

    public function boot(IBootContext $context): void {
    }
}
