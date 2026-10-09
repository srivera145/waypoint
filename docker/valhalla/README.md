# Valhalla for Waypoint

Waypoint routes through a self-hosted [Valhalla](https://github.com/valhalla/valhalla) server. This folder runs it in Docker, builds routing tiles from an OpenStreetMap extract, and downloads elevation tiles so `/height` works. Waypoint's grade analysis depends on `/height`.

- **Image:** `ghcr.io/valhalla/valhalla-scripted`, the image the Valhalla project maintains. It is pinned to `3.9.0`, published 2026-09-19 and checked on GHCR on 2026-10-08. It is not pinned to `latest`, because tiles built by one release aren't guaranteed to load in another.
- **Data:** a single state for development, starting with Utah from [Geofabrik](https://download.geofabrik.de/north-america/us.html). Moving to the whole US is a two-line change in `.env`.
- **Address:** `http://127.0.0.1:8002`. By default it listens on localhost only.

All settings live in `.env` (copy it from `.env.example`). Nothing is hard-coded in `docker-compose.yml`.

## What it takes

| Region | Download | On disk after build | RAM for the build | First build |
|---|---|---|---|---|
| Utah | 161 MB PBF, about 44 elevation tiles | about 1.5 GB (300 MB of routing tiles, 1.1 GB of elevation) | 4 GB is plenty | about 10 minutes on 8 cores |
| Whole US | about 11 GB PBF, plus elevation for the country | tens of GB | 16 GB or more | hours |

The Utah figures were measured with Valhalla 3.9.0. The US figures are rough guidance only. If the build is killed, lower `VALHALLA_THREADS`.

## Windows (Docker Desktop)

You need Docker Desktop with the WSL 2 backend. If you'll build the whole US, give WSL enough memory: Docker Desktop → Settings → Resources, or a `.wslconfig` file.

```powershell
cd C:\Helm\resources\server\sites\waypoint\docker\valhalla
Copy-Item .env.example .env
docker compose up -d
docker compose logs -f valhalla
```

The first start downloads the PBF and builds the tiles. It then downloads the elevation tiles and starts the server. Valhalla is ready once `/status` answers:

```powershell
curl.exe http://127.0.0.1:8002/status
```

Keep the default named volume on Windows (leave `VALHALLA_DATA` unset). A bind mount to a Windows folder goes through the WSL file share, which makes a tile build far slower.

## Linux

You need Docker Engine with the compose plugin (`docker compose version` should work).

```bash
cd /path/to/waypoint/docker/valhalla
cp .env.example .env
docker compose up -d
docker compose logs -f valhalla
curl http://127.0.0.1:8002/status
```

On Linux a bind mount is fast. Set `VALHALLA_DATA=./custom_files` if you want the tiles visible on the host. `custom_files/` is git-ignored.

## Check that it works

Run these from Git Bash or a Linux shell. In PowerShell, use `curl.exe` and put the JSON body in a file with `--data-binary @body.json`.

```bash
# A route: Salt Lake City to Park City as a 3.8 m, 10 t RV
curl -s http://127.0.0.1:8002/route -H 'Content-Type: application/json' -d '{
  "locations": [{"lat": 40.7608, "lon": -111.891}, {"lat": 40.6461, "lon": -111.498}],
  "costing": "truck", "costing_options": {"truck": {"height": 3.8, "weight": 10.0}},
  "alternates": 2, "directions_type": "none"}'

# Elevation: should return heights near 1,300 m, not nulls
curl -s http://127.0.0.1:8002/height -H 'Content-Type: application/json' -d '{
  "shape": [{"lat": 40.7608, "lon": -111.891}, {"lat": 40.6461, "lon": -111.498}],
  "range": true}'
```

If `/height` returns `null` heights, the elevation tiles are missing. Rebuild with `VALHALLA_FORCE_REBUILD=True`.

Then, from the Waypoint root:

```bash
vendor/bin/phpunit --testsuite Integration
```

## Point Waypoint at it

Waypoint's own `.env` holds the provider settings:

```dotenv
ROUTING_PROVIDER=valhalla
VALHALLA_URL=http://127.0.0.1:8002
VALHALLA_CONNECT_TIMEOUT=3
VALHALLA_TIMEOUT=20
ROUTING_CACHE_TTL_MINUTES=10
ROUTING_RATE_LIMIT_PER_MINUTE=20
```

If Valhalla is down, `POST /api/routes` returns a 503 with a plain error. It never returns a made-up route.

## Switch from Utah to the whole US

Edit `.env`:

```dotenv
VALHALLA_REGION=us
VALHALLA_PBF_URL=https://download.geofabrik.de/north-america/us-latest.osm.pbf
```

Then run `docker compose up -d`. Each region builds in its own folder (`/custom_files/<region>`), so the Utah build stays intact. Switching back is the same two lines.

## Refresh the map data

The image downloads a PBF only when the region's folder has none. To rebuild from fresh OpenStreetMap data, delete that region's folder and start again:

```bash
docker compose run --rm --entrypoint sh valhalla -c 'rm -rf /custom_files/utah'
docker compose up -d
```

`VALHALLA_FORCE_REBUILD=True` rebuilds tiles from the PBF already on disk. It doesn't download a new one. Set it back to `False` once the rebuild is done.

## Upgrade Valhalla

Change `VALHALLA_IMAGE_TAG`, set `VALHALLA_FORCE_REBUILD=True` for one start, then set it back. Run the Integration suite afterwards.

## Without Docker (Windows, development only)

When Docker isn't installed, the [`pyvalhalla`](https://pypi.org/project/pyvalhalla/) wheel ships the same Valhalla binaries for Windows. Waypoint's integration tests were verified against one of these. It needs Python 3.12 or later. The wheel's `python -m valhalla <tool>` launcher fails on Windows ("Can't find executable"), so call the binaries in `site-packages\valhalla\bin` directly, with the bundled DLL folder on `PATH`. From Git Bash:

```bash
python -m venv vh && vh/Scripts/python -m pip install pyvalhalla==3.9.0
SITE="$PWD/vh/Lib/site-packages"
export PATH="$(cygpath -u "$SITE/pyvalhalla.libs"):$(cygpath -u "$SITE/valhalla/bin"):$PATH"
mkdir -p utah && cd utah
curl -fLo utah-latest.osm.pbf https://download.geofabrik.de/north-america/us/utah-latest.osm.pbf
../vh/Scripts/valhalla_build_config.exe --mjolnir-tile-dir "$PWD/valhalla_tiles" \
  --mjolnir-tile-extract "$PWD/valhalla_tiles.tar" --additional-data-elevation "$PWD/elevation_data" > valhalla.json
valhalla_build_tiles.exe -c valhalla.json -e build utah-latest.osm.pbf
../vh/Scripts/valhalla_build_elevation.exe --from-tiles --decompress -c valhalla.json -v
valhalla_build_tiles.exe -c valhalla.json -s enhance utah-latest.osm.pbf
valhalla_service.exe valhalla.json 4
```

This mirrors what the Docker image does: build, then download elevation, then enhance. It skips the admin and timezone databases, which a Utah-only build doesn't need.

## Clearance data

Valhalla's height, width and weight limits come from OpenStreetMap tags, and those tags are incomplete. Waypoint shows every RV, truck and semi route with the notice *"Clearance data comes from OpenStreetMap and may be incomplete. Watch posted clearances."* It never presents a route as clearance-guaranteed.
