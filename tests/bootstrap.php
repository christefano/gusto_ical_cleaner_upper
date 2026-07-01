<?php

declare(strict_types=1);

// Boot the Nextcloud server test harness, then load this app so its classes and
// the bundled sabre/vobject + OCP interfaces are available.
// Assumes the app lives at <nextcloud>/apps/gusto_ical_cleaner_upper.
require_once __DIR__ . '/../../../tests/bootstrap.php';

\OC_App::loadApp('gusto_ical_cleaner_upper');

if (!class_exists('PHPUnit\\Framework\\TestCase')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
