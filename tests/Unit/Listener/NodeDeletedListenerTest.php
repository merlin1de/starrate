<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Listener;

use OCA\StarRate\Listener\NodeDeletedListener;
use OCA\StarRate\Service\ShareService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NodeDeletedListenerTest extends TestCase
{
    /** @var ShareService&MockObject */
    private ShareService $shareService;
    private NodeDeletedListener $listener;

    protected function setUp(): void
    {
        $this->shareService = $this->createMock(ShareService::class);
        $this->listener     = new NodeDeletedListener($this->shareService);
    }

    public function testDeletesCommentForFileId(): void
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(4711);

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->shareService
            ->expects($this->once())
            ->method('deleteComment')
            ->with(4711);

        $this->listener->handle($event);
    }

    public function testIgnoresUnrelatedEvents(): void
    {
        $event = $this->createMock(Event::class);

        $this->shareService
            ->expects($this->never())
            ->method('deleteComment');

        $this->listener->handle($event);
    }

    public function testIgnoresNodeWithoutId(): void
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(null);

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($node);

        $this->shareService
            ->expects($this->never())
            ->method('deleteComment');

        $this->listener->handle($event);
    }

    public function testPassesThroughArbitraryFileIds(): void
    {
        foreach ([1, 9999999] as $fileId) {
            $service = $this->createMock(ShareService::class);
            $service->expects($this->once())
                ->method('deleteComment')
                ->with($fileId);

            $node = $this->createMock(Node::class);
            $node->method('getId')->willReturn($fileId);
            $event = $this->createMock(NodeDeletedEvent::class);
            $event->method('getNode')->willReturn($node);

            (new NodeDeletedListener($service))->handle($event);
        }
    }
}
