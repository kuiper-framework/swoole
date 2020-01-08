<?php

declare(strict_types=1);

namespace kuiper\swoole\event;

use Swoole\WebSocket\Frame;

class MessageEvent extends SwooleServerEvent
{
    /**
     * @var Frame
     */
    private $frame;

    public function getFrame(): Frame
    {
        return $this->frame;
    }

    public function setFrame(Frame $frame): void
    {
        $this->frame = $frame;
    }
}
