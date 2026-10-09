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

$city = $weather = null;
$error = null;

if ($q !== '') {
    try {
        $q = mb_substr($q, 0, 100);
        $city = $service->findCity($q);
        if ($city === null) {
            $error = 'No city found for "' . $q . '". Try another spelling.';
        } else {
            $weather = $service->forecast($city['lat'], $city['lon'], $units);
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
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
    <input type="text" name="city" value="<?= e($q) ?>" placeholder="Search for a city…" required autofocus>
    <select name="units" aria-label="Units">
      <option value="metric"   <?= $units === 'metric' ? 'selected' : '' ?>>°C</option>
      <option value="imperial" <?= $units === 'imperial' ? 'selected' : '' ?>>°F</option>
    </select>
    <button type="submit">Search</button>
  </form>

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

  <footer>Data by <a href="https://open-meteo.com/">Open-Meteo.com</a> · Free &amp; open source</footer>
</main>
</body>
</html>
