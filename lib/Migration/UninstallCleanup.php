<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Migration;

use OCA\GustoIcalCleanerUpper\Service\CalendarMirror;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Runs when the app is uninstalled (registered under <repair-steps><uninstall>).
 * Deletes every managed Gusto calendar for every user so uninstalling actually
 * removes the split calendars instead of leaving them orphaned.
 *
 * Only calendars matching the managed URI pattern are ever touched, so the
 * user's own calendars are safe.
 */
class UninstallCleanup implements IRepairStep {
    public function __construct(
        private IUserManager $userManager,
        private CalendarMirror $mirror,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Remove Gusto iCal Cleaner Upper managed calendars';
    }

    public function run(IOutput $output): void {
        $this->userManager->callForAllUsers(function (IUser $user): void {
            $principal = 'principals/users/' . $user->getUID();
            try {
                $this->mirror->purgeManagedCalendars($principal);
            } catch (\Throwable $e) {
                // Keep going so one user's failure can't block the uninstall.
                $this->logger->error('Gusto iCal Cleaner Upper: uninstall cleanup failed for user', ['user' => $user->getUID(), 'exception' => $e]);
            }
        });
    }
}
