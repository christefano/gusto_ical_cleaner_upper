<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Dashboard;

use OCA\GustoIcalCleanerUpper\AppInfo\Application;
use OCA\GustoIcalCleanerUpper\Service\HrEvent;
use OCA\GustoIcalCleanerUpper\Service\HrEventReader;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

/**
 * Dashboard widget listing upcoming birthdays, anniversaries, and first days.
 */
class GustoCelebrationsWidget extends AbstractHrWidget {
    private const DEFAULT_WINDOW_DAYS = 7;

    public function __construct(
        HrEventReader $reader,
        IL10N $l,
        IDateTimeFormatter $dateFormatter,
        private IConfig $config,
    ) {
        parent::__construct($reader, $l, $dateFormatter);
    }

    public function getId(): string {
        return 'gusto-celebrations';
    }

    public function getTitle(): string {
        return $this->l->t('Celebrations');
    }

    public function getOrder(): int {
        return 11;
    }

    protected function events(string $userId): array {
        return $this->reader->celebrations($userId, $this->windowDays());
    }

    protected function toItem(HrEvent $event): WidgetItem {
        $when = $this->dateFormatter->formatDate($event->date->getTimestamp(), 'long');
        return new WidgetItem(
            $event->summary,
            $when,
            '',
            '',
            $event->date->format('Y-m-d') . '-' . $event->summary,
        );
    }

    protected function emptyMessage(): string {
        return $this->l->t('Nothing coming up');
    }

    private function windowDays(): int {
        $value = (int)$this->config->getAppValue(
            Application::APP_ID,
            'dashboard_window_days',
            (string)self::DEFAULT_WINDOW_DAYS,
        );
        return $value > 0 ? $value : self::DEFAULT_WINDOW_DAYS;
    }
}
