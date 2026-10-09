# Vendored front-end libraries

Keel has no npm build step. The map screen's libraries are copied here unchanged from their published packages, each in a folder named for its exact version. `views/partials/map-assets.php` is the only place that names these paths. To upgrade a library, add its new folder, point that partial at it, then delete the old folder.

Everything is served from this site. The map makes no requests to a third-party tile, font, sprite or geocoding service.

| Folder | Source | Files taken | License |
|---|---|---|---|
| `maplibre-gl@6.13.0` | npm `maplibre-gl@6.13.0`, `dist/` | `maplibre-gl.mjs`, `maplibre-gl-worker.mjs`, `maplibre-gl.css` | BSD-3-Clause (`LICENSE.txt`) |
| `pmtiles@4.5.0` | npm `pmtiles@4.5.0`, `dist/pmtiles.js` | the self-contained IIFE build (its ESM build imports `fflate` by bare name, which a browser cannot resolve) | BSD-3-Clause (`LICENSE`) |
| `protomaps-basemaps@5.7.2` | npm `@protomaps/basemaps@5.7.2`, `dist/esm/index.js` | saved as `basemaps.mjs` | BSD-3-Clause (`LICENSE.md`) |
| `protomaps-basemaps-assets@028c18f` | GitHub `protomaps/basemaps-assets` at commit `028c18f713baecad011301ff7a69acc39bcc2ae7` (2025-10-31) | `fonts/` for the four font stacks the basemap style names, and the `light` and `dark` sprites from `sprites/v4/` | fonts: SIL OFL 1.1 (`fonts/OFL.txt`); sprites: MIT (`sprites/LICENSE.md`) |

The npm tarballs' published `shasum` values were checked when they were downloaded on 2026-10-08. SHA-256 of the copied files:

```
389731e8581cc59d484e867b051db73ae034a76f0691d2890a2a1a7d2495bdf3  maplibre-gl@6.13.0/maplibre-gl.mjs
53a2dd60f76c7b9201a3cc007b755d98ce4b52403843fef5c90c41780fe62e91  maplibre-gl@6.13.0/maplibre-gl-worker.mjs
20fc3054e6c769ae9502ec5c7ea46dae05b544fdc4fca2108553fb8c07265bf3  maplibre-gl@6.13.0/maplibre-gl.css
caf981bc46f6327ee7e65d5dc964d89d38a69f60edca2bd4c5c890c21b554c6c  pmtiles@4.5.0/pmtiles.js
9d6ee63bee82fd4af79bedaaab538f904341b35fbc1f6254b86166aa4ee24cf1  protomaps-basemaps@5.7.2/basemaps.mjs
```

Source maps are left out to keep the repository small. The files still end in a `sourceMappingURL` comment, so browser dev tools report a missing map. That's harmless.

## Notes on these versions

- **MapLibre GL JS 6 is ESM-only.** It loads with `<script type="module">`, and it finds its worker beside itself (`maplibre-gl-worker.mjs?v=6.13.0`). It needs WebGL 2. The map screen reports a browser without WebGL 2 instead of failing silently.
- **The basemap tiles must match the style package.** `@protomaps/basemaps` 5.x draws Protomaps basemap tiles version 4. The Utah archive was extracted from the 2026-10-08 build, which is tiles version 4.15.2.
