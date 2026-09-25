<?php
// OData via Mímir (aanbevolen). Alleen in web/auth.php zetten — niet committen.
// Zonder $mimirApi blijft het BC-pad hieronder actief.
// $mimirApi  = 'mimir_…';              // verplicht om Mímir te activeren
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
//
// Bij Mímir zijn $auth_list, $environment en $auth niet nodig voor reads.
// $base is optioneel: een Company-pad zonder BC-host volstaat als er meerdere
// bedrijven zijn. Bij precies één bedrijf vult Ploutos $base zelf.
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