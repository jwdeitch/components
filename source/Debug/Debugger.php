<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Debug;

use Spiral\Core\ConfiguratorInterface;
use Spiral\Core\ContainerInterface;
use Spiral\Core\Singleton;
use Spiral\Core\Traits\ConfigurableTrait;
use Spiral\Debug\Traits\LoggerTrait;

class Debugger extends Singleton
{
    /**
     * Few traits.
     */
    use ConfigurableTrait, LoggerTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = self::class;

    /**
     * Container instance is required to resolve dependencies.
     *
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * Constructing debug component. Debug is one of primary spiral component and will be available
     * for use in any environment and any application point. This is first initiated component in
     * application.
     *
     * @param ConfiguratorInterface $configurator
     * @param ContainerInterface    $container
     */
    public function __construct(ConfiguratorInterface $configurator, ContainerInterface $container)
    {
        $this->config = $configurator->getConfig($this);
    }

    /**
     * Create logger associated with specified container.
     *
     * @param $name
     * @return Logger
     */
    public function createLogger($name)
    {
        $logger = new Logger($name);

        return $logger;
    }

//    /**
//     * Benchmark method used to determinate how long time and how much memory was used to perform
//     * some specified piece of code. Method should be used twice, before and after code needs to be
//     * profile, first call will return true, second one will return time in seconds took to perform
//     * code between benchmark method calls. If Debugger::$benchmarking enabled - result will be
//     * additionally logged in Debugger::$benchmarks array and can be retrieved using
//     * Debugger::getBenchmarks() for future analysis.
//     *
//     * Example:
//     * Debugger::benchmark('parseURL', 'google.com');
//     * ...
//     * echo Debugger::benchmark('parseURL', 'google.com');
//     *
//     * @param string $record Record name.
//     * @return bool|float
//     */
//    public static function benchmark($record)
//    {
//        if (func_num_args() > 1)
//        {
//            $record = join('|', func_get_args());
//        }
//
//        if (!isset(self::$benchmarks[$record]))
//        {
//            self::$benchmarks[$record] = [
//                microtime(true),
//                memory_get_usage()
//            ];
//
//            return true;
//        }
//
//        self::$benchmarks[$record][] = microtime(true);
//        self::$benchmarks[$record][] = memory_get_usage();
//
//        return self::$benchmarks[$record][2] - self::$benchmarks[$record][0];
//    }
//
//    /**
//     * Retrieve all active and finished benchmark records, this method will return finished records
//     * only if Debugger::$benchmarking is true, in opposite case all finished records will be erased
//     * right after completion.
//     *
//     * @return array|null
//     */
//    public static function getBenchmarks()
//    {
//        return self::$benchmarks;
//    }


    //    /**
    //     * Will convert Exception to ExceptionResponse object which can be passed further to dispatcher
    //     * and handled by environment logic. Additionally error message will be recorded in "error" debug
    //     * container.
    //     *
    //     * ExceptionResponse will contain full exception explanation and rendered snapshot which can be
    //     * recorded as html file for future usage.
    //     *
    //     * @param Exception $exception
    //     * @param bool      $logException If true (default), message to error container will be added.
    //     * @return Snapshot
    //     */
    //    public function handleException(Exception $exception, $logException = true)
    //    {
    //        //We are requesting viewManager using container here to performance reasons
    //        $snapshot = new Snapshot(
    //            $exception,
    //            ViewManager::getInstance($this->container),
    //            $this->config['backtrace']['view']
    //        );
    //
    //        if ($exception instanceof ClientException)
    //        {
    //            //No logging for ClientExceptions
    //            return $snapshot;
    //        }
    //
    //        //Error message should be added to log only for non http exceptions
    //        if ($logException)
    //        {
    //            self::logger()->error($snapshot->getMessage());
    //        }
    //
    //        $filename = null;
    //        if ($this->config['backtrace']['snapshots']['enabled'])
    //        {
    //            $filename = interpolate($this->config['backtrace']['snapshots']['filename'], [
    //                'timestamp' => date($this->config['backtrace']['snapshots']['timeFormat'], time()),
    //                'exception' => $snapshot->getName()
    //            ]);
    //
    //            //We can save it now
    //            FileManager::getInstance($this->container)->write(
    //                $filename,
    //                $snapshot->renderSnapshot()
    //            );
    //        }
    //
    //        //Letting subscribers know...
    //        $this->event('snapshot', compact('snapshot', 'filename'));
    //
    //        return $snapshot;
    //    }
}