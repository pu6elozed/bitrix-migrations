<?php

namespace Arrilot\BitrixMigrations\Repositories;

use Arrilot\BitrixMigrations\Interfaces\DatabaseRepositoryInterface;

class BitrixDatabaseRepository implements DatabaseRepositoryInterface
{
    /**
     * Bitrix $DB object.
     *
     * @var \CDatabase
     */
    protected $db;

    /**
     * Table in DB to store migrations that have been already ran.
     *
     * @var string
     */
    protected $table;

    /**
     * BitrixDatabaseRepository constructor.
     * @param $table
     */
    public function __construct($table)
    {
        global $DB;

        $this->db = $DB;
        $this->table = $table;
    }

    /**
     * Check if a given table already exists.
     *
     * @return bool
     */
    public function checkMigrationTableExistence()
    {
        return (bool) $this->db->query('SHOW TABLES LIKE "'.$this->table.'"')->fetch();
    }

    /**
     * Create migration table.
     *
     * @return void
     */
    public function createMigrationTable()
    {
        $this->db->query("CREATE TABLE {$this->table} (ID INT NOT NULL AUTO_INCREMENT, MIGRATION VARCHAR(255) NOT NULL, PRIMARY KEY (ID))");
    }

    /**
     * Get an array of migrations the have been ran previously.
     *
     * @return array
     */
    public function getRanMigrations()
    {
        $migrations = [];

        $dbRes = $this->db->query("SELECT MIGRATION FROM {$this->table} ORDER BY ID ASC");
        while ($result = $dbRes->fetch()) {
            $migrations[] = $result['MIGRATION'];
        }

        return $migrations;
    }

    /**
     * Save migration name to the database to prevent it from running again.
     *
     * @param string $fileName
     * @return void
     */
    public function logSuccessfulMigration($fileName)
    {
        $this->db->insert($this->table, [
            'MIGRATION' => "'".$this->db->forSql($fileName)."'",
        ]);
    }
}