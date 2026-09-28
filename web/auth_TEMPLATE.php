<?php
// OData via Mímir (aanbevolen). Alleen in web/auth.php zetten — niet committen.
// Zonder $mimirApi blijft het BC-pad hieronder actief.
// $mimirApi  = 'mimir_…';              // verplicht om Mímir te activeren
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
//
// Met $mimirApi gaan reads eerst naar Mímir. Faalt die, dan valt Ploutos terug
// op het BC-pad hieronder. Laat $auth_list, $environment, $auth en de volledige
// $base (BC-host + company) daarom naast $mimirApi staan. Een Company-pad
// zonder host is niet genoeg voor die fallback.
// $base = "/ODataV4/Company('COMPANY')/";

$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$base = "https://DOMAIN.nl:7148/$environment/ODataV4/Company('COMPANY')/";

$allowedUsers = [
    "user@domain.nl"
];