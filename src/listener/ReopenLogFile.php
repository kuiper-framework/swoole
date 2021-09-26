<?php

/*
 * This file is part of the Kuiper package.
 *
 * (c) Ye Wenbin <wenbinye@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace kuiper\swoole\listener;

use kuiper\event\EventListenerInterface;
use kuiper\logger\LoggerFactoryInterface;
use kuiper\swoole\event\WorkerStartEvent;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Webmozart\Assert\Assert;

class ReopenLogFile implements EventListenerInterface
{
    /**
     * @var LoggerFactoryInterface
     */
    private $loggerFactory;

    /**
     * @var int[]
     */
    private $fileInodes;

    /**
     * WorkerStartEventListener constructor.
     *
     * @param LoggerFactoryInterface $loggerFactory
     */
    public function __construct(LoggerFactoryInterface $loggerFactory)
    {
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke($event): void
    {
        Assert::isInstanceOf($event, WorkerStartEvent::class);
        $this->tryReopen();
        /* @var WorkerStartEvent $event */
        $event->getServer()->tick(10000, function (): void {
            $this->tryReopen();
        });
    }

    public function tryReopen(): void
    {
        clearstatcache();
        foreach ($this->loggerFactory->getLoggers() as $logger) {
            if (!($logger instanceof Logger)) {
                continue;
            }
            foreach ($logger->getHandlers() as $handler) {
                if (!($handler instanceof StreamHandler)) {
                    continue;
                }
                $fileExists = file_exists($handler->getUrl());
                if (!isset($this->fileInodes[$handler->getUrl()])) {
                    if (!$fileExists) {
                        continue;
                    }
                    $this->fileInodes[$handler->getUrl()] = fileinode($handler->getUrl());
                }
                if (!$fileExists || $this->fileInodes[$handler->getUrl()] !== fileinode($handler->getUrl())) {
                    $handler->close();
                }
            }
        }
    }

    public function getSubscribedEvent(): string
    {
        return WorkerStartEvent::class;
    }
}
