<?php
declare(strict_types=1);

$id = preg_replace('/\D+/', '', (string)($_GET['id'] ?? ''));
$lang = (($_GET['lang'] ?? 'en') === 'fr') ? 'fr' : 'en';
$isFr = $lang === 'fr';

if ($id === '') {
    http_response_code(404);
    exit('Property not found');
}

$configFile = __DIR__ . '/../proprietes/syncbroker-config.php';
$config = is_file($configFile) ? require $configFile : [];
$apiKey = is_array($config) ? trim((string)($config['api_key'] ?? getenv('SYNCBROKER_API_KEY') ?: '')) : '';
$endpoint = is_array($config) ? trim((string)($config['endpoint'] ?? 'https://app.sync.quebec/api/properties/data')) : '';
$cacheTtl = is_array($config) ? max(300, (int)($config['cache_ttl'] ?? 1800)) : 1800;
$cacheFile = __DIR__ . '/../proprietes/syncbroker-cache.json';

function fetchFeed(string $endpoint, string $apiKey, string $cacheFile, int $cacheTtl): array {
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = @file_get_contents($cacheFile);
        $data = $cached !== false ? json_decode($cached, true) : null;
        if (is_array($data)) return $data;
    }

    if ($apiKey === '' || $endpoint === '') return [];

    $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'api-key=' . rawurlencode($apiKey);
    $response = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PanoramaAdvisory/1.0',
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) $response = false;
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 18,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: PanoramaAdvisory/1.0\r\n",
        ]]);
        $response = @file_get_contents($url, false, $ctx);
    }

    $data = $response !== false ? json_decode($response, true) : null;
    if (is_array($data)) {
        @file_put_contents($cacheFile, $response, LOCK_EX);
        return $data;
    }

    if (is_file($cacheFile)) {
        $cached = @file_get_contents($cacheFile);
        $data = $cached !== false ? json_decode($cached, true) : null;
        if (is_array($data)) return $data;
    }

    return [];
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function textFromHtml($v): string {
    return trim(preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<br>','<br/>','<br />'], ' ', (string)$v))));
}

$feed = fetchFeed($endpoint, $apiKey, $cacheFile, $cacheTtl);
$property = null;
foreach ($feed as $item) {
    if ((string)($item['no_inscription'] ?? '') === $id) {
        $property = $item;
        break;
    }
}

if (!$property) {
    http_response_code(404);
    $title = $isFr ? 'Propriété introuvable' : 'Property not found';
    $back = $isFr ? '/proprietes/' : '/en/properties/';
    $backLabel = $isFr ? 'Retour aux propriétés' : 'Back to properties';
    echo '<!doctype html><html lang="'.($isFr?'fr-CA':'en-CA').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' — Panorama</title><body style="font-family:Arial,sans-serif;padding:40px"><h1>'.e($title).'</h1><p><a href="'.e($back).'">'.e($backLabel).'</a></p></body></html>';
    exit;
}

$address = trim(implode(' ', array_filter([
    $property['no_civique_debut'] ?? null,
    $property['nom_rue_complet'] ?? null,
])));
if (!empty($property['appartement'])) $address .= ' — ' . $property['appartement'];

$city = $property['municipalite']['description'] ?? '';
$type = $property['genre_propriete'][$isFr ? 'description_francaise' : 'description_anglaise']
    ?? $property['categorie_propriete'][$isFr ? 'description_francaise' : 'description_anglaise']
    ?? '';

$priceValue = $property['prix_location_demande'] ?? $property['prix_demande'] ?? null;
$price = $priceValue !== null
    ? number_format((float)$priceValue, 0, $isFr ? ',' : '.', $isFr ? ' ' : ',') . ($isFr ? ' $' : ' CAD')
    : '';

$area = '';
if (!empty($property['superficie_habitable'])) {
    $unit = $property['um_superficie_habitable'][$isFr ? 'description_abregee_francaise' : 'description_abregee_anglaise'] ?? '';
    $area = number_format((float)$property['superficie_habitable'], 0, $isFr ? ',' : '.', $isFr ? ' ' : ',') . ($unit ? ' ' . $unit : '');
} elseif (!empty($property['superficie_terrain'])) {
    $unit = $property['um_superficie_terrain'][$isFr ? 'description_abregee_francaise' : 'description_abregee_anglaise'] ?? '';
    $area = number_format((float)$property['superficie_terrain'], 0, $isFr ? ',' : '.', $isFr ? ' ' : ',') . ($unit ? ' ' . $unit : '');
}

$remarks = '';
foreach (($property['remarques'] ?? []) as $r) {
    if (($r['code_langue'] ?? '') === ($isFr ? 'F' : 'A') && !empty($r['texte'])) {
        $remarks = $r['texte'];
        break;
    }
}
if ($remarks === '') {
    $remarks = textFromHtml($property[$isFr ? 'addenda_complet_f' : 'addenda_complet_a'] ?? '');
}

$photos = $property['photos'] ?? [];
usort($photos, fn($a,$b) => ((int)($a['seq'] ?? 0)) <=> ((int)($b['seq'] ?? 0)));
$photos = array_values(array_filter($photos, fn($p) => !empty($p['photourl'])));
$hero = $photos[0]['photourl'] ?? '';

$back = $isFr ? '/proprietes/' : '/en/properties/';
$alternate = $isFr ? '/properties/'.$id.'/' : '/proprietes/'.$id.'/';
$backLabel = $isFr ? 'Toutes les propriétés' : 'All properties';
$status = $property['prix_location_demande'] !== null
    ? ($isFr ? 'À louer' : 'For lease')
    : ($isFr ? 'À vendre' : 'For sale');
$desc = mb_substr($remarks, 0, 180);
?>
<!doctype html>
<html lang="<?= $isFr ? 'fr-CA' : 'en-CA' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($address) ?> — Panorama</title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="https://panoramaadvisory.ca/<?= $isFr ? 'proprietes' : 'properties' ?>/<?= e($id) ?>/">
<link rel="alternate" hreflang="fr-CA" href="https://panoramaadvisory.ca/proprietes/<?= e($id) ?>/">
<link rel="alternate" hreflang="en-CA" href="https://panoramaadvisory.ca/properties/<?= e($id) ?>/">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@100..125,400..700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}html,body{margin:0;background:#FCFBF8;color:#141714;font-family:Archivo,sans-serif}a{color:inherit;text-decoration:none}
header{height:70px;display:flex;align-items:center;padding:0 40px;border-bottom:1px solid rgba(30,58,41,.18);position:sticky;top:0;background:#FCFBF8;z-index:10}
.logo{width:128px}.nav{margin-left:auto;display:flex;gap:24px;align-items:center;font:500 11px/1 Archivo,sans-serif;letter-spacing:.14em;text-transform:uppercase;color:#4E5349}
main{padding:44px 40px 90px}.eyebrow{font:500 11px/1.6 'IBM Plex Mono',monospace;letter-spacing:.14em;text-transform:uppercase;color:#4E5349}
.hero{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(320px,.7fr);gap:42px;margin-top:22px;align-items:start}.hero img{width:100%;aspect-ratio:4/3;object-fit:cover;background:#E7E3DA}
h1{font-size:clamp(42px,5.8vw,92px);line-height:.98;letter-spacing:-.055em;font-weight:500;margin:0 0 26px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:20px;border-top:1px solid rgba(30,58,41,.2);padding-top:20px}.meta div{display:flex;flex-direction:column;gap:7px}.label{font:500 10px/1.4 'IBM Plex Mono',monospace;letter-spacing:.13em;text-transform:uppercase;color:#4E5349}.value{font-size:19px;line-height:1.3}
.copy{max-width:900px;margin:70px 0 0;font-size:clamp(18px,1.55vw,23px);line-height:1.65;color:#33372F}.gallery{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:54px}.gallery img{width:100%;aspect-ratio:4/3;object-fit:cover;background:#E7E3DA}
@media(max-width:900px){header{padding:0 20px}.nav a:not(:last-child){display:none}main{padding:28px 20px 60px}.hero{grid-template-columns:1fr;gap:26px}.meta{grid-template-columns:1fr 1fr}.gallery{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<header>
<a href="<?= e($isFr ? '/' : '/en/') ?>"><img class="logo" src="/assets/panorama-wordmark-ink.png" alt="Panorama"></a>
<nav class="nav">
<a href="<?= e($back) ?>"><?= e($backLabel) ?></a>
<a href="<?= e($alternate) ?>"><?= $isFr ? 'EN' : 'FR' ?></a>
</nav>
</header>
<main>
<div class="eyebrow">MLS <?= e($id) ?> · <?= e($status) ?></div>
<section class="hero">
<div><?php if($hero): ?><img src="<?= e($hero) ?>" alt="<?= e($address) ?>"><?php endif; ?></div>
<div>
<h1><?= e($address) ?></h1>
<div class="meta">
<div><span class="label"><?= $isFr ? 'Lieu' : 'Location' ?></span><span class="value"><?= e($city) ?></span></div>
<div><span class="label"><?= $isFr ? 'Type' : 'Type' ?></span><span class="value"><?= e($type) ?></span></div>
<div><span class="label"><?= $isFr ? 'Superficie' : 'Area' ?></span><span class="value"><?= e($area) ?></span></div>
<div><span class="label"><?= $isFr ? 'Prix' : 'Price' ?></span><span class="value"><?= e($price) ?></span></div>
</div>
</div>
</section>
<?php if($remarks): ?><div class="copy"><?= e($remarks) ?></div><?php endif; ?>
<?php if(count($photos)>1): ?><div class="gallery"><?php foreach(array_slice($photos,1,12) as $photo): ?><img loading="lazy" src="<?= e($photo['photourl']) ?>" alt="<?= e($address) ?>"><?php endforeach; ?></div><?php endif; ?>
</main>
</body>
</html>
