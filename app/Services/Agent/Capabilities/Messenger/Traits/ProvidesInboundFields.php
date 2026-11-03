<?php

namespace App\Services\Agent\Capabilities\Messenger\Traits;

use App\Services\Agent\Capabilities\Messenger\DTO\InboundSettings;

/**
 * Inbound-policy config fields shared by every messenger driver.
 *
 * The policy (who gets through, what wakes the agent, pool vs history) is not
 * a property of Telegram or WhatsApp — it is the operator's decision about the
 * agent. So, like ProvidesNormalizationFields for vision, the fields live in
 * one place and every driver merges them into its own getConfigFields().
 *
 * Read back through InboundSettings::fromConfig().
 */
trait ProvidesInboundFields
{
    protected function inboundFields(): array
    {
        return [
            'inbound_mode' => [
                'type'        => 'select',
                'label'       => 'Incoming messages',
                'description' => 'Which incoming messages reach the agent. "Everyone" means any '
                    . 'stranger who writes to this account can put text into the agent\'s context '
                    . 'and wake it — use with care on an agent that has tools.',
                'required'    => false,
                'options'     => [
                    InboundSettings::MODE_OFF       => 'Off — the agent only reads when it decides to',
                    InboundSettings::MODE_WHITELIST => 'Whitelist — only listed people and chats',
                    InboundSettings::MODE_ALL       => 'Everyone',
                ],
            ],
            'inbound_whitelist' => [
                'type'        => 'textarea',
                'label'       => 'Whitelist',
                'description' => '@usernames or ids, separated by commas or new lines. A group '
                    . 'message passes if the group or its author is listed.',
                'placeholder' => "@alena_p\n-1001820894363",
                'required'    => false,
            ],
            'inbound_groups' => [
                'type'        => 'select',
                'label'       => 'Groups and channels',
                'description' => 'Private messages always wake the agent. For group traffic, '
                    . 'only mentions and replies to the account do.',
                'required'    => false,
                'options'     => [
                    InboundSettings::GROUPS_IGNORE   => 'Ignore group messages',
                    InboundSettings::GROUPS_MENTIONS => 'Only mentions and replies to me',
                    InboundSettings::GROUPS_ALL      => 'All group messages (mentions wake the agent)',
                ],
            ],
            'reply_via_speak' => [
                'type'        => 'checkbox',
                'label'       => 'Reply through speak',
                'description' => 'When someone writes to the agent here, its [speak] goes back to that chat '
                    . '(and still shows in the web chat). Ends when someone writes from the web, '
                    . 'or after the reply window.',
                'required'    => false,
            ],
            'reply_window_minutes' => [
                'type'        => 'number',
                'label'       => 'Reply window (minutes)',
                'description' => 'How long after the last incoming message [speak] keeps going to that chat.',
                'min'         => 1,
                'max'         => 1440,
                'required'    => false,
            ],
            'mark_read' => [
                'type'        => 'checkbox',
                'label'       => 'Mark delivered messages as read',
                'description' => 'The sender sees the message as read once it has reached the agent.',
                'required'    => false,
            ],
            'send_to_pool' => [
                'type'        => 'checkbox',
                'label'       => 'Route to input pool',
                'description' => 'When ON and the preset uses input-pool mode, incoming messages go '
                    . 'to the input pool instead of the message history.',
                'required'    => false,
            ],
        ];
    }

    protected function inboundDefaults(): array
    {
        return [
            'inbound_mode'      => InboundSettings::MODE_OFF,
            'inbound_whitelist' => '',
            'inbound_groups'    => InboundSettings::GROUPS_IGNORE,
            'send_to_pool'      => false,
            'reply_via_speak'   => true,
            'reply_window_minutes' => (int) (InboundSettings::DEFAULT_REPLY_WINDOW / 60),
            'mark_read'         => true,
        ];
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, string>
     */
    protected function validateInbound(array $config): array
    {
        $errors = [];

        if (($config['inbound_mode'] ?? null) === InboundSettings::MODE_WHITELIST
            && InboundSettings::parseWhitelist($config['inbound_whitelist'] ?? '') === []) {
            $errors['inbound_whitelist'] = 'Whitelist mode needs at least one @username or id.';
        }

        return $errors;
    }
}
