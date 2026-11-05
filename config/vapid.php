<?php

declare(strict_types=1);

return [
    'subject' => getenv('VAPID_SUBJECT') ?: 'mailto:admin@qrpager.io',
    'public_key' => getenv('VAPID_PUBLIC_KEY') ?: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
    'private_key' => getenv('VAPID_PRIVATE_KEY') ?: 'UUx2Vy1t-rA8k_FwY7uXq7y65Z5aQx7w9rT3vP1sN8m',
];
