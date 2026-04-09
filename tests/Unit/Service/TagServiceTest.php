<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\TagService;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
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

    /** Configure tagMapper->getTagIdsForObjects and tagManager->getTagsByIds */
    private function mockExistingTags(string $fileId, array $tags): void
    {
        $tagIds = array_map(fn($t) => $t->getId(), $tags);
        $this->tagMapper->method('getTagIdsForObjects')
            ->with([$fileId], 'files')
            ->willReturn([$fileId => $tagIds]);
        if (!empty($tagIds)) {
            $this->tagManager->method('getTagsByIds')
                ->with($tagIds)
                ->willReturn($tags);
        }
    }

    // ─── setRating ───────────────────────────────────────────────────────────

    public function testSetRatingAssignsTag(): void
    {
        $tag = $this->makeTag('starrate:rating:4', '42');
        $this->mockExistingTags('10', []);
        $this->tagManager->method('getAllTags')->willReturn(['42' => $tag]);
        $this->tagManager->method('createTag')->willReturn($tag);

        $this->tagMapper->expects($this->once())
            ->method('assignTags')
            ->with('10', 'files', ['42']);

        $this->service->setRating('10', 4);
    }

    public function testSetRatingZeroAssignsNoTag(): void
    {
        $this->mockExistingTags('10', []);

        $this->tagMapper->expects($this->never())->method('assignTags');

        $this->service->setRating('10', 0);
    }

    public function testSetRatingRemovesOldRatingTag(): void
    {
        $oldTag = $this->makeTag('starrate:rating:3', '30');
        $newTag = $this->makeTag('starrate:rating:5', '50');

        $this->mockExistingTags('10', [$oldTag]);
        $this->tagManager->method('getAllTags')->willReturn(['50' => $newTag]);
        $this->tagManager->method('createTag')->willReturn($newTag);

        $this->tagMapper->expects($this->once())
            ->method('unassignTags')
            ->with('10', 'files', ['30']);

        $this->service->setRating('10', 5);
    }

    public function testSetRatingZeroRemovesExistingTag(): void
    {
        $oldTag = $this->makeTag('starrate:rating:4', '40');
        $this->mockExistingTags('10', [$oldTag]);

        $this->tagMapper->expects($this->once())
            ->method('unassignTags')
            ->with('10', 'files', ['40']);
        $this->tagMapper->expects($this->never())->method('assignTags');

        $this->service->setRating('10', 0);
    }

    public function testSetRatingThrowsForInvalidRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setRating('10', 6);
    }

    public function testSetRatingThrowsForNegativeRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setRating('10', -1);
    }

    // ─── getRating ───────────────────────────────────────────────────────────

    public function testGetRatingReturnsHighestMatch(): void
    {
        $tag5 = $this->makeTag('starrate:rating:5', '50');
        $this->tagManager->method('getAllTags')
            ->willReturnCallback(function ($vis, $name) use ($tag5) {
                if ($name === 'starrate:rating:5') return ['50' => $tag5];
                return [];
            });
        $this->tagMapper->method('haveTag')
            ->willReturnCallback(function ($ids, $type, $tagId) {
                return $tagId === '50';
            });

        $this->assertSame(5, $this->service->getRating('10'));
    }

    public function testGetRatingReturns0WhenNoTags(): void
    {
        $this->tagManager->method('getAllTags')->willReturn([]);

        $this->assertSame(0, $this->service->getRating('10'));
    }

    // ─── setColor ────────────────────────────────────────────────────────────

    public function testSetColorAssignsTag(): void
    {
        $tag = $this->makeTag('starrate:color:Red', '60');
        $this->mockExistingTags('10', []);
        $this->tagManager->method('getAllTags')->willReturn(['60' => $tag]);
        $this->tagManager->method('createTag')->willReturn($tag);

        $this->tagMapper->expects($this->once())
            ->method('assignTags')
            ->with('10', 'files', ['60']);

        $this->service->setColor('10', 'Red');
    }

    public function testSetColorNullRemovesTag(): void
    {
        $oldTag = $this->makeTag('starrate:color:Blue', '61');
        $this->mockExistingTags('10', [$oldTag]);

        $this->tagMapper->expects($this->once())
            ->method('unassignTags')
            ->with('10', 'files', ['61']);
        $this->tagMapper->expects($this->never())->method('assignTags');

        $this->service->setColor('10', null);
    }

    public function testSetColorThrowsForInvalidColor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setColor('10', 'Orange');
    }

    /** @dataProvider validColorsProvider */
    public function testSetColorAcceptsAllValidColors(string $color): void
    {
        $tag = $this->makeTag('starrate:color:' . $color, '70');
        $this->mockExistingTags('10', []);
        $this->tagManager->method('getAllTags')->willReturn(['70' => $tag]);
        $this->tagManager->method('createTag')->willReturn($tag);

        $this->tagMapper->expects($this->once())->method('assignTags');

        $this->service->setColor('10', $color);
    }

    public static function validColorsProvider(): array
    {
        return [
            ['Red'], ['Yellow'], ['Green'], ['Blue'], ['Purple'],
        ];
    }

    // ─── getColor ────────────────────────────────────────────────────────────

    public function testGetColorReturnsMatch(): void
    {
        $tag = $this->makeTag('starrate:color:Green', '70');
        $this->tagManager->method('getAllTags')
            ->willReturnCallback(function ($vis, $name) use ($tag) {
                if ($name === 'starrate:color:Green') return ['70' => $tag];
                return [];
            });
        $this->tagMapper->method('haveTag')
            ->willReturnCallback(fn($ids, $type, $tagId) => $tagId === '70');

        $this->assertSame('Green', $this->service->getColor('10'));
    }

    public function testGetColorReturnsNullWhenNone(): void
    {
        $this->tagManager->method('getAllTags')->willReturn([]);

        $this->assertNull($this->service->getColor('10'));
    }

    // ─── setPick ─────────────────────────────────────────────────────────────

    public function testSetPickAssignsPickTag(): void
    {
        $tag = $this->makeTag('starrate:pick:pick', '80');
        $this->mockExistingTags('10', []);
        $this->tagManager->method('getAllTags')->willReturn(['80' => $tag]);
        $this->tagManager->method('createTag')->willReturn($tag);

        $this->tagMapper->expects($this->once())
            ->method('assignTags')
            ->with('10', 'files', ['80']);

        $this->service->setPick('10', 'pick');
    }

    public function testSetPickAssignsRejectTag(): void
    {
        $tag = $this->makeTag('starrate:pick:reject', '81');
        $this->mockExistingTags('10', []);
        $this->tagManager->method('getAllTags')->willReturn(['81' => $tag]);
        $this->tagManager->method('createTag')->willReturn($tag);

        $this->tagMapper->expects($this->once())
            ->method('assignTags')
            ->with('10', 'files', ['81']);

        $this->service->setPick('10', 'reject');
    }

    public function testSetPickNoneRemovesAllPickTags(): void
    {
        $oldTag = $this->makeTag('starrate:pick:pick', '80');
        $this->mockExistingTags('10', [$oldTag]);

        $this->tagMapper->expects($this->once())
            ->method('unassignTags')
            ->with('10', 'files', ['80']);
        $this->tagMapper->expects($this->never())->method('assignTags');

        $this->service->setPick('10', 'none');
    }

    public function testSetPickThrowsForInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setPick('10', 'maybe');
    }

    // ─── getPick ─────────────────────────────────────────────────────────────

    public function testGetPickReturnsPick(): void
    {
        $tag = $this->makeTag('starrate:pick:pick', '80');
        $this->tagManager->method('getAllTags')
            ->willReturnCallback(function ($vis, $name) use ($tag) {
                if ($name === 'starrate:pick:pick') return ['80' => $tag];
                return [];
            });
        $this->tagMapper->method('haveTag')
            ->willReturnCallback(fn($ids, $type, $tagId) => $tagId === '80');

        $this->assertSame('pick', $this->service->getPick('10'));
    }

    public function testGetPickReturnsReject(): void
    {
        $tag = $this->makeTag('starrate:pick:reject', '81');
        $this->tagManager->method('getAllTags')
            ->willReturnCallback(function ($vis, $name) use ($tag) {
                if ($name === 'starrate:pick:reject') return ['81' => $tag];
                if ($name === 'starrate:pick:pick') return [];
                return [];
            });
        $this->tagMapper->method('haveTag')
            ->willReturnCallback(fn($ids, $type, $tagId) => $tagId === '81');

        $this->assertSame('reject', $this->service->getPick('10'));
    }

    public function testGetPickReturnsNone(): void
    {
        $this->tagManager->method('getAllTags')->willReturn([]);

        $this->assertSame('none', $this->service->getPick('10'));
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

    // ─── filterByMinRating ───────────────────────────────────────────────────

    public function testFilterByMinRatingReturnsAllWhenMinIs0(): void
    {
        $ids = ['10', '20', '30'];
        // Should not even call getMetadataBatch
        $this->assertSame($ids, $this->service->filterByMinRating($ids, 0));
    }

    public function testFilterByMinRatingFilters(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:5'],
            ['objectid' => '20', 'name' => 'starrate:rating:2'],
            ['objectid' => '30', 'name' => 'starrate:rating:3'],
        ]);

        $result = $this->service->filterByMinRating(['10', '20', '30'], 3);
        $this->assertSame(['10', '30'], $result);
    }

    public function testFilterByMinRatingReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->service->filterByMinRating([], 3));
    }

    // ─── filterByRating ──────────────────────────────────────────────────────

    public function testFilterByRatingReturnsExactMatches(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:rating:3'],
            ['objectid' => '20', 'name' => 'starrate:rating:3'],
            ['objectid' => '30', 'name' => 'starrate:rating:4'],
        ]);

        $result = $this->service->filterByRating(['10', '20', '30'], 3);
        $this->assertSame(['10', '20'], $result);
    }

    public function testFilterByRatingReturnsEmptyForEmptyInput(): void
    {
        $this->mockQueryBuilder([]);
        $this->assertSame([], $this->service->filterByRating([], 3));
    }

    // ─── filterByColor ───────────────────────────────────────────────────────

    public function testFilterByColorReturnsEmptyForEmptyInput(): void
    {
        $this->mockQueryBuilder([]);
        $this->assertSame([], $this->service->filterByColor([], 'Red'));
    }

    public function testFilterByColorReturnsMatches(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:color:Red'],
            ['objectid' => '20', 'name' => 'starrate:color:Blue'],
        ]);

        $result = $this->service->filterByColor(['10', '20'], 'Red');
        $this->assertSame(['10'], $result);
    }

    public function testFilterByColorReturnsEmptyWhenNoMatch(): void
    {
        $this->mockQueryBuilder([
            ['objectid' => '10', 'name' => 'starrate:color:Red'],
        ]);

        $result = $this->service->filterByColor(['10'], 'Green');
        $this->assertSame([], $result);
    }

    // ─── Edge Cases ──────────────────────────────────────────────────────────

    public function testGetOrCreateTagCreatesNewTagWhenNotFound(): void
    {
        $newTag = $this->makeTag('starrate:rating:2', '22');
        $this->mockExistingTags('10', []);

        // getAllTags returns empty → tag doesn't exist yet
        $this->tagManager->method('getAllTags')->willReturn([]);
        $this->tagManager->expects($this->once())
            ->method('createTag')
            ->with('starrate:rating:2', false, false)
            ->willReturn($newTag);

        $this->tagMapper->expects($this->once())
            ->method('assignTags')
            ->with('10', 'files', ['22']);

        $this->service->setRating('10', 2);
    }

    public function testGetOrCreateTagFallsBackToCreateOnException(): void
    {
        $tag = $this->makeTag('starrate:rating:1', '11');
        $this->mockExistingTags('10', []);

        // getAllTags throws → should fall back to createTag
        $this->tagManager->method('getAllTags')
            ->willThrowException(new \RuntimeException('DB error'));
        $this->tagManager->expects($this->once())
            ->method('createTag')
            ->willReturn($tag);

        $this->tagMapper->expects($this->once())->method('assignTags');

        $this->service->setRating('10', 1);
    }

    public function testTagCacheAvoidsRepeatedLookups(): void
    {
        $tag = $this->makeTag('starrate:rating:3', '30');
        $this->tagMapper->method('getTagIdsForObjects')->willReturn(['10' => [], '20' => []]);

        // getAllTags should be called for the lookup, but createTag only once
        // because the second setRating(3) should hit the cache
        $this->tagManager->method('getAllTags')->willReturn([]);
        $this->tagManager->expects($this->once())
            ->method('createTag')
            ->willReturn($tag);

        $this->service->setRating('10', 3);
        $this->service->setRating('20', 3);
    }

    public function testRemoveTagsByPrefixHandlesTagNotFoundException(): void
    {
        $tag = $this->makeTag('starrate:rating:3', '30');
        $this->tagMapper->method('getTagIdsForObjects')
            ->willThrowException(new TagNotFoundException());

        // Should not throw, and should still try to assign the new tag
        $this->tagManager->method('getAllTags')->willReturn(['30' => $tag]);
        $this->tagManager->method('createTag')->willReturn($tag);
        $this->tagMapper->expects($this->once())->method('assignTags');

        $this->service->setRating('10', 3);
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
