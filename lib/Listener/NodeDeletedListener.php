<?php

declare(strict_types=1);

namespace OCA\StarRate\Listener;

use OCA\StarRate\Service\ShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;

/**
 * Löscht StarRate-Kommentare wenn die zugehörige Datei gelöscht wird.
 *
 * NC recycelt file_ids — ohne diesen Listener würde ein neues Foto
 * den Kommentar einer gelöschten Datei erben.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class NodeDeletedListener implements IEventListener
{
    public function __construct(
        private readonly ShareService $shareService,
    ) {}

    public function handle(Event $event): void
    {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $fileId = $event->getNode()->getId();
        if ($fileId === null) {
            return;
        }

        $this->shareService->deleteComment($fileId);
    }
}
