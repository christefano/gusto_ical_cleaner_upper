<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Dashboard;

use OCA\GustoIcalCleanerUpper\Service\HrEvent;
use OCP\Dashboard\Model\WidgetItem;

/**
 * Dashboard widget listing who is out of office today.
 */
class GustoOutTodayWidget extends AbstractHrWidget {
    public function getId(): string {
        return 'gusto-out-today';
    }

    public function getTitle(): string {
        return $this->l->t('Out today');
    }

    public function getOrder(): int {
        return 10;
    }

    protected function events(string $userId): array {
        return $this->reader->outToday($userId);
    }

    protected function toItem(HrEvent $event): WidgetItem {
        // Strip the trailing "- OOO" marker to leave just the person's name.
        $name = trim((string)preg_replace('/\s*-\s*OOO\s*$/i', '', $event->summary));
        if ($name === '') {
            $name = $event->summary;
        }
        return new WidgetItem(
            $name,
            $this->l->t('Out of office'),
            '',
            '',
            $event->date->format('Y-m-d') . '-' . $name,
        );
    }

    protected function emptyMessage(): string {
        return $this->l->t('Nobody is out today');
    }
}
