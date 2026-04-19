<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\TagService;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagServiceTest extends TestCase
{
    private TagService $service;

    /** @var ISystemTagManager&MockObject */
    private ISystemTagManager $tagManager;
    /** @var ISystemTagObjectMapper&MockObject */
    private ISystemTagObjectMapper $tagMapper;
    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    private int $tagIdCounter = 100;

    protected function setUp(): void
    {
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->tagMapper  = $this->createMock(ISystemTagObjectMapper::class);
        $this->db         = $this->createMock(IDBConnection::class);

        $this->service = new TagService(
            $this->tagManager,
            $this->tagMapper,
            $this->db,
            $this->createMock(LoggerInterface::class),
        );
    }

    // ─── Hilfsmethoden ───────────────────────────────────────────────────────

    private function makeTag(string $name, string $id = null): ISystemTag&MockObject
    {
        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getName')->willReturn($name);
        $tag->method('getId')->willReturn($id ?? (string) $this->tagIdCounter++);
        return $tag;
    }

    // ─── setMetadata ─────────────────────────────────────────────────────────
    // setMetadata verwendet direkte SQL-Queries (IDBConnection) statt ISystemTagObjectMapper,
    // damit es auch in unauthentifizierten Kontexten (Gast-Bewertungen, NC 32+) funktioniert.

    public function testSetMetadataAllFields(): void
    {
        // Kein existing mapping, alle 3 Tags existieren bereits in systemtag.
        // fetch-Sequenz: false (existing-SELECT) | id:40 | id:61 | id:80 (je 1 × getOrCreateTagDirect)
        $fetchValues = [false, ['id' => '40'], ['id' => '61'], ['id' => '80']];
        [$qb] = $this->mockSetMetadataQb($fetchValues, 3); // 3 assignTagDirect INSERTs

        $this->service->setMetadata('10', ['rating' => 4, 'color' => 'Blue', 'pick' => 'pick']);
    }

    public function testSetMetadataRemovesOldTags(): void
    {
        // Existing: rating:2 (id=20) + color:Red (id=60) → beide löschen
        // Neu: rating=5 (id=50), color=null → kein INSERT für color
        $fetchValues = [
            ['systemtagid' => '20', 'name' => 'starrate:rating:2'],
            ['systemtagid' => '60', 'name' => 'starrate:color:Red'],
            false,           // existing-SELECT Ende
            ['id' => '50'],  // getOrCreateTagDirect rating:5
        ];
        [$qb] = $this->mockSetMetadataQb($fetchValues, 2); // 1 DELETE + 1 INSERT

        $this->service->setMetadata('10', ['rating' => 5, 'color' => null]);
    }

    public function testSetMetadataRatingOnly(): void
    {
        // Kein existing mapping, rating:3 (id=30) existiert bereits
        $fetchValues = [false, ['id' => '30']];
        [$qb] = $this->mockSetMetadataQb($fetchValues, 1); // 1 INSERT

        $this->service->setMetadata('10', ['rating' => 3]);
    }

    public function testSetMetadataCreatesTagWhenNotInDb(): void
    {
        // Simuliert den NC 32 "fresh tag"-Fall: Tag existiert noch nicht in der systemtag-Tabelle.
        // getOrCreateTagDirect muss ihn per INSERT anlegen und danach per SELECT die ID lesen.
        //
        // fetch-Sequenz:
        //   false          → existing-mapping SELECT (keine vorhandenen Tags)
        //   false          → getOrCreateTagDirect: erster SELECT findet Tag nicht
        //   ['id' => '99'] → getOrCreateTagDirect: zweiter SELECT nach INSERT liest neue ID
        //
        // executeStatement:
        //   Aufruf 1 → INSERT INTO systemtag (neuer Tag)
        //   Aufruf 2 → INSERT INTO systemtag_object_mapping (assignTagDirect)
        $fetchValues = [false, false, ['id' => '99']];
        [$qb] = $this->mockSetMetadataQb($fetchValues, 2);

        $this->service->setMetadata('10', ['rating' => 5]);
    }

    public function testSetMetadataThrowsForInvalidRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setMetadata('10', ['rating' => 7]);
    }

    public function testSetMetadataThrowsForInvalidColor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setMetadata('10', ['color' => 'Pink']);
    }

    public function testSetMetadataThrowsForInvalidPick(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setMetadata('10', ['pick' => 'maybe']);
    }

    // ─── getMetadata ─────────────────────────────────────────────────────────

    public function testGetMetadataReturnsDefaultsWhenNoTags(): void
    {
        // Mock QueryBuilder chain for getMetadataBatch
        $this->mockQueryBuilder([]);

        $result = $this->service->getMetadata('10');

        $this->assertSame(0, $result['rating']);
        $this->assertNull($result['color']);
        $this->assertSame('none', $result['pick']);
    }

    public function testGetMetadataParsesAllTagTypes(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:4'],
            ['objectid' => '10', 'name' => 'starrate:color:Green'],
            ['objectid' => '10', 'name' => 'starrate:pick:reject'],
        ]);

        $result = $this->service->getMetadata('10');

        $this->assertSame(4, $result['rating']);
        $this->assertSame('Green', $result['color']);
        $this->assertSame('reject', $result['pick']);
    }

    // ─── getMetadataBatch ────────────────────────────────────────────────────

    public function testGetMetadataBatchReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->service->getMetadataBatch([]));
    }

    public function testGetMetadataBatchMultipleFiles(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:5'],
            ['objectid' => '10', 'name' => 'starrate:color:Red'],
            ['objectid' => '20', 'name' => 'starrate:rating:2'],
            ['objectid' => '20', 'name' => 'starrate:pick:pick'],
        ]);

        $result = $this->service->getMetadataBatch(['10', '20']);

        $this->assertSame(5, $result['10']['rating']);
        $this->assertSame('Red', $result['10']['color']);
        $this->assertSame('none', $result['10']['pick']);
        $this->assertSame(2, $result['20']['rating']);
        $this->assertNull($result['20']['color']);
        $this->assertSame('pick', $result['20']['pick']);
    }

    public function testGetMetadataBatchDefaultsForFilesWithoutTags(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:4'],
            // '20' has no rows at all
        ]);

        $result = $this->service->getMetadataBatch(['10', '20']);

        $this->assertSame(4, $result['10']['rating']);
        // File '20' should still be in result with defaults
        $this->assertArrayHasKey('20', $result);
        $this->assertSame(0, $result['20']['rating']);
        $this->assertNull($result['20']['color']);
        $this->assertSame('none', $result['20']['pick']);
    }

    public function testGetMetadataBatchIgnoresInvalidRating(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:99'],
        ]);

        $result = $this->service->getMetadataBatch(['10']);
        $this->assertSame(0, $result['10']['rating']);
    }

    public function testGetMetadataBatchIgnoresInvalidColor(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:color:Orange'],
        ]);

        $result = $this->service->getMetadataBatch(['10']);
        $this->assertNull($result['10']['color']);
    }

    public function testGetMetadataBatchIgnoresInvalidPick(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:pick:maybe'],
        ]);

        $result = $this->service->getMetadataBatch(['10']);
        $this->assertSame('none', $result['10']['pick']);
    }

    public function testGetMetadataBatchIgnoresNonStarrateTags(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:3'],
            ['objectid' => '10', 'name' => 'othertag:foo'],
        ]);

        $result = $this->service->getMetadataBatch(['10']);
        $this->assertSame(3, $result['10']['rating']);
    }

    // ─── clearAll ────────────────────────────────────────────────────────────

    public function testClearAllRemovesAllStarrateTags(): void
    {
        $ratingTag = $this->makeTag('starrate:rating:3', '30');
        $colorTag  = $this->makeTag('starrate:color:Red', '60');
        $otherTag  = $this->makeTag('sometag', '99');

        $this->tagMapper->method('getTagIdsForObjects')
            ->with(['10'], 'files')
            ->willReturn(['10' => ['30', '60', '99']]);
        $this->tagManager->method('getTagsByIds')
            ->with(['30', '60', '99'])
            ->willReturn([$ratingTag, $colorTag, $otherTag]);

        $this->tagMapper->expects($this->once())
            ->method('unassignTags')
            ->with('10', 'files', $this->callback(function ($ids) {
                return in_array('30', $ids) && in_array('60', $ids) && !in_array('99', $ids);
            }));

        $this->service->clearAll('10');
    }

    public function testClearAllRemovesPickTagsToo(): void
    {
        $ratingTag = $this->makeTag('starrate:rating:5', '50');
        $pickTag   = $this->makeTag('starrate:pick:pick', '80');
        $colorTag  = $this->makeTag('starrate:color:Green', '70');

        $this->tagMapper->method('getTagIdsForObjects')
            ->willReturn(['10' => ['50', '80', '70']]);
        $this->tagManager->method('getTagsByIds')
            ->willReturn([$ratingTag, $pickTag, $colorTag]);

        $this->tagMapper->expects($this->once())
            ->method('unassignTags')
            ->with('10', 'files', $this->callback(function ($ids) {
                return in_array('50', $ids) && in_array('80', $ids) && in_array('70', $ids);
            }));

        $this->service->clearAll('10');
    }

    public function testClearAllNoopWhenNoTags(): void
    {
        $this->tagMapper->method('getTagIdsForObjects')
            ->willReturn(['10' => []]);

        $this->tagMapper->expects($this->never())->method('unassignTags');

        $this->service->clearAll('10');
    }

    public function testSetMetadataHandlesExceptionOnTagRead(): void
    {
        // Erstes executeQuery (existing-mapping SELECT) wirft → try/catch fängt es.
        // getOrCreateTagDirect (2. executeQuery) soll trotzdem laufen und den Tag finden.
        // Komplett standalone – kein mockSetMetadataQb, damit executeQuery nicht doppelt gesetzt wird.
        $queryCount = 0;
        $fetchCount = 0;

        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturnCallback(function () use (&$fetchCount) {
            return $fetchCount++ === 0 ? ['id' => '30'] : false;
        });
        $result->method('closeCursor');

        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $expr->method('like')->willReturn('1=1');

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturnCallback(function () use (&$queryCount, $result) {
            if ($queryCount++ === 0) {
                throw new \RuntimeException('DB down'); // existing-mapping SELECT wirft
            }
            return $result; // getOrCreateTagDirect SELECT → findet Tag
        });
        $qb->expects($this->once())->method('executeStatement'); // 1 assignTagDirect INSERT

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->service->setMetadata('10', ['rating' => 3]);
    }

    // ─── QueryBuilder Mock ───────────────────────────────────────────────────

    /**
     * Mockt den QueryBuilder für getMetadataBatch / getMetadata (nutzt fetchAll).
     */
    private function mockQueryBuilder(array $rows): void
    {
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchAll')->willReturn($rows);
        $result->method('closeCursor');

        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $expr->method('like')->willReturn('1=1');

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    /**
     * Erstellt einen QB-Mock für setMetadata-Tests.
     * fetch()-Sequenz wird durch $fetchValues gesteuert (Array aus rows oder false).
     * executeStatement wird auf $expectedStatements-Aufrufe eingeschränkt.
     *
     * @return array{0: \OCP\DB\QueryBuilder\IQueryBuilder&MockObject, 1: \OCP\DB\IResult&MockObject}
     */
    private function mockSetMetadataQb(array $fetchValues, int $expectedStatements): array
    {
        $fetchIndex = 0;

        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturnCallback(
            function () use ($fetchValues, &$fetchIndex) {
                return $fetchValues[$fetchIndex++] ?? false;
            }
        );
        $result->method('closeCursor');

        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');
        $expr->method('in')->willReturn('1=1');
        $expr->method('like')->willReturn('1=1');

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);
        $qb->expects($this->exactly($expectedStatements))->method('executeStatement')->willReturn(1);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        return [$qb, $result];
    }
}
