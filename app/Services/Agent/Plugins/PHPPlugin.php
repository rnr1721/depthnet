<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;

class PHPPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;

    public function getName(): string
    {
        return 'php';
    }

    public function execute(string $content): string
    {
        try {
            if (strpos($content, '?>') !== false) {
                $content = substr($content, 0, strpos($content, '?>'));
            }
            $content = preg_replace('/^\s*<\?(php)?/', '', $content);

            ob_start();
            eval($content);
            $result = ob_get_clean();

            return $result ?: 'Code executed successfully with no output.';
        } catch (\Throwable $e) {
            return "Error while executing PHP code: " . $e->getMessage();
        }
    }

    public function getDescription(): string
    {
        return 'You can execute any PHP code and get the results of execution or errors. You can use it to manage database, creating API requests etc.';
    }

    public function getInstructions(): array
    {
        return [
            'Execute simple PHP code: [php]echo "Hello World!";[/php]',
            'Mathematical calculations: [php]$result = 15 * 8 + 45; echo "Result: $result";[/php]',
            'Database connection: [php]$pdo = new PDO("mysql:host=localhost;dbname=test", "user", "pass"); echo "Connected";[/php]',
            'Create table: [php]$sql = "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))"; $pdo->exec($sql); echo "Table created";[/php]',
            'Insert data: [php]$stmt = $pdo->prepare("INSERT INTO users (name) VALUES (?)"); $stmt->execute(["John"]); echo "User added";[/php]',
            'Select data: [php]$stmt = $pdo->query("SELECT * FROM users"); while($row = $stmt->fetch()) { echo $row["name"] . "\n"; }[/php]',
            'HTTP GET request: [php]$data = file_get_contents("https://api.example.com/users"); echo $data;[/php]',
            'POST request with cURL: [php]$ch = curl_init(); curl_setopt($ch, CURLOPT_URL, "https://api.example.com/data"); curl_setopt($ch, CURLOPT_POST, 1); $result = curl_exec($ch); echo $result;[/php]',
            'Read file: [php]$content = file_get_contents("data.txt"); echo $content;[/php]',
            'Write file: [php]file_put_contents("output.txt", "Hello World"); echo "File written";[/php]',
            'JSON operations: [php]$array = ["name" => "John", "age" => 25]; $json = json_encode($array); echo $json;[/php]',
            'Current date: [php]echo date("Y-m-d H:i:s");[/php]',
            'Date calculations: [php]$tomorrow = date("Y-m-d", strtotime("+1 day")); echo "Tomorrow: $tomorrow";[/php]',
            'String manipulation: [php]$text = "Hello World"; echo strtoupper($text); echo strlen($text);[/php]',
            'Regular expressions: [php]$text = "Email: user@example.com"; if(preg_match("/[\w\.-]+@[\w\.-]+/", $text, $matches)) { echo "Found: " . $matches[0]; }[/php]',
            'Array operations: [php]$fruits = ["apple", "banana", "orange"]; foreach($fruits as $fruit) { echo "$fruit\n"; }[/php]',
            'Associative arrays: [php]$user = ["name" => "John", "email" => "john@example.com"]; echo $user["name"];[/php]',
            'Try-catch example: [php]try { $result = 10/0; } catch(DivisionByZeroError $e) { echo "Error: " . $e->getMessage(); }[/php]'
        ];
    }
}
