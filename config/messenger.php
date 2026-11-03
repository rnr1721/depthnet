<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram (tgcli)
    |--------------------------------------------------------------------------
    |
    | binary   — tgcli entry point. The Docker image installs a wrapper at
    |            /usr/local/bin/telegram. Requires tgcli >= 1.1 (--json).
    | data_dir — base directory; each preset gets <data_dir>/preset_<id>.
    |            Same layout as the old TelegramService, so existing sessions
    |            are picked up as-is.
    | timeout  — seconds per call. Baseline scans and downloads get 120s.
    |
    */

    'telegram' => [
        'binary'   => env('TGCLI_BINARY', 'telegram'),
        'data_dir' => env('TELEGRAM_DATA_DIR', '/shared/telegram'),
        'timeout'  => (int) env('TGCLI_TIMEOUT', 60),
    ],

];
