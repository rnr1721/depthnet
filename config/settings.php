<?php

return [
    'fields' => [

        'agent_command_parser_mode' => [
            'type' => 'select',
            'label' => 'settings_command_parser_mode',
            'description' => 'settings_command_parser_mode_desc',
            'default' => 'smart',
            'validation' => 'required|string|in:simple,smart',
            'group' => 'agent_settings',
            'order' => 2,
            'options' => [
                'simple' => 'Separate commands',
                'smart' => 'Glue similar commands'
            ],
        ],

        'model_reply_from_model' => [
            'type' => 'input',
            'input_type' => 'text',
            'label' => 'settings_reply_from_model',
            'description' => 'settings_reply_from_model_desc',
            'placeholder' => 'reply_from_model',
            'default' => 'reply_from_model',
            'validation' => 'string|max:255',
            'group' => 'agent_settings',
            'order' => 3,
        ],

        'model_message_from_user' => [
            'type' => 'input',
            'input_type' => 'text',
            'label' => 'settings_message_from_user',
            'description' => 'settings_message_from_user_desc',
            'placeholder' => 'message_from_user',
            'default' => 'message_from_user',
            'validation' => 'string|max:255',
            'group' => 'agent_settings',
            'order' => 4,
        ],

        'model_message_thinking_phrase' => [
            'type' => 'input',
            'input_type' => 'text',
            'label' => 'settings_message_thinking_phrase',
            'description' => 'settings_message_thinking_phrase_desc',
            'placeholder' => '',
            'default' => 'settings_message_thinking_phrase_def',
            'validation' => 'string|max:255',
            'group' => 'agent_settings',
            'order' => 5,
        ],

        'model_timeout_between_requests' => [
            'type' => 'input',
            'input_type' => 'number',
            'label' => 'settings_timeout_between_requests',
            'description' => 'settings_timeout_between_requests_desc',
            'placeholder' => '15',
            'default' => 15,
            'validation' => 'required|integer|min:1|max:1000',
            'group' => 'agent_settings',
            'order' => 6,
            'min' => 1,
            'max' => 1000,
        ],

        'model_max_context_limit' => [
            'type' => 'input',
            'input_type' => 'number',
            'label' => 'settings_max_context_limit',
            'description' => 'settings_max_context_limit_desc',
            'placeholder' => '8',
            'default' => 8,
            'validation' => 'required|integer|min:1|max:1000',
            'group' => 'agent_settings',
            'order' => 7,
            'min' => 1,
            'max' => 1000,
        ],

        'chat_max_chat_history' => [
            'type' => 'input',
            'input_type' => 'number',
            'label' => 'settings_max_chat_history',
            'description' => 'settings_max_chat_history_desc',
            'placeholder' => '100',
            'default' => 100,
            'validation' => 'required|integer|min:1|max:1000',
            'group' => 'chat_settings',
            'order' => 1,
            'min' => 1,
            'max' => 1000,
        ],

        'site_locale' => [
            'type' => 'select',
            'label' => 'settings_site_locale',
            'description' => 'settings_site_locale_desc',
            'default' => 'en',
            'validation' => 'required|string|in:en,ru',
            'group' => 'site_settings',
            'order' => 1,
            'options' => [
                'en' => 'English',
                'ru' => 'Russian (Русский)'
            ],
        ],
        'site_enable_registration' => [
            'type' => 'checkbox',
            'label' => 'settings_enable_registration',
            'description' => 'settings_enable_registration_desc',
            'default' => true,
            'validation' => 'boolean',
            'group' => 'site_settings',
            'order' => 2,
        ],
    ],

    'groups' => [
        'agent_settings' => [
            'label' => 'settings_group_agent',
            'description' => 'settings_group_agent_desc',
            'icon' => 'agent',
            'order' => 1,
        ],
        'chat_settings' => [
            'label' => 'settings_group_chat',
            'description' => 'settings_group_chat_desc',
            'icon' => 'chat',
            'order' => 2,
        ],
        'site_settings' => [
            'label' => 'settings_group_site',
            'description' => 'settings_group_site_desc',
            'icon' => 'site',
            'order' => 3,
        ],
    ],

    'icons' => [
        'agent' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        'chat' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        'site' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'
    ],
];
