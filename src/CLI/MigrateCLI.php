<?php

namespace splitbrain\TheBankster\CLI;

use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\PSR3CLIv3;
use splitbrain\TheBankster\Container;
use splitbrain\TheBankster\SqlHelper;

class MigrateCLI extends PSR3CLIv3
{
    const DBFILES = __DIR__ . '/../../db';

    /**
     * Register options and arguments on the given $options object
     *
     * @param Options $options
     * @return void
     */
    protected function setup(Options $options)
    {
        $options->setHelp('Upgrades the database to the latest version');
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param Options $options
     * @return void
     */
    protected function main(Options $options)
    {
        $db = Container::getInstance()->db;

        $current = $this->currentDbVersion($db->getSqlHelper());
        $upgrades = $this->getUpgradeFiles($current);

        $this->info('Current version: ' . $current);
        if (count($upgrades)) {
            $this->warning('Database is {count} version(s) behind', ['count' => count($upgrades)]);
        } else {
            $this->success('Database is up to date.');
            exit(0);
        }

        foreach ($upgrades as $version => $file) {
            $this->applyUpgrade($db->getConnection(), $file, $version);
        }

        $db->getSqlHelper()->exec('VACUUM');
    }

    /**
     * @param \PDO $pdo
     * @param string $file
     * @param int $version
     */
    protected function applyUpgrade(\PDO $pdo, $file, $version)
    {
        $this->notice('Applying version {v} from {f}.', ['f' => $file, 'v' => $version]);
        $sql = file_get_contents(self::DBFILES . '/' . $file);

        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $st = $pdo->prepare('REPLACE INTO opt ("conf", "val") VALUES (:conf, :val)');
            $st->execute([':conf' => 'dbversion', ':val' => $version]);
            $pdo->commit();
            $this->success('Upgraded to version {v}', ['v' => $version]);
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $this->error('An error occured, changes have been rolled back');
            $this->fatal($e);
        }
    }

    /**
     * Get all unapplied upgrade files
     *
     * @param int $current current version
     * @return array
     */
    protected function getUpgradeFiles($current)
    {
        $files = glob(self::DBFILES . '/*.sql');
        $upgrades = [];
        foreach ($files as $file) {
            $file = basename($file);
            if (!preg_match('/^(\d+)/', $file, $m)) continue;
            if ((int)$m[1] <= $current) continue;
            $upgrades[(int)$m[1]] = $file;
        }
        return $upgrades;
    }

    /**
     * Read the current version
     *
     * @param SqlHelper $dbhlp
     * @return int
     */
    protected function currentDbVersion(SqlHelper $dbhlp)
    {
        $sql = "SELECT val FROM opt WHERE conf = 'dbversion'";
        try {
            $version = $dbhlp->querySingleValue($sql);
            return (int)$version;
        } catch (\PDOException $ignored) {
            return 0;
        }
    }


}
