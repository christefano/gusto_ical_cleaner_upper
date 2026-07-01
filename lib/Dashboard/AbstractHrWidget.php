<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Dashboard;

use OCA\GustoIcalCleanerUpper\Service\HrEvent;
use OCA\GustoIcalCleanerUpper\Service\HrEventReader;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

/**
 * Shared plumbing for the Gusto HR dashboard widgets. Renders through
 * IAPIWidgetV2 so the built-in dashboard frontend draws the list. No custom
 * JavaScript and no build step.
 */
abstract class AbstractHrWidget implements IAPIWidgetV2 {
    public function __construct(
        protected HrEventReader $reader,
        protected IL10N $l,
        protected IDateTimeFormatter $dateFormatter,
    ) {
    }

    public function getIconClass(): string {
        return 'icon-calendar-dark';
    }

    public function getUrl(): ?string {
        return null;
    }

    public function load(): void {
        // API widgets are rendered by the core dashboard frontend, so there is
        // no app script or style to enqueue here.
    }

    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        $items = [];
        foreach (array_slice($this->events($userId), 0, $limit) as $event) {
            $items[] = $this->toItem($event);
        }
        return $items;
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        return new WidgetItems(
            $this->getItems($userId, $since, $limit),
            $this->emptyMessage(),
        );
    }

    /**
     * Events this widget should show for the user, already filtered and sorted.
     *
     * @return HrEvent[]
     */
    abstract protected function events(string $userId): array;

    abstract protected function toItem(HrEvent $event): WidgetItem;

    abstract protected function emptyMessage(): string;
}
