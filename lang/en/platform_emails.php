<?php

declare(strict_types=1);

/*
| Default texts of the platform (account) emails. Central admins can override them in
| central Settings → Email. Placeholders: {name} {platform} {minutes}.
| The reset button is added under the body automatically.
*/

return [
    'templates' => [
        'password_reset' => [
            'subject' => 'Reset your {platform} password',
            'body' => "Hello {name},\n\nWe received a request to reset the password of your {platform} account. Use the button below to choose a new password.\n\nThe link expires in {minutes} minutes. If you did not ask for this, you can ignore this email: your password stays the same.\n\nThe {platform} team",
        ],
    ],

    'action_password_reset' => 'Reset password',
    'link_fallback' => 'If the button does not work, copy this link into your browser:',
    'sent_by' => 'Sent by :platform',

    'test_subject' => 'Test email from :platform',
    'test_body' => "This is a test email from :platform.\n\nIf you received it, the platform mailbox works and account emails (such as password resets) can be sent.",
];
