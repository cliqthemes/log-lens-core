<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use InvalidArgumentException;
use LogLens\Domain\IssueOrigin;
use LogLens\Repositories\IssueRepository;
use LogLens\Services\ManualIssueService;
use LogLens\Tests\TestCase;
use PDO;
use RuntimeException;

/**
 * H-6: the Issue lifecycle gateway. All origin/actor-sensitive error_groups
 * mutations go through IssueRepository, so its invariants are tested here once.
 */
final class IssueRepositoryTest extends TestCase
{
    /** @return array{0:IssueRepository,1:PDO,2:int} */
    private function seed(): array
    {
        $pdo = $this->makeDatabase()->pdo;
        $id = (new ManualIssueService($pdo))->create(['title' => 'Investigate', 'kind' => 'task', 'severity' => 'INFO']);
        return [new IssueRepository($pdo), $pdo, $id];
    }

    public function testExistenceStatusAndOriginReads(): void
    {
        [$repo, , $id] = $this->seed();
        self::assertTrue($repo->exists($id));
        self::assertFalse($repo->exists($id + 999));
        self::assertSame('open', $repo->statusOf($id));
        self::assertNull($repo->statusOf($id + 999));
        self::assertSame(IssueOrigin::MANUAL, $repo->originOf($id));
        self::assertSame(1, $repo->countByOrigin(IssueOrigin::MANUAL));
        self::assertSame(0, $repo->countByOrigin(IssueOrigin::INGESTED));
    }

    public function testChangeStatusUpdatesAndAttributesHistory(): void
    {
        [$repo, $pdo, $id] = $this->seed();
        $result = $repo->changeStatus($id, 'fixed', 'Shipped the fix.', 'Alice');
        self::assertSame(['from' => 'open', 'to' => 'fixed'], $result);
        self::assertSame('fixed', $repo->statusOf($id));

        $row = $pdo->query("SELECT to_status,note,actor FROM issue_status_history WHERE group_id={$id} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('fixed', $row['to_status']);
        self::assertSame('Shipped the fix.', $row['note']);
        self::assertSame('Alice', $row['actor']);
    }

    public function testChangeStatusRejectsUnknownStatus(): void
    {
        [$repo, , $id] = $this->seed();
        $this->expectException(InvalidArgumentException::class);
        $repo->changeStatus($id, 'nonsense');
    }

    public function testChangeStatusRejectsMissingIssue(): void
    {
        [$repo] = $this->seed();
        $this->expectException(RuntimeException::class);
        $repo->changeStatus(999999, 'fixed');
    }

    public function testRecordStatusChangeAppendsSystemHistory(): void
    {
        [$repo, $pdo, $id] = $this->seed();
        $repo->recordStatusChange($id, 'open', 'reoccurred', 'system note', null);
        $row = $pdo->query("SELECT to_status,actor FROM issue_status_history WHERE group_id={$id} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('reoccurred', $row['to_status']);
        self::assertNull($row['actor']);
    }
}
