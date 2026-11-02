<?php

// Deliberately NO permanent local/public disk for user content. Photos live in object storage
// (see config/agriscan.php). The "local" disk exists only for framework scratch files.
return [
    'default' => 'local',
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'throw' => false, 'report' => false],
    ],
    'links' => [],
];
