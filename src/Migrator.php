<?php

namespace Arrilot\BitrixMigrations;

use Arrilot\BitrixMigrations\Interfaces\DatabaseStorageInterface;
use Arrilot\BitrixMigrations\Interfaces\FileStorageInterface;
use Arrilot\BitrixMigrations\Interfaces\MigrationInterface;
use Arrilot\BitrixMigrations\Storages\BitrixDatabaseStorage;
use Arrilot\BitrixMigrations\Storages\FileStorage;
use DateTime;
use Exception;
use Illuminate\Support\Str;

class Migrator
{
    /**
     * Migrator configuration array.
     *
     * @var array
     */
    protected $config;

    /**
     * Files interactions.
     *
     * @var FileStorageInterface
     */
    protected $files;

    /**
     * Interface that gives us access to the database.
     *
     * @var DatabaseStorageInterface
     */
    protected $database;

    /**
     * TemplatesCollection instance.
     *
     * @var TemplatesCollection
     */
    protected $templates;

    /**
     * Constructor.
     *
     * @param array $config
     * @param TemplatesCollection $templates
     * @param DatabaseStorageInterface $database
     * @param FileStorageInterface $files
     */
    public function __construct($config, TemplatesCollection $templates, DatabaseStorageInterface $database = null, FileStorageInterface $files = null)
    {
        $this->config = $config;
        $this->dir = $config['dir'];

        $this->templates = $templates;
        $this->database = $database ?: new BitrixDatabaseStorage($config['table']);
        $this->files = $files ?: new FileStorage();
    }

    /**
     * Create migration file.
     *
     * @param $name - migration name
     * @param $templateName
     * @param $replace - array of placeholders that should be replaced with a given values.
     *
     * @return string
     */
    public function createMigration($name, $templateName, array $replace = [])
    {
        $this->files->createDirIfItDoesNotExist($this->dir);

        $fileName = $this->constructFileName($name);
        $className = $this->getMigrationClassNameByFileName($fileName);
        $templateName = $this->templates->selectTemplate($templateName);

        $template = $this->files->getContent($this->templates->getTemplatePath($templateName));
        $template = $this->replacePlaceholdersInTemplate($template, array_merge($replace, ['className' => $className]));

        $this->files->putContent($this->dir.'/'.$fileName.'.php', $template);

        return $fileName;
    }

    /**
     * Run all migrations that were not run before.
     */
    public function runMigrations()
    {
        $migrations = $this->getMigrationsToRun();
        $ran = [];

        if (empty($migrations)) {
            return $ran;
        }

        foreach ($migrations as $migration) {
            $this->runMigration($migration);
            $ran[] = $migration;
        }

        return $ran;
    }

    /**
     * Run a given migration.
     *
     * @param string $file
     *
     * @return string
     *
     * @throws Exception
     */
    public function runMigration($file)
    {
        $migration = $this->getMigrationObjectByFileName($file);

        if ($migration->up() === false) {
            throw new Exception("Migration up from {$file}.php returned false");
        }

        $this->logSuccessfulMigration($file);
    }

    /**
     * Log successful migration.
     *
     * @param $migration
     *
     * @return bool
     */
    public function logSuccessfulMigration($migration)
    {
        $this->database->logSuccessfulMigration($migration);
    }

    /**
     * Get ran migrations
     *
     * @return array
     */
    public function getRanMigrations()
    {
        return $this->database->getRanMigrations();
    }

    /**
     * Determine whether migration file for migration exists.
     *
     * @param string $migration
     * @return bool
     */
    public function doesMigrationFileExist($migration)
    {
        return $this->files->exists($this->getMigrationFilePath($migration));
    }

    /**
     * Rollback a given migration.
     *
     * @param string $file
     * @return mixed
     * @throws Exception
     */
    public function rollbackMigration($file)
    {
        $migration = $this->getMigrationObjectByFileName($file);

        if ($migration->down() === false) {
            throw new Exception("<error>Can't rollback migration:</error> {$file}.php");
        }

        $this->removeSuccessfulMigrationFromLog($file);
    }

    /**
     * Remove a migration name from the database so it can be run again.
     *
     * @param string $file
     *
     * @return void
     */
    public function removeSuccessfulMigrationFromLog($file)
    {
        $this->database->removeSuccessfulMigrationFromLog($file);
    }

    /**
     * Construct migration file name from migration name and current time.
     *
     * @param $name
     *
     * @return string
     */
    protected function constructFileName($name)
    {
        list($usec, $sec) = explode(' ', microtime());

        $usec = substr($usec, 2, 6);

        return date('Y_m_d_His', $sec).'_'.$usec.'_'.$name;
    }

    /**
     * Get a migration class name by a migration file name.
     *
     * @param string $file
     *
     * @return string
     */
    protected function getMigrationClassNameByFileName($file)
    {
        $fileExploded = explode('_', $file);

        $datePart = implode('_', array_slice($fileExploded, 0, 5));
        $namePart = implode('_', array_slice($fileExploded, 5));

        return Str::studly($namePart.'_'.$datePart);
    }

    /**
     * Replace all placeholders in the stub.
     *
     * @param string $template
     * @param array $replace
     *
     * @return string
     */
    protected function replacePlaceholdersInTemplate($template, array $replace)
    {
        foreach ($replace as $placeholder => $value) {
            $template = str_replace("__{$placeholder}__", $value, $template);
        }

        return $template;
    }

    /**
     * Get array of migrations that should be ran.
     *
     * @return array
     */
    public function getMigrationsToRun()
    {
        $allMigrations = $this->files->getMigrationFiles($this->dir);

        $ranMigrations = $this->getRanMigrations();

        return array_diff($allMigrations, $ranMigrations);
    }

    /**
     * Resolve a migration instance from a file.
     *
     * @param string $file
     * @return MigrationInterface
     * @throws Exception
     */
    protected function getMigrationObjectByFileName($file)
    {
        $class = $this->getMigrationClassNameByFileName($file);

        $this->requireMigrationFile($file);

        $object = new $class();

        if (!$object instanceof MigrationInterface) {
            throw new Exception("Migration class {$class} must implement Arrilot\\BitrixMigrations\\Interfaces\\MigrationInterface");
        }

        return $object;
    }

    /**
     * Require migration file.
     *
     * @param string $file
     *
     * @return void
     */
    protected function requireMigrationFile($file)
    {
        $this->files->requireFile($this->getMigrationFilePath($file));
    }

    /**
     * Get path to a migration file.
     *
     * @param $file
     *
     * @return string
     */
    protected function getMigrationFilePath($file)
    {
        return $this->dir.'/'.$file.'.php';
    }
}