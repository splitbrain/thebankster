<?php

namespace splitbrain\TheBankster;

use ORM\DbConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

/**
 * Class Container
 *
 * @property EntityManager db
 * @property Twig view
 */
class Container extends \Slim\Container
{
    /** @var Container */
    static protected $instance;

    /** @var  LoggerInterface */
    protected $logger;

    /**
     * Returns the initialized singleton instance of the container
     *
     * @return Container
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Container(self::loadConfig());
        }
        return self::$instance;
    }

    /**
     * Load the configuration from config.yaml and environment variables
     *
     * Environment variables override config.yaml values.
     * @todo might be better to use vlucas/phpdotenv for this
     * @return array
     */
    public static function loadConfig()
    {
        $configuration = \Spyc::YAMLLoad(__DIR__ . '/../config.yaml');

        // load overrides from envrionment using getenv()
        // keys are UPPERCASE and use THEBANKSTER_ as prefix
        foreach ($configuration as $toplevel => $item) {
            if (is_array($item)) {
                foreach ($item as $key => $value) {
                    $envkey = 'THEBANKSTER_' . strtoupper($toplevel) . '_' . strtoupper($key);
                    $envval = getenv($envkey);
                    if ($envval !== false) {
                        // cast to the type of the original value
                        settype($envval, gettype($value));
                        $configuration[$toplevel][$key] = $envval;
                    }
                }
            } else {
                $envkey = 'THEBANKSTER_' . strtoupper($toplevel);
                $envval = getenv($envkey);
                if ($envval !== false) {
                    // cast to the type of the original value
                    settype($envval, gettype($item));
                    $configuration[$toplevel] = $envval;
                }
            }
        }
        return $configuration;
    }

    /**
     * Container constructor.
     *
     * You should not call this, but use getInstance() instead
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        // default logger
        $this->logger = new NullLogger();

        // we want exceptions
        set_error_handler([$this, 'errorHandler']);

        // DataBase Entity Manager (always initialized)
        $this['db'] = new EntityManager([
            EntityManager::OPT_CONNECTION => new DbConfig(
                'sqlite',
                __DIR__ . '/../data/data.sqlite3'
            )
        ]);
        $this['db']->getConnection()->exec('PRAGMA foreign_keys = ON');
        $this['db']->getConnection()->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // create the Twig view
        $this['view'] = function () {
            $view = new Twig(__DIR__ . '/../views', [
                'cache' => false,
                'debug' => true,
            ]);

            $view->getEnvironment()->addFilter(
                new \Twig\TwigFilter(
                    'autolink',
                    'autolink',
                    [
                        'pre_escape' => 'html',
                        'is_safe' => array('html')
                    ]
                )
            );

            // add basepath
            $view->getEnvironment()->addGlobal('base_path', rtrim($this->request->getUri()->getBasePath(), '/'));

            $view->addExtension(new \Slim\Views\TwigExtension(
                $this->router,
                $this->request->getUri()
            ));
            return $view;
        };

        // custom error handlers
        $this['errorHandler'] = function ($c) {
            return new ErrorHandler($c);
        };
        $this['phpErrorHandler'] = function ($c) {
            return new ErrorHandler($c);
        };
        $this['notFoundHandler'] = function ($c) {
            return function ($request, $response) use ($c) {
                return (new ErrorHandler($c))->notFound($request, $response);
            };
        };
    }

    /**
     * Error handler to convert old school warnings, notices, etc to exceptions
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return bool
     * @throws \ErrorException
     */
    public function errorHandler(int $errno, string $errstr, string $errfile, int $errline)
    {
        if (!(error_reporting() & $errno)) {
            // This error code is not included in error_reporting, so let it fall
            // through to the standard PHP error handler
            return false;
        }
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Change the logger from the default
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get the logger
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }
}
