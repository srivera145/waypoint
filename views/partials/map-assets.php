<?php
/**
 * The map libraries, vendored in public_html/vendor (its README lists versions and sources).
 * Require inside <head>, after partials/head.php and before any module script.
 *
 * MapLibre's stylesheet is imported into app.base: unlayered, it would outrank every layered
 * rule, and keel.css could not place the attribution clear of the bottom sheet.
 *
 * The import map lets page scripts import 'maplibre-gl', 'protomaps-basemaps' and
 * 'waypoint/map-core' by name. Versions and cache-busting live here and nowhere else.
 * pmtiles ships no browser-ready ES module, so it loads as a script and sets window.pmtiles.
 */
$mapAsset = static fn (string $path): string => $path . '?v=' . (int) @filemtime(dirname(__DIR__, 2) . '/public_html' . $path);
$mapImports = [
    'maplibre-gl' => '/vendor/maplibre-gl@6.13.0/maplibre-gl.mjs',
    'protomaps-basemaps' => '/vendor/protomaps-basemaps@5.7.2/basemaps.mjs',
    'waypoint/map-core' => $mapAsset('/js/map-core.js'),
];
?>
<style>@import url("/vendor/maplibre-gl@6.13.0/maplibre-gl.css") layer(app.base);</style>
<script type="importmap"><?= json_encode(['imports' => $mapImports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="/vendor/pmtiles@4.5.0/pmtiles.js" defer></script>
