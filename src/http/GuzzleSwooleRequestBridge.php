<?php

declare(strict_types=1);

namespace kuiper\swoole\http;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request;

class GuzzleSwooleRequestBridge implements SwooleRequestBridgeInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(Request $swooleRequest): ServerRequestInterface
    {
        $server = array_change_key_case($swooleRequest->server, CASE_UPPER);
        $headers = $swooleRequest->header;
        foreach ($headers as $key => $val) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($key))] = $val;
        }
        $server['HTTP_COOKIE'] = isset($swooleRequest->cookie) ? $this->cookieString($swooleRequest->cookie) : '';
        $serverRequest = ServerRequestFactory::fromGlobals(
            $server,
            $swooleRequest->get,
            $swooleRequest->post,
            $swooleRequest->cookie,
            $swooleRequest->files ? normalizeUploadedFiles($swooleRequest->files) : null
        );
        $body = $swooleRequest->rawContent();
        if (!empty($body)) {
            $serverRequest = $serverRequest->withBody(Utils::streamFor($body));
        }

        return $serverRequest;
    }

    /**
     * Converts array to cookie string.
     */
    private function cookieString(array $cookie): string
    {
        return implode('; ', array_map(static function ($key, $value): string {
            return $key.'='.$value;
        }, array_keys($cookie), array_values($cookie)));
    }
}