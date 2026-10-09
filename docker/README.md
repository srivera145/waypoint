# Waypoint's map stack

Everything the map screen needs runs on your own machines. Nothing calls a third-party tile, geocoding or routing service, and nothing needs an API key.

| Piece | What it does | Where | Address |
|---|---|---|---|
| Valhalla | routes and elevation | [valhalla/](valhalla/README.md) | `http://127.0.0.1:8002` |
| Photon | place search and reverse lookup | [photon/](photon/README.md) | `http://127.0.0.1:2322` |
| Basemap | one Protomaps `.pmtiles` file, served by Waypoint itself | [tiles/](#map-tiles) builds it into `storage/maps/` | `/tiles/basemap.pmtiles` |
| Map libraries | MapLibre GL, PMTiles, Protomaps styles, fonts and sprites | [public_html/vendor/](../public_html/vendor/README.md) | served with the app |

All three data sets cover the same region: Utah for development, the whole US later. Search never offers a place routing can't reach, and the map has tiles wherever there's a route.

## Start the full stack

### With Docker (Windows Docker Desktop, or Linux)

Copy each `.env.example` to `.env` first: `docker/valhalla`, `docker/photon` and `docker/tiles`. The first start of each downloads and builds its data. See each README for times and sizes.

```bash
# 1. Routing (first start: about 10 minutes for Utah)
cd docker/valhalla && docker compose up -d && cd ../..

# 2. Place search (first start: download the USA dump, then about 8 minutes to import Utah)
cd docker/photon && docker compose up -d --build && cd ../..

# 3. Basemap tiles: once, and again whenever you want fresher tiles (about 290 MB for Utah)
cd docker/tiles && docker compose run --rm extract && cd ../..

# 4. The app: Helm's Apache and MariaDB serve it at http://waypoint.local
php database/migrate.php
```

In PowerShell the steps are the same, with `Copy-Item .env.example .env` and `cd docker\photon`.

Check each piece:

```bash
curl http://127.0.0.1:8002/status                       # Valhalla
curl http://127.0.0.1:2322/status                       # Photon
curl -s -o /dev/null -w '%{http_code}\n' -H 'Range: bytes=0-16383' http://waypoint.local/tiles/basemap.pmtiles   # 206
vendor/bin/phpunit --testsuite Integration              # talks to Valhalla and Photon
```

### Without Docker (Windows, development)

Valhalla and Photon both run natively. [valhalla/README.md](valhalla/README.md#without-docker-windows-development-only) uses the `pyvalhalla` wheel. [photon/README.md](photon/README.md#without-docker-windows-development-only) runs the Photon jar on Java 21. Once each has built its data, starting the stack is two long-running commands plus Helm:

```bash
# Valhalla, from the folder you built Utah in (with the wheel's DLL folder on PATH, as its README shows)
valhalla_service.exe valhalla.json 4

# Photon, from docker/photon
java -Xmx1g -jar data/bin/photon-1.3.0.jar serve -data-dir data/utah -listen-ip 127.0.0.1 -listen-port 2322 -default-language en
```

For tiles without Docker, use the `pmtiles` CLI as shown below.

## Map tiles

The basemap is a single [Protomaps](https://protomaps.com) `.pmtiles` archive: vector tiles for zoom 0–15 in one file. The browser reads it a range at a time (a header, a directory, then each tile), so whatever serves it must answer HTTP range requests with `206 Partial Content`.

### Get a regional archive

Protomaps publishes a planet build at `https://build.protomaps.com/<YYYYMMDD>.pmtiles` (about 140 GB). You never download it whole. `pmtiles extract` reads just the tiles inside a bounding box straight from the remote file.

**With Docker:** set the build date and box in `docker/tiles/.env`, then:

```bash
cd docker/tiles
docker compose run --rm extract      # writes storage/maps/utah.pmtiles
```

**Without Docker:** download `go-pmtiles` for your platform from [its releases](https://github.com/protomaps/go-pmtiles/releases) (v1.31.2 was used here; check the archive against the release's SHA-256), then:

```bash
pmtiles extract https://build.protomaps.com/20261008.pmtiles storage/maps/utah.pmtiles \
  --bbox=-114.06,36.99,-109.03,42.01
pmtiles show storage/maps/utah.pmtiles     # bounds, zoom 0-15, "version 4.x"
```

Add `--dry-run` first to see the size. Measured on 2026-10-08 for Utah: zoom 15 is 291 MB, zoom 14 is 138 MB and zoom 13 is 64 MB. Below 15, the map still works but stretches the last zoom's tiles, so streets look softer when zoomed in. For the whole US, use a bigger box, or `--region` with a GeoJSON outline that includes Alaska and Hawaii. Expect tens of GB.

Pick a recent build from [maps.protomaps.com/builds](https://maps.protomaps.com/builds/). The archive must be basemap **version 4**, the schema `@protomaps/basemaps` 5.x draws. Every current build is.

### Serve it

Waypoint's `.env` decides where the browser loads the archive from:

```dotenv
# Development: Waypoint serves this file at /tiles/basemap.pmtiles, with range requests.
MAP_TILES_PATH=storage/maps/utah.pmtiles

# Production: point the browser at a static server or CDN that answers range requests.
# When this is set, it wins and MAP_TILES_PATH is ignored.
MAP_TILES_URL=
```

`storage/maps/` is git-ignored. Waypoint's own endpoint (`TileController`) answers `206` with `Content-Range` for a range, `416` for a range past the end, and `200` without one. It sends `Accept-Ranges` and an `ETag` that changes when the file is replaced, and it needs no sign-in, since basemap tiles are public OpenStreetMap data. Each range boots PHP, which is fine for a few users. Under real traffic, put the file behind nginx, Apache, or object storage with a CDN in front, and set `MAP_TILES_URL`. All of these serve ranges with no extra configuration.

### Light and dark

The style isn't a file. The browser builds it from `@protomaps/basemaps` (`light` or `dark` flavor) with glyphs and sprites from `public_html/vendor/protomaps-basemaps-assets@028c18f`. The map follows the system theme and Deck's theme switch, restyling in place when either changes. Nothing about tiles changes between themes.
