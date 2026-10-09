# PHP Weather Forecast

A tiny, free, dependency-free weather app written in plain PHP.
Search any city and get current conditions plus a 7-day forecast.

- No API key needed (uses [Open-Meteo](https://open-meteo.com/))
- No Composer, no database — just PHP 8.0+ (with `curl` or `allow_url_fopen`)
- Metric / imperial units, responsive layout, automatic dark mode
- Responses cached on disk for 10 minutes

## Run it

```bash
git clone https://github.com/kettalevi/weather.git
cd weather
php -S localhost:8000
```

Open <http://localhost:8000>. To host on shared hosting or Apache/Nginx, upload
the folder and make sure `cache/` is writable by the web server.

## Layout

| Path | Purpose |
|------|---------|
| `index.php` | Page + form handling |
| `src/WeatherService.php` | API client, caching, weather-code descriptions |
| `assets/style.css` | Styling |
| `cache/` | Cached API responses (git-ignored) |

## License

See [LICENSE](LICENSE). Weather data © Open-Meteo.com (CC BY 4.0) — non-commercial free API use.
