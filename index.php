<?php
declare(strict_types=1);

require __DIR__ . '/src/WeatherService.php';

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$service = new WeatherService(__DIR__ . '/cache');

$q     = trim((string) ($_GET['city'] ?? ''));
$units = ($_GET['units'] ?? 'metric') === 'imperial' ? 'imperial' : 'metric';
$tUnit = $units === 'imperial' ? '°F' : '°C';
$wUnit = $units === 'imperial' ? 'mph' : 'km/h';

// --- recent cities (stored in a cookie on the visitor's own browser) ---
const RECENT_COOKIE = 'recent_cities';
const RECENT_MAX    = 5;

function recentCookieOptions(int $expires): array
{
    return ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true];
}

$recent = json_decode((string) ($_COOKIE[RECENT_COOKIE] ?? '[]'), true);
$recent = is_array($recent)
    ? array_slice(array_values(array_filter($recent, 'is_string')), 0, RECENT_MAX)
    : [];

if (isset($_GET['clear'])) {
    setcookie(RECENT_COOKIE, '', recentCookieOptions(time() - 3600));
    header('Location: ?units=' . $units);
    exit;
}

// --- "Use my location" sends coordinates instead of a city name ---
$lat = $lon = null;
if (isset($_GET['lat'], $_GET['lon'])
    && is_numeric($_GET['lat']) && is_numeric($_GET['lon'])
    && abs((float) $_GET['lat']) <= 90 && abs((float) $_GET['lon']) <= 180) {
    $lat = round((float) $_GET['lat'], 2);   // ~1 km: privacy + better cache hits
    $lon = round((float) $_GET['lon'], 2);
}

$city = $weather = null;
$error = null;
$hours = [];

try {
    if ($lat !== null) {
        $city = ['name' => 'Your location', 'country' => '', 'admin' => '', 'lat' => $lat, 'lon' => $lon];
        $weather = $service->forecast($lat, $lon, $units);
    } elseif ($q !== '') {
        $q = mb_substr($q, 0, 100);
        $city = $service->findCity($q);
        if ($city === null) {
            $error = 'No city found for "' . $q . '". Try another spelling.';
        } else {
            $weather = $service->forecast($city['lat'], $city['lon'], $units);
            $recent = array_slice(
                array_values(array_unique([$city['name'], ...$recent])),
                0,
                RECENT_MAX
            );
            setcookie(RECENT_COOKIE, json_encode($recent), recentCookieOptions(time() + 86400 * 90));
        }
    }
    if ($weather) {
        $hours = WeatherService::nextHours($weather, 24);
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

/** Inline SVG bar chart of rain probability for the given hours. */
function rainChart(array $hours): string
{
    $w = 720; $h = 190; $l = 36; $r = 8; $t = 10; $b = 28;
    $pw = $w - $l - $r; $ph = $h - $t - $b;
    $n = count($hours);
    $step = $pw / $n;
    $bw = max(4, $step - 4);
    $svg = '<svg class="rainchart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="Chance of rain for the next ' . $n . ' hours">';
    foreach ([0, 50, 100] as $v) {
        $y = $t + $ph * (1 - $v / 100);
        $svg .= '<line class="grid" x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . $y . '" y2="' . $y . '"/>'
              . '<text class="axis" x="' . ($l - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' . $v . '%</text>';
    }
    foreach ($hours as $i => $hr) {
        $x = $l + $i * $step + ($step - $bw) / 2;
        $bh = $ph * $hr['pop'] / 100;
        $label = date('ga', strtotime($hr['time']));
        $tip = $label . ': ' . $hr['pop'] . '% chance, ' . $hr['rain'] . ' mm';
        // full-height transparent hit area so tiny bars still get a tooltip
        $svg .= '<g class="bar"><title>' . e($tip) . '</title>'
              . '<rect class="hit" x="' . $x . '" y="' . $t . '" width="' . $bw . '" height="' . $ph . '"/>';
        if ($bh > 0) {
            $svg .= '<rect class="fill" x="' . $x . '" y="' . ($t + $ph - $bh) . '" width="' . $bw
                  . '" height="' . $bh . '" rx="3"/>';
        }
        $svg .= '</g>';
        if ($i % 3 === 0) {
            $svg .= '<text class="axis" x="' . ($x + $bw / 2) . '" y="' . ($h - 8)
                  . '" text-anchor="middle">' . e($label) . '</text>';
        }
    }
    return $svg . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weather Forecast</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
  <h1>🌍 Weather Forecast</h1>

  <form method="get" class="search">
    <input type="text" name="city" required value="<?= e($q) ?>" placeholder="Search for a city…" autofocus>
    <select name="units" aria-label="Units">
      <option value="metric"   <?= $units === 'metric' ? 'selected' : '' ?>>°C</option>
      <option value="imperial" <?= $units === 'imperial' ? 'selected' : '' ?>>°F</option>
    </select>
    <button type="submit">Search</button>
    <button type="button" id="locate" class="secondary" title="Use my location">📍</button>
  </form>

  <?php if ($recent): ?>
    <nav class="recent" aria-label="Recent cities">
      <?php foreach ($recent as $name): ?>
        <a href="?city=<?= urlencode($name) ?>&amp;units=<?= $units ?>"><?= e($name) ?></a>
      <?php endforeach; ?>
      <a href="?clear=1&amp;units=<?= $units ?>" class="clear">clear</a>
    </nav>
  <?php endif; ?>

  <?php if ($error): ?>
    <p class="error"><?= e($error) ?></p>
  <?php endif; ?>

  <?php if ($weather && $city):
      $cur = $weather['current'];
      [$desc, $icon] = WeatherService::describe((int) $cur['weather_code'], (bool) $cur['is_day']);
      $place = implode(', ', array_filter([$city['name'], $city['admin'], $city['country']]));
  ?>
    <section class="current">
      <h2><?= e($place) ?></h2>
      <div class="big"><span class="icon"><?= $icon ?></span> <?= round($cur['temperature_2m']) ?><?= $tUnit ?></div>
      <p><?= e($desc) ?> · feels like <?= round($cur['apparent_temperature']) ?><?= $tUnit ?></p>
      <p class="muted">💧 <?= (int) $cur['relative_humidity_2m'] ?>% humidity · 💨 <?= round($cur['wind_speed_10m']) ?> <?= $wUnit ?></p>
    </section>

    <?php if ($hours): ?>
    <section class="hourly">
      <h3>Next 24 hours</h3>
      <div class="strip">
        <?php foreach ($hours as $hr):
            [$hDesc, $hIcon] = WeatherService::describe($hr['code'], $hr['day']); ?>
          <div class="hour" title="<?= e($hDesc) ?>">
            <small class="muted"><?= e(date('ga', strtotime($hr['time']))) ?></small>
            <span class="icon"><?= $hIcon ?></span>
            <strong><?= round($hr['temp']) ?>°</strong>
          </div>
        <?php endforeach; ?>
      </div>
      <h3>Chance of rain</h3>
      <?= rainChart($hours) ?>
    </section>
    <?php endif; ?>

    <section class="forecast">
      <h3>7-day forecast</h3>
      <div class="days">
      <?php $d = $weather['daily'];
      foreach ($d['time'] as $i => $date):
          [$dDesc, $dIcon] = WeatherService::describe((int) $d['weather_code'][$i]);
          $label = $i === 0 ? 'Today' : date('D, M j', strtotime($date));
      ?>
        <div class="day">
          <strong><?= e($label) ?></strong>
          <span class="icon" title="<?= e($dDesc) ?>"><?= $dIcon ?></span>
          <span><?= round($d['temperature_2m_max'][$i]) ?>° / <span class="muted"><?= round($d['temperature_2m_min'][$i]) ?>°</span></span>
          <small class="muted">☔ <?= (int) ($d['precipitation_probability_max'][$i] ?? 0) ?>%</small>
        </div>
      <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <footer>
    Data by <a href="https://open-meteo.com/">Open-Meteo.com</a> · Free &amp; open source ·
    <a href="download.php">⬇ Download this app (.zip)</a>
  </footer>
</main>
<script>
document.getElementById('locate').addEventListener('click', function () {
  var btn = this;
  if (!navigator.geolocation) { alert('Geolocation is not supported by this browser.'); return; }
  btn.disabled = true; btn.textContent = '…';
  navigator.geolocation.getCurrentPosition(function (pos) {
    var p = new URLSearchParams({
      lat: pos.coords.latitude.toFixed(2),
      lon: pos.coords.longitude.toFixed(2),
      units: document.querySelector('select[name=units]').value
    });
    location.search = p.toString();
  }, function (err) {
    btn.disabled = false; btn.textContent = '📍';
    alert(err.code === 1 ? 'Location permission was denied.' : 'Could not determine your location.');
  }, { timeout: 10000, maximumAge: 600000 });
});
</script>
</body>
</html>
