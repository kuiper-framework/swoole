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

namespace kuiper\swoole\config;

use function DI\autowire;

use InvalidArgumentException;
use kuiper\di\attribute\Bean;
use kuiper\di\ContainerBuilderAwareTrait;
use kuiper\di\DefinitionConfiguration;

use function kuiper\helper\env;

use kuiper\helper\PropertyResolverInterface;
use kuiper\logger\LoggerFactoryInterface;
use kuiper\swoole\Application;
use kuiper\swoole\constants\ServerSetting;
use kuiper\swoole\constants\ServerType;
use kuiper\swoole\event\ReceiveEvent;
use kuiper\swoole\event\RequestEvent;
use kuiper\swoole\http\FileStreamFactory;
use kuiper\swoole\http\FileStreamFactoryInterface;
use kuiper\swoole\http\HttpMessageFactoryHolder;
use kuiper\swoole\http\SwooleRequestBridgeInterface;
use kuiper\swoole\http\SwooleResponseBridge;
use kuiper\swoole\http\SwooleResponseBridgeInterface;
use kuiper\swoole\listener\HttpRequestEventListener;
use kuiper\swoole\listener\ManagerStartEventListener;
use kuiper\swoole\listener\PipeMessageEventListener;
use kuiper\swoole\listener\ReopenLogFile;
use kuiper\swoole\listener\RoutedHttpRequestEventListener;
use kuiper\swoole\listener\RoutedTcpReceiveEventListener;
use kuiper\swoole\listener\StartEventListener;
use kuiper\swoole\listener\TaskEventListener;
use kuiper\swoole\listener\WorkerExitEventListener;
use kuiper\swoole\listener\WorkerStartEventListener;
use kuiper\swoole\server\ServerInterface;
use kuiper\swoole\ServerConfig;
use kuiper\swoole\ServerFactory;
use kuiper\swoole\ServerPort;
use kuiper\swoole\ServerReloadCommand;
use kuiper\swoole\ServerStartCommand;
use kuiper\swoole\ServerStopCommand;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Application as ConsoleApplication;

class ServerConfiguration implements DefinitionConfiguration
{
    use ContainerBuilderAwareTrait;

    protected const TAG = '['.__CLASS__.'] ';

    public function getDefinitions(): array
    {
        $config = Application::getInstance()->getConfig();
        $config->mergeIfNotExists([
            'application' => [
                'default_command' => ServerStartCommand::NAME,
                'commands' => [
                    'start' => ServerStartCommand::class,
                    'stop' => ServerStopCommand::class,
                    'reload' => ServerReloadCommand::class,
                ],
                'server' => [
                    'port' => env('SERVER_PORT'),
                ],
            ],
        ]);
        if (empty($config->get('application.server.ports'))) {
            $config->set('application.server.ports', [
                $config->get('application.server.port', env('SERVER_PORT', '8000')) => 'http',
            ]);
        }
        $config->merge([
            'application' => [
                'bootstrap_listeners' => [
                    StartEventListener::class,
                    ManagerStartEventListener::class,
                    WorkerStartEventListener::class,
                    WorkerExitEventListener::class,
                    PipeMessageEventListener::class,
                    TaskEventListener::class,
                    ReopenLogFile::class,
                ],
                'listeners' => [
                    ReceiveEvent::class => RoutedTcpReceiveEventListener::class,
                    RequestEvent::class => RoutedHttpRequestEventListener::class,
                ],
            ],
        ]);

        return [
            SwooleResponseBridgeInterface::class => autowire(SwooleResponseBridge::class),
            FileStreamFactoryInterface::class => autowire(FileStreamFactory::class),
        ];
    }

    #[Bean]
    public function consoleApplication(PropertyResolverInterface $config): ConsoleApplication
    {
        return new ConsoleApplication($config->get('application.name', 'app'));
    }

    #[Bean]
    public function tcpReceiveEventListener(ContainerInterface $container, ServerConfig $serverConfig, LoggerFactoryInterface $loggerFactory): RoutedTcpReceiveEventListener
    {
        $routes = [];
        foreach ($serverConfig->getPorts() as $port) {
            if (ServerType::TCP === $port->getServerType()) {
                if (null === $port->getListener()) {
                    throw new InvalidArgumentException("Tcp port {$port->getPort()} listener is required");
                }
                $listener = $container->get($port->getListener());
                $routes[$port->getPort()] = $listener;
            }
        }

        $eventListener = new RoutedTcpReceiveEventListener($routes);
        $eventListener->setLogger($loggerFactory->create(RoutedTcpReceiveEventListener::class));

        return $eventListener;
    }

    #[Bean]
    public function httpRequestEventListener(ContainerInterface $container, ServerConfig $serverConfig, LoggerFactoryInterface $loggerFactory): RoutedHttpRequestEventListener
    {
        $routes = [];
        foreach ($serverConfig->getPorts() as $port) {
            if ($port->isHttpProtocol()) {
                if (null !== $port->getListener()) {
                    $listener = $container->get($port->getListener());
                } else {
                    $listener = $container->get(HttpRequestEventListener::class);
                }
                $routes[$port->getPort()] = $listener;
            }
        }

        $eventListener = new RoutedHttpRequestEventListener($routes);
        $eventListener->setLogger($loggerFactory->create(RoutedHttpRequestEventListener::class));

        return $eventListener;
    }

    #[Bean]
    public function server(
        ContainerInterface $container,
        ServerConfig $serverConfig,
        EventDispatcherInterface $eventDispatcher,
        LoggerFactoryInterface $loggerFactory
    ): ServerInterface {
        $app = Application::getInstance();
        if ($app->isBootstrapContainerEnabled() && !$app->isBootstrapping()) {
            return $app->getBootstrapContainer()->get(ServerInterface::class);
        }
        $config = $app->getConfig();
        $serverFactory = new ServerFactory();
        $serverFactory->setLogger($loggerFactory->create(ServerFactory::class));
        $serverFactory->setEventDispatcher($eventDispatcher);
        $serverFactory->enablePhpServer($config->getBool('application.server.enable_php_server'));
        if ($serverConfig->getPort()->isHttpProtocol()) {
            $serverFactory->setHttpMessageFactoryHolder($container->get(HttpMessageFactoryHolder::class));
            $serverFactory->setSwooleRequestBridge($container->get(SwooleRequestBridgeInterface::class));
            $serverFactory->setSwooleResponseBridge($container->get(SwooleResponseBridgeInterface::class));
        }

        return $serverFactory->create($serverConfig);
    }

    #[Bean]
    public function serverConfig(): ServerConfig
    {
        $config = Application::getInstance()->getConfig();
        $tcpSettings = [
            ServerSetting::OPEN_LENGTH_CHECK => true,
            ServerSetting::PACKAGE_LENGTH_TYPE => 'N',
            ServerSetting::PACKAGE_LENGTH_OFFSET => 0,
            ServerSetting::PACKAGE_BODY_OFFSET => 0,
            ServerSetting::MAX_WAIT_TIME => 60,
            ServerSetting::RELOAD_ASYNC => true,
            ServerSetting::PACKAGE_MAX_LENGTH => 10485760,
            ServerSetting::OPEN_TCP_NODELAY => true,
            ServerSetting::OPEN_EOF_CHECK => false,
            ServerSetting::OPEN_EOF_SPLIT => false,
        ];
        $mainSettings = [
            ServerSetting::WORKER_NUM => env('SERVER_WORKER_NUM'),
            ServerSetting::TASK_WORKER_NUM => env('SERVER_TASK_WORKER_NUM'),
            ServerSetting::DISPATCH_MODE => 2,
            ServerSetting::DAEMONIZE => false,
        ];
        $settings = array_merge($mainSettings, $config->get('application.server.settings', $config->get('application.swoole', [])));
        $allPortConfig = $config->get('application.server.ports', []);

        $ports = [];
        foreach ($allPortConfig as $port => $portConfig) {
            if (isset($ports[$port])) {
                throw new InvalidArgumentException("Port $port was duplicated");
            }
            if (is_string($portConfig)) {
                $portConfig = [
                    'protocol' => $portConfig,
                ];
            }
            $portSettings = $portConfig;
            if (0 === count($ports)) {
                $portSettings += $settings;
            }
            $serverType = isset($portConfig['protocol']) ? ServerType::from($portConfig['protocol']) : ServerType::HTTP;
            if (ServerType::TCP === $serverType) {
                $portSettings += $tcpSettings;
            }
            $ports[$port] = new ServerPort(
                $portConfig['host'] ?? '0.0.0.0',
                (int) $port,
                $serverType,
                $portConfig['listener'] ?? null,
                $portSettings
            );
        }

        $serverConfig = new ServerConfig($config->getString('application.name', 'app'), $ports);
        $serverConfig->setMasterPidFile($config->get('application.logging.path').'/master.pid');

        return $serverConfig;
    }
}
