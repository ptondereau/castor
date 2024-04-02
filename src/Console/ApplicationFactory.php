<?php

namespace Castor\Console;

use Castor\Console\Command\CompileCommand;
use Castor\Console\Command\DebugCommand;
use Castor\Console\Command\RepackCommand;
use Castor\ContextRegistry;
use Castor\EventDispatcher;
use Castor\ExpressionLanguage;
use Castor\Fingerprint\FingerprintHelper;
use Castor\FunctionFinder;
use Castor\Helper\PathHelper;
use Castor\Helper\PlatformHelper;
use Castor\Helper\WaitForHelper;
use Castor\Import\Importer;
use Castor\Import\Listener\RemoteImportListener;
use Castor\Import\Remote\Composer;
use Castor\Import\Remote\PackageImporter;
use Castor\Listener\GenerateStubsListener;
use Castor\Listener\UpdateCastorListener;
use Castor\Monolog\Processor\ProcessProcessor;
use Castor\Stub\StubsGenerator;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

/** @internal */
class ApplicationFactory
{
    public static function create(): SymfonyApplication
    {
        try {
            $rootDir = PathHelper::getRoot();
        } catch (\RuntimeException $e) {
            return new CastorFileNotFoundApplication($e);
        }

        $class = Application::class;
        if (class_exists(\RepackedApplication::class)) {
            $class = \RepackedApplication::class;
        }

        $contextRegistry = new ContextRegistry();
        $httpClient = HttpClient::create([
            'headers' => [
                'User-Agent' => 'Castor/' . Application::VERSION,
            ],
        ]);
        $cacheDir = PlatformHelper::getCacheDirectory();
        $cache = new FilesystemAdapter(directory: $cacheDir);
        $logger = new Logger('castor', [], [new ProcessProcessor()]);
        $fs = new Filesystem();
        $fingerprintHelper = new FingerprintHelper($cache);
        $packageImporter = new PackageImporter($logger, new Composer($fs, $logger, $fingerprintHelper));
        $importer = new Importer($packageImporter, $logger);
        $eventDispatcher = new EventDispatcher(logger: $logger);
        $eventDispatcher->addSubscriber(new UpdateCastorListener(
            $cache,
            $httpClient,
            $logger,
        ));
        $eventDispatcher->addSubscriber(new GenerateStubsListener(
            new StubsGenerator($rootDir, $logger),
        ));
        $eventDispatcher->addSubscriber(new RemoteImportListener($packageImporter));

        /** @var Application */
        // @phpstan-ignore-next-line
        $application = new $class(
            $rootDir,
            new FunctionFinder($cache, $rootDir),
            $contextRegistry,
            $eventDispatcher,
            new ExpressionLanguage($contextRegistry),
            $logger,
            $fs,
            new WaitForHelper($httpClient, $logger),
            $fingerprintHelper,
            $importer,
            $httpClient,
            $cache,
        );

        // Avoid dependency cycle
        $packageImporter->setApplication($application);

        $application->setDispatcher($eventDispatcher);
        $application->add(new DebugCommand($rootDir, $cacheDir, $contextRegistry));

        if (!class_exists(\RepackedApplication::class)) {
            $application->add(new RepackCommand());
            $application->add(new CompileCommand($httpClient, $fs));
        }

        return $application;
    }
}
