<?php

declare(strict_types=1);

namespace kuiper\swoole\logger;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface RequestLogFormatterInterface
{
    /**
     * @param RequestInterface       $request
     * @param ResponseInterface|null $response
     * @param float                  $responseTime
     *
     * @return array formatted message and extra info
     */
    public function format(RequestInterface $request, ?ResponseInterface $response, float $responseTime): array;
}