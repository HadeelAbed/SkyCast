/**
 * SkyCast · Open-Meteo proxy (Cloudflare Worker)
 * ---------------------------------------------------------------------------
 * Open-Meteo applies its free-tier rate limits per IP address. Hosts that
 * share outbound IPs with many other apps (Render, Vercel, Netlify, ...) are
 * throttled early and start returning HTTP 429, which stops the forecast from
 * loading. Open-Meteo's own terms recommend Cloudflare Workers for this,
 * because limits are applied per Cloudflare user (via the automatic
 * `CF-Worker` request header).
 *
 * This Worker forwards the request to Open-Meteo and returns the JSON as-is.
 * Deploy it once in the Cloudflare dashboard (Workers & Pages -> Create ->
 * Start with Hello World -> Edit code -> paste this -> Deploy), then set:
 *
 *   OPEN_METEO_FORECAST_URL=https://<worker>.<subdomain>.workers.dev/v1/forecast
 *
 * on the app host (e.g. Render -> Environment). Add `?path=search` style
 * routes here if the geocoding endpoint ever needs proxying too.
 */
export default {
  async fetch(request) {
    const target = new URL('https://api.open-meteo.com/v1/forecast');
    target.search = new URL(request.url).search;

    const response = await fetch(target.toString(), {
      headers: { accept: 'application/json' },
    });

    return new Response(response.body, {
      status: response.status,
      headers: { 'content-type': 'application/json' },
    });
  },
};
