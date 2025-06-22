<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registered Adapters
    |--------------------------------------------------------------------------
    |
    | List of adapter classes to automatically register
    |
    */
    'adapters' => [
        \rnr1721\CodeCraft\Adapters\PhpAdapter::class,
        \rnr1721\CodeCraft\Adapters\JavaScriptAdapter::class,
        \rnr1721\CodeCraft\Adapters\TypeScriptAdapter::class,
        \rnr1721\CodeCraft\Adapters\PythonAdapter::class,
        \rnr1721\CodeCraft\Adapters\CssAdapter::class,
        \rnr1721\CodeCraft\Adapters\JsonAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Options
    |--------------------------------------------------------------------------
    |
    | Default options for each file extension
    |
    */
    'defaults' => [
        'php' => [
            'namespace' => 'App',
            'strict_types' => true,
        ],
        'jsx' => [
            'functional' => true,
        ],
        'ts' => [
            'strict' => true,
        ],
    ],
];
