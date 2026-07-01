<?php

declare(strict_types=1);

namespace OCA\GustoIcalCleanerUpper\Tests\Unit;

use OCA\GustoIcalCleanerUpper\Migration\UninstallCleanup;
use OCA\GustoIcalCleanerUpper\Service\CalendarMirror;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UninstallCleanupTest extends TestCase {
    private function user(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testPurgesEveryUsersManagedCalendars(): void {
        $users = [$this->user('frodo'), $this->user('gandalf')];
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('callForAllUsers')->willReturnCallback(
            function (callable $cb) use ($users): void {
                foreach ($users as $user) {
                    $cb($user);
                }
            },
        );

        $mirror = $this->createMock(CalendarMirror::class);
        $mirror->expects($this->exactly(2))
            ->method('purgeManagedCalendars')
            ->with($this->logicalOr(
                $this->equalTo('principals/users/frodo'),
                $this->equalTo('principals/users/gandalf'),
            ));

        $step = new UninstallCleanup($userManager, $mirror, $this->createMock(LoggerInterface::class));
        $step->run($this->createMock(IOutput::class));
    }

    public function testOneUserFailureDoesNotStopTheRest(): void {
        $users = [$this->user('frodo'), $this->user('gandalf')];
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('callForAllUsers')->willReturnCallback(
            function (callable $cb) use ($users): void {
                foreach ($users as $user) {
                    $cb($user);
                }
            },
        );

        $mirror = $this->createMock(CalendarMirror::class);
        $calls = 0;
        $mirror->method('purgeManagedCalendars')->willReturnCallback(
            function (string $principal) use (&$calls): void {
                $calls++;
                if ($principal === 'principals/users/frodo') {
                    throw new \RuntimeException('boom');
                }
            },
        );

        $step = new UninstallCleanup($userManager, $mirror, $this->createMock(LoggerInterface::class));
        $step->run($this->createMock(IOutput::class));

        // Both users attempted despite the first throwing.
        $this->assertSame(2, $calls);
    }
}
