<?php

namespace splitbrain\TheBankster;

use splitbrain\TheBankster\Controller\AccountController;
use splitbrain\TheBankster\Controller\CategoryController;
use splitbrain\TheBankster\Controller\ChartController;
use splitbrain\TheBankster\Controller\FinTsSetupController;
use splitbrain\TheBankster\Controller\HomeController;
use splitbrain\TheBankster\Controller\RuleController;
use splitbrain\TheBankster\Controller\SearchController;
use splitbrain\TheBankster\Controller\TransactionController;


/**
 * PMI Dashboard App
 */
class App
{

    /**
     * The app
     *
     * @var \Slim\App
     */
    protected $app;

    /**
     * Dependency container
     *
     * @var Container
     */
    protected $container;


    /**
     * Application constructor
     */
    public function __construct()
    {
        $c = Container::getInstance();
        $this->app = new \Slim\App($c);
        $this->container = $this->app->getContainer();
    }

    /**
     * Run application
     */
    public function run()
    {

        $this->app->get('/chart[/{top}[/{sub}]]', ChartController::class)->setName('chart');

        $this->app->any('/accounts', AccountController::class . ':listAll')->setName('accounts');
        $this->app->any('/accounts/edit/{account}', AccountController::class)->setName('account');
        $this->app->any('/accounts/del/{account}', AccountController::class . ':remove')->setName('account-del');
        $this->app->any('/accounts/new/{backend}', AccountController::class . ':add')->setName('account-new');
        $this->app->any('/accounts/fints-setup/{account}', FinTsSetupController::class)->setName('fints-setup');

        $this->app->get('/category/del/{id}', CategoryController::class . ':remove')->setName('category-del');
        $this->app->any('/category/[{id}]', CategoryController::class)->setName('category');
        $this->app->get('/categories', CategoryController::class . ':listAll')->setName('categories');

        $this->app->get('/rule/del/{id}', RuleController::class . ':remove')->setName('rule-del');
        $this->app->get('/rule/on/{id}', RuleController::class . ':enable')->setName('rule-on');
        $this->app->any('/rule/[{id}]', RuleController::class)->setName('rule');
        $this->app->get('/rules', RuleController::class . ':listAll')->setName('rules');

        $this->app->get('/uncategorized', TransactionController::class . ':uncategorized')->setName('uncategorized');
        $this->app->get('/assign/{txid}/{catid}', TransactionController::class . ':assign')->setName('assign');

        $this->app->any('/search', SearchController::class)->setName('search');
        $this->app->get('/', HomeController::class)->setName('home');

        $this->app->run();
    }
}
