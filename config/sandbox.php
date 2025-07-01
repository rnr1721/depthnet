<?php

return [

    'enabled' => env('COMPOSE_PROFILES') && 
                        (str_contains(env('COMPOSE_PROFILES'), 'sandbox') || 
                         str_contains(env('COMPOSE_PROFILES'), 'full')),

    /**
     * Sandbox container configuration
     */
    'manager' => [
        'container_name' => env('SANDBOX_MANAGER_CONTAINER', 'depthnet-sandbox-manager-1'),
        'script_path' => env('SANDBOX_MANAGER_SCRIPT_PATH', '/sandbox-manager/scripts/manager.sh'),
        'container_pattern' => env('SANDBOX_MANAGER_PATTERN', 'sandbox-manager'),
        'network' => env('SANDBOX_NETWORK', 'depthnet_depthnet'),
        'debug' => (bool) env('SANDBOX_DEBUG', false)
    ],

    /**
     * Sandbox instance configuration
     */
    'sandbox' => [
        'prefix' => env('SANDBOX_PREFIX', 'depthnet-sandbox'),
        'default_type' => env('SANDBOX_DEFAULT_TYPE', 'ubuntu-full'),
        'default_timeout' => (int) env('SANDBOX_DEFAULT_TIMEOUT', 30),
        'templates_dir' => 'sandboxes/templates',
    ],

    /**
     * Resource limits
     */
    'limits' => [
        'memory' => env('SANDBOX_MEMORY', '512m'),
        'cpus' => env('SANDBOX_CPUS', '1.0'),
        'max_execution_time' => (int) env('SANDBOX_DEFAULT_TIMEOUT', 60),
    ],

    /**
     * Supported programming languages configuration
     */
    'languages' => [
        'python' => [
            'extension' => 'py',
            'display_name' => 'Python',
            'interpreter' => 'python3',
            'package_manager' => 'pip',
            'install_command' => 'pip3 install',
            'version_command' => 'python3 --version',
        ],
        'javascript' => [
            'extension' => 'js',
            'display_name' => 'JavaScript',
            'interpreter' => 'node',
            'package_manager' => 'npm',
            'install_command' => 'npm install -g',
            'version_command' => 'node --version',
        ],
        'php' => [
            'extension' => 'php',
            'display_name' => 'PHP',
            'interpreter' => 'php',
            'package_manager' => 'composer',
            'install_command' => 'composer require',
            'version_command' => 'php --version',
        ],
        'bash' => [
            'extension' => 'sh',
            'display_name' => 'Bash',
            'interpreter' => 'bash',
            'package_manager' => 'apt',
            'install_command' => 'apt-get update && apt-get install -y',
            'version_command' => 'bash --version',
        ],
    ],

    /**
     * Code templates for different languages
     */
    'templates' => [
        'python' => [
            [
                'name' => 'Hello World',
                'code' => 'print("Hello, World!")'
            ],
            [
                'name' => 'Math Example',
                'code' => 'import math

# Calculate factorial
def factorial(n):
    if n <= 1:
        return 1
    return n * factorial(n - 1)

number = 5
result = factorial(number)
print(f"Factorial of {number} is {result}")

# Math operations
print(f"Square root of 16: {math.sqrt(16)}")
print(f"Pi: {math.pi}")'
            ],
            [
                'name' => 'File Operations',
                'code' => '# Create and write to file
with open(\'test.txt\', \'w\') as f:
    f.write("Hello from Python!")

# Read from file
with open(\'test.txt\', \'r\') as f:
    content = f.read()
    print(f"File content: {content}")

# List current directory
import os
print("Current directory contents:")
for item in os.listdir(\'.\'):
    print(f"- {item}")'
            ]
        ],
        'javascript' => [
            [
                'name' => 'Hello World',
                'code' => 'console.log("Hello, World!");'
            ],
            [
                'name' => 'Array Operations',
                'code' => '// Array operations
const numbers = [1, 2, 3, 4, 5];

// Map and filter
const doubled = numbers.map(n => n * 2);
const evens = numbers.filter(n => n % 2 === 0);

console.log("Original:", numbers);
console.log("Doubled:", doubled);
console.log("Even numbers:", evens);

// Reduce
const sum = numbers.reduce((acc, n) => acc + n, 0);
console.log("Sum:", sum);'
            ],
            [
                'name' => 'Async Example',
                'code' => '// Async/await example
function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function main() {
    console.log("Starting...");
    
    await delay(1000);
    console.log("1 second passed");
    
    await delay(1000);
    console.log("2 seconds passed");
    
    console.log("Done!");
}

main().catch(console.error);'
            ]
        ],
        'php' => [
            [
                'name' => 'Hello World',
                'code' => "<?php\necho \"Hello, World!\\n\";"
            ],
            [
                'name' => 'Array Example',
                'code' => '<?php
// Array operations
$numbers = [1, 2, 3, 4, 5];

echo "Original array: " . implode(", ", $numbers) . "\\n";

// Map
$doubled = array_map(function($n) { return $n * 2; }, $numbers);
echo "Doubled: " . implode(", ", $doubled) . "\\n";

// Filter
$evens = array_filter($numbers, function($n) { return $n % 2 === 0; });
echo "Even numbers: " . implode(", ", $evens) . "\\n";

// Sum
$sum = array_sum($numbers);
echo "Sum: $sum\\n";'
            ],
            [
                'name' => 'Class Example',
                'code' => '<?php
class Calculator {
    public function add($a, $b) {
        return $a + $b;
    }
    
    public function multiply($a, $b) {
        return $a * $b;
    }
    
    public function factorial($n) {
        if ($n <= 1) return 1;
        return $n * $this->factorial($n - 1);
    }
}

$calc = new Calculator();
echo "5 + 3 = " . $calc->add(5, 3) . "\\n";
echo "5 * 3 = " . $calc->multiply(5, 3) . "\\n";
echo "5! = " . $calc->factorial(5) . "\\n";'
            ]
        ],
        'bash' => [
            [
                'name' => 'Hello World',
                'code' => "#!/bin/bash\necho \"Hello, World!\""
            ],
            [
                'name' => 'System Info',
                'code' => '#!/bin/bash
echo "=== System Information ==="
echo "Date: $(date)"
echo "User: $(whoami)"
echo "Working directory: $(pwd)"
echo "Disk usage:"
df -h | head -5
echo ""
echo "Memory info:"
free -h'
            ],
            [
                'name' => 'File Processing',
                'code' => '#!/bin/bash
# Create test files
echo "Creating test files..."
echo "Line 1" > file1.txt
echo -e "Line 2\\nLine 3" > file2.txt

# Process files
echo "File contents:"
for file in *.txt; do
    echo "=== $file ==="
    cat "$file"
    echo ""
done

# Count lines
echo "Line counts:"
wc -l *.txt

# Cleanup
rm -f file1.txt file2.txt
echo "Cleanup done."'
            ]
        ]
    ],

    /**
     * Quick commands for command execution
     */
    'quick_commands' => [
        [
            'label' => 'ls -la',
            'command' => 'ls -la'
        ],
        [
            'label' => 'pwd',
            'command' => 'pwd'
        ],
        [
            'label' => 'whoami',
            'command' => 'whoami'
        ],
        [
            'label' => 'ps aux',
            'command' => 'ps aux'
        ],
        [
            'label' => 'df -h',
            'command' => 'df -h'
        ],
        [
            'label' => 'free -h',
            'command' => 'free -h'
        ],
        [
            'label' => 'python --version',
            'command' => 'python --version'
        ],
        [
            'label' => 'node --version',
            'command' => 'node --version'
        ],
        [
            'label' => 'php --version',
            'command' => 'php --version'
        ]
    ]
];
