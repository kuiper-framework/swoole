<?php

declare(strict_types=1);

namespace kuiper\swoole\config;

use DI\Annotation\Inject;
use function DI\autowire;
use kuiper\di\annotation\Bean;
use kuiper\di\ContainerBuilderAwareTrait;
use kuiper\di\DefinitionConfiguration;
use kuiper\helper\Properties;
use kuiper\helper\Text;
use kuiper\logger\LoggerFactoryInterface;
use kuiper\swoole\Application;
use kuiper\swoole\constants\ServerSetting;
use kuiper\swoole\constants\ServerType;
use kuiper\swoole\event\ManagerStartEvent;
use kuiper\swoole\event\StartEvent;
use kuiper\swoole\event\WorkerStartEvent;
use kuiper\swoole\http\HttpMessageFactoryHolder;
use kuiper\swoole\http\SwooleRequestBridgeInterface;
use kuiper\swoole\http\SwooleResponseBridge;
use kuiper\swoole\http\SwooleResponseBridgeInterface;
use kuiper\swoole\listener\ManagerStartEventListener;
use kuiper\swoole\listener\StartEventListener;
use kuiper\swoole\listener\WorkerStartEventListener;
use kuiper\swoole\monolog\CoroutineIdProcessor;
use kuiper\swoole\server\ServerInterface;
use kuiper\swoole\ServerCommand;
use kuiper\swoole\ServerConfig;
use kuiper\swoole\ServerFactory;
use kuiper\swoole\ServerPort;
use kuiper\web\LineRequestLogFormatter;
use kuiper\web\middleware\AccessLog;
use kuiper\web\RequestLogFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ServerConfiguration implements DefinitionConfiguration
{
    use ContainerBuilderAwareTrait;

    public function getDefinitions(): array
    {
        $config = Application::getInstance()->getConfig();
        $config->mergeIfNotExists([
            'application' => [
                'default_command' => ServerCommand::NAME,
                'commands' => [
                    ServerCommand::NAME => ServerCommand::class,
                ],
            ],
        ]);
        $this->addAccessLoggerConfig($config);
        $this->addEventListeners();

        return [
            SwooleResponseBridgeInterface::class => autowire(SwooleResponseBridge::class),
            RequestLogFormatter::class => autowire(LineRequestLogFormatter::class),
        ];
    }

    /**
     * @Bean()
     */
    public function server(
        ContainerInterface $container,
        ServerConfig $serverConfig,
        EventDispatcherInterface $eventDispatcher,
        LoggerFactoryInterface $loggerFactory): ServerInterface
    {
        $config = Application::getInstance()->getConfig();
        $serverFactory = new ServerFactory($loggerFactory->create(ServerFactory::class));
        $serverFactory->setEventDispatcher($eventDispatcher);
        $serverFactory->enablePhpServer($config->getBool('application.server.enable-php-server'));
        if ($serverConfig->getPort()->isHttpProtocol()) {
            $serverFactory->setHttpMessageFactoryHolder($container->get(HttpMessageFactoryHolder::class));
            $serverFactory->setSwooleRequestBridge($container->get(SwooleRequestBridgeInterface::class));
            $serverFactory->setSwooleResponseBridge($container->get(SwooleResponseBridgeInterface::class));
        }

        return $serverFactory->create($serverConfig);
    }

    /**
     * @Bean()
     * @Inject({"name": "applicationName"})
     */
    public function serverConfig(string $name): ServerConfig
    {
        $app = Application::getInstance();
        $settings = $app->getConfig()->get('application.swoole', []);
        $settings = array_merge([
            ServerSetting::OPEN_LENGTH_CHECK => true,
            ServerSetting::PACKAGE_LENGTH_TYPE => 'N',
            ServerSetting::PACKAGE_LENGTH_OFFSET => 0,
            ServerSetting::PACKAGE_BODY_OFFSET => 0,
            ServerSetting::MAX_WAIT_TIME => 60,
            ServerSetting::RELOAD_ASYNC => true,
            ServerSetting::PACKAGE_MAX_LENGTH => 10485760,
            ServerSetting::OPEN_TCP_NODELAY => 1,
            ServerSetting::OPEN_EOF_CHECK => 0,
            ServerSetting::OPEN_EOF_SPLIT => 0,
            ServerSetting::DISPATCH_MODE => 2,
        ], $settings);

        $ports = [];
        foreach ($settings['ports'] as $address => $serverType) {
            if (Text::isInteger((string) $address)) {
                $port = $address;
                $host = '0.0.0.0';
            } elseif (false === strpos($address, ':')) {
                [$host, $port] = explode($address, ':');
            } else {
                throw new \InvalidArgumentException('');
            }
            if (isset($ports[$port])) {
                throw new \InvalidArgumentException("Port $port was duplicated");
            }
            $ports[$port] = new ServerPort($host, (int) $port, $serverType);
        }
        unset($settings['ports']);

        $serverConfig = new ServerConfig($name, $settings, array_values($ports));
        $serverConfig->setMasterPidFile($app->getBasePath().'/master.pid');

        return $serverConfig;
    }

    protected function addAccessLoggerConfig(Properties $config): void
    {
        $path = $config->get('application.logging.path');
        if (null !== $path) {
            $config->mergeIfNotExists([
                'application' => [
                    'logging' => [
                        'loggers' => [
                            'AccessLogLogger' => $this->createAccessLogger($path.'/access.log'),
                        ],
                        'logger' => [
                            AccessLog::class => 'AccessLogLogger',
                        ],
                    ],
                ],
            ]);
        }
        foreach ($config->get('application.swoole.ports', []) as $serverType) {
            if (ServerType::fromValue($serverType)->isHttpProtocol()) {
                $config->mergeIfNotExists([
                    'application' => [
                        'web' => [
                            'middleware' => [
                                AccessLog::class,
                            ],
                        ],
                    ],
                ]);
                break;
            }
        }
    }

    public function createAccessLogger(string $logFileName): array
    {
        return [
            'handlers' => [
                [
                    'handler' => [
                        'class' => StreamHandler::class,
                        'constructor' => [
                            'stream' => $logFileName,
                        ],
                    ],
                    'formatter' => [
                        'class' => LineFormatter::class,
                        'constructor' => [
                            'format' => "%message% %context% %extra%\n",
                        ],
                    ],
                ],
            ],
            'processors' => [
                CoroutineIdProcessor::class,
            ],
        ];
    }

    protected function addEventListeners(): void
    {
        $this->containerBuilder->defer(function (ContainerInterface $container): void {
            $dispatcher = $container->get(EventDispatcherInterface::class);
            $dispatcher->addListener(StartEvent::class, $container->get(StartEventListener::class));
            $dispatcher->addListener(ManagerStartEvent::class, $container->get(ManagerStartEventListener::class));
            $dispatcher->addListener(WorkerStartEvent::class, $container->get(WorkerStartEventListener::class));
        });
    }
}