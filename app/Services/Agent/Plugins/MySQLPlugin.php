<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MySQLPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;

    public function getName(): string
    {
        return 'mysql';
    }

    public function getDescription(): string
    {
        return 'MySQL database operations with query execution, schema inspection, and table analysis.';
    }

    public function getInstructions(): array
    {
        return [
            'Execute any custom query: [mysql]Your SQL query[/mysql]',
            'Show tables in database: [mysql tabes][/mysql]',
            'Describe tables: [mysql describe][/mysql]',
            'Show database info: [mysql info][/mysql]',
            'Count records in table: [mysql count]table[/mysql]',
        ];
    }

    /**
     * Default method - execute any allowed query
     */
    public function execute(string $content): string
    {
        return $this->query($content);
    }

    /**
     * Execute a database query (SELECT, SHOW, DESCRIBE only)
     */
    public function query(string $content): string
    {
        try {
            // Restrictions? Why? But if we neeed to restrict queries, we can uncomment this block
            /*
            if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)/i', trim($content))) {
                return 'Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed for security.';
            }
            */

            $results = DB::select($content);

            if (empty($results)) {
                return 'Query executed successfully. No results returned.';
            }

            return "Query results (" . count($results) . " rows):\n" .
                   json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            Log::error("MySQLPlugin::query error: " . $e->getMessage(), [
                'query' => $content
            ]);
            return "Database error: " . $e->getMessage();
        }
    }

    /**
     * Show all tables in the database
     */
    public function tables(string $content): string
    {
        try {
            $results = DB::select('SHOW TABLES');

            if (empty($results)) {
                return 'No tables found in the database.';
            }

            $tableNames = array_map(function ($row) {
                return array_values((array)$row)[0];
            }, $results);

            return "Database tables (" . count($tableNames) . "):\n" .
                   implode("\n", array_map(fn ($name) => "- $name", $tableNames));

        } catch (\Throwable $e) {
            Log::error("MySQLPlugin::tables error: " . $e->getMessage());
            return "Error retrieving tables: " . $e->getMessage();
        }
    }

    /**
     * Describe table structure
     */
    public function describe(string $content): string
    {
        try {
            $tableName = trim($content);

            if (empty($tableName)) {
                return 'Please specify a table name to describe.';
            }

            $tableExists = DB::select("SHOW TABLES LIKE ?", [$tableName]);
            if (empty($tableExists)) {
                return "Table '$tableName' does not exist.";
            }

            $results = DB::select("DESCRIBE `$tableName`");

            if (empty($results)) {
                return "No structure information for table '$tableName'.";
            }

            $output = "Structure of table '$tableName':\n";
            $output .= str_repeat("=", 50) . "\n";

            foreach ($results as $column) {
                $col = (array)$column;
                $output .= sprintf(
                    "%-20s %-15s %-8s %-8s %-10s %s\n",
                    $col['Field'],
                    $col['Type'],
                    $col['Null'],
                    $col['Key'],
                    $col['Default'] ?? 'NULL',
                    $col['Extra']
                );
            }

            return $output;

        } catch (\Throwable $e) {
            Log::error("MySQLPlugin::describe error: " . $e->getMessage(), [
                'table' => $content
            ]);
            return "Error describing table: " . $e->getMessage();
        }
    }

    /**
     * Show database information
     */
    public function info(string $content): string
    {
        try {
            $dbName = DB::select('SELECT DATABASE() as db_name')[0]->db_name;
            $tables = DB::select('SHOW TABLES');
            $version = DB::select('SELECT VERSION() as version')[0]->version;

            $output = "Database Information:\n";
            $output .= str_repeat("=", 30) . "\n";
            $output .= "Database: $dbName\n";
            $output .= "MySQL Version: $version\n";
            $output .= "Tables: " . count($tables) . "\n";

            if (!empty($tables)) {
                $output .= "\nTable List:\n";
                foreach ($tables as $table) {
                    $tableName = array_values((array)$table)[0];

                    try {
                        $count = DB::select("SELECT COUNT(*) as count FROM `$tableName`")[0]->count;
                        $output .= "- $tableName ($count rows)\n";
                    } catch (\Exception $e) {
                        $output .= "- $tableName (unknown rows)\n";
                    }
                }
            }

            return $output;

        } catch (\Throwable $e) {
            Log::error("MySQLPlugin::info error: " . $e->getMessage());
            return "Error retrieving database info: " . $e->getMessage();
        }
    }

    /**
     * Count records in a table
     */
    public function count(string $content): string
    {
        try {
            $tableName = trim($content);

            if (empty($tableName)) {
                return 'Please specify a table name to count records.';
            }

            $tableExists = DB::select("SHOW TABLES LIKE ?", [$tableName]);
            if (empty($tableExists)) {
                return "Table '$tableName' does not exist.";
            }

            $result = DB::select("SELECT COUNT(*) as count FROM `$tableName`")[0];
            $count = $result->count;

            return "Table '$tableName' contains $count records.";

        } catch (\Throwable $e) {
            Log::error("MySQLPlugin::count error: " . $e->getMessage(), [
                'table' => $content
            ]);
            return "Error counting records: " . $e->getMessage();
        }
    }

    /**
     * Show recent records from a table (LIMIT 10)
     */
    public function peek(string $content): string
    {
        try {
            $tableName = trim($content);

            if (empty($tableName)) {
                return 'Please specify a table name to peek at records.';
            }

            $tableExists = DB::select("SHOW TABLES LIKE ?", [$tableName]);
            if (empty($tableExists)) {
                return "Table '$tableName' does not exist.";
            }

            $results = DB::select("SELECT * FROM `$tableName` LIMIT 10");

            if (empty($results)) {
                return "Table '$tableName' is empty.";
            }

            return "First 10 records from '$tableName':\n" .
                   json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            Log::error("MySQLPlugin::peek error: " . $e->getMessage(), [
                'table' => $content
            ]);
            return "Error peeking at table: " . $e->getMessage();
        }
    }
}
