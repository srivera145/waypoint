# Photon for Waypoint

Waypoint's place search runs on a self-hosted [Photon](https://github.com/komoot/photon) geocoder. This folder builds Photon into a Docker image, imports the places for one region, and serves them at `http://127.0.0.1:2322`. `GET /api/places/search` and `GET /api/places/reverse` call it.

- **Photon:** version 1.3.0, published 2026-08-07. Photon has no official Docker image, so the `Dockerfile` downloads the release jar and checks it against the SHA-256 listed on the GitHub release. It runs on `eclipse-temurin:21.0.12.1_1-jre-noble`, since Photon needs Java 21.
- **Data:** the region matches [docker/valhalla](../valhalla/README.md). For development that's Utah, so search covers the same area as routing and never offers a place Valhalla can't reach. Moving to the whole US is a two-line change in `.env`.
- **Address:** `http://127.0.0.1:2322`. By default it listens on localhost only.

All settings live in `.env` (copy it from `.env.example`). Nothing is hard-coded in `docker-compose.yml`.

## Where the data comes from

Photon builds its database from either a Nominatim database or a Photon JSON dump. GraphHopper publishes weekly dumps per country at [download1.graphhopper.com/public](https://download1.graphhopper.com/public/), but nothing smaller than a country. Photon's own filter (`-country-codes`) also stops at countries.

So the first start downloads the **USA dump** and runs it through `region_filter.py`, which keeps only the places inside `PHOTON_BBOX`. The default box is Utah's, the area of the Geofabrik Utah extract that Valhalla builds from. The result goes into `photon import`. For the whole US, leave `PHOTON_BBOX` empty and the dump is imported as it is.

The alternative is to run Nominatim on the same Geofabrik PBF that Valhalla uses and import Photon from that. Its data would be from exactly the same snapshot. But it means running PostgreSQL alongside Photon, the Utah import is far slower, and the US import takes about a day. The dump is a week old at most, which doesn't matter for finding a destination.

## What it takes

| Region | Download | Import | On disk afterwards | RAM |
|---|---|---|---|---|
| Utah | 5.1 GB USA dump | about 6 min to filter 54 million places down to 836,000, then 75 s to import | 270 MB database (keep or delete the dump) | 1–2 GB heap |
| Whole US | same 5.1 GB dump | an hour or more | tens of GB | 8 GB heap or more |

The Utah figures were measured on 2026-10-08 with Photon 1.3.0 and the dump dated 2026-10-03. The US figures are rough guidance only. The download took 32 minutes that day, with GraphHopper's server sending between 0.4 and 3 MB/s, so allow for longer. An interrupted download resumes where it stopped on the next start.

## Windows (Docker Desktop)

You need Docker Desktop with the WSL 2 backend.

```powershell
cd C:\Helm\resources\server\sites\waypoint\docker\photon
Copy-Item .env.example .env
docker compose up -d --build
docker compose logs -f photon
```

The first start downloads the dump, imports the region, then starts the server. Photon is ready once `/status` answers:

```powershell
curl.exe http://127.0.0.1:2322/status
```

Keep the default named volume on Windows (leave `PHOTON_DATA` unset). A bind mount to a Windows folder goes through the WSL file share, which makes an import far slower.

## Linux

You need Docker Engine with the compose plugin (`docker compose version` should work).

```bash
cd /path/to/waypoint/docker/photon
cp .env.example .env
docker compose up -d --build
docker compose logs -f photon
curl http://127.0.0.1:2322/status
```

On Linux a bind mount is fast. Set `PHOTON_DATA=./data` if you want the files on the host. `data/` is git-ignored and kept out of the image build.

## Check that it works

```bash
# A search biased toward Salt Lake City: Park City (Summit County) should come first
curl -s 'http://127.0.0.1:2322/api?q=Park%20City&lat=40.76&lon=-111.89&limit=3&lang=en'

# The nearest place to a point
curl -s 'http://127.0.0.1:2322/reverse?lat=40.7608&lon=-111.891&limit=1&lang=en'
```

Then, from the Waypoint root, run the integration tests. They skip themselves when Photon isn't answering:

```bash
vendor/bin/phpunit --testsuite Integration
```

## Point Waypoint at it

Waypoint's own `.env` holds the provider settings:

```dotenv
GEOCODING_PROVIDER=photon
PHOTON_URL=http://127.0.0.1:2322
PHOTON_CONNECT_TIMEOUT=2
PHOTON_TIMEOUT=5
PHOTON_LANGUAGE=en
PLACES_CACHE_TTL_MINUTES=5
PLACES_RATE_LIMIT_PER_MINUTE=60
```

The timeouts are short because the search box asks as the user types. If Photon is down, the search box says "Place search is unavailable right now" within a couple of seconds instead of spinning.

## Switch from Utah to the whole US

Edit `.env`:

```dotenv
PHOTON_REGION=us
PHOTON_BBOX=
PHOTON_JAVA_OPTS=-Xmx8g
```

Then run `docker compose up -d`. Each region builds in its own folder (`/photon/<region>`), so the Utah database stays intact, and the dump already on disk is reused. Switching back is the same lines.

For the whole US you can also skip the import. GraphHopper publishes a ready-built US database (`photon-db-usa-1.0-latest.tar.bz2`, about 10.5 GB) on the same server. Unpack it into the volume at `/photon/us/` so you end up with `/photon/us/photon_data`. The entrypoint then serves it as it is.

## Refresh the data

GraphHopper rebuilds the dumps weekly. To import a fresh one, delete the downloaded dump and force a reimport:

```bash
docker compose run --rm --entrypoint sh photon -c 'rm -f /photon/downloads/*'
# set PHOTON_FORCE_REIMPORT=True in .env, then:
docker compose up -d
# once it reports "<region> is ready", set PHOTON_FORCE_REIMPORT=False again
```

The new database is built beside the old one and moved into place only when the import finishes. A failed import leaves the old database serving.

## Upgrade Photon

Change `PHOTON_VERSION` and `PHOTON_SHA256` together (the hash is on the GitHub release page, next to the jar). Then run `docker compose up -d --build`. A new major version may not read the old database: set `PHOTON_FORCE_REIMPORT=True` for one start. Run the Integration suite afterwards.

## Without Docker (Windows, development only)

Photon is a single jar, so it runs natively anywhere Java 21 does. These are the steps Waypoint's integration tests were verified with, run from Git Bash in this folder. Everything lands in `data/`, which is git-ignored.

```bash
# 1. Java 21 (java -version), plus zstd: download zstd-v1.5.7-win64.zip from
#    https://github.com/facebook/zstd/releases and put zstd.exe on PATH.

# 2. The Photon jar, checked against the release's SHA-256
mkdir -p data/bin data/downloads data/utah
curl -fL -o data/bin/photon-1.3.0.jar https://github.com/komoot/photon/releases/download/1.3.0/photon-1.3.0.jar
sha256sum data/bin/photon-1.3.0.jar   # a89707c0045e4807b2a1180e132e68e108d998709f48b6c94b98a6e281f571a5

# 3. The USA dump (resumable: rerun the same command if it stops)
curl -fL -C - -o data/downloads/photon-dump-usa-1.0-latest.jsonl.zst \
  https://download1.graphhopper.com/public/north-america/usa/photon-dump-usa-1.0-latest.jsonl.zst

# 4. Keep Utah, then import it
zstd -dc data/downloads/photon-dump-usa-1.0-latest.jsonl.zst \
  | python region_filter.py --bbox=-114.06,36.99,-109.03,42.01 > data/utah/photon-dump-utah.jsonl
java -Xmx2g -jar data/bin/photon-1.3.0.jar import -import-file data/utah/photon-dump-utah.jsonl -data-dir data/utah -languages en -j 4

# 5. Serve it (leave this running)
java -Xmx1g -jar data/bin/photon-1.3.0.jar serve -data-dir data/utah -listen-ip 127.0.0.1 -listen-port 2322 -default-language en
```

`data/` is laid out like the container's `/photon` volume (`downloads/` and `<region>/photon_data`). So `PHOTON_DATA=./data` on Linux, or a copy into the Docker volume, picks up the same database. Once the import is done, you can delete the 5.1 GB dump and the 1.4 GB filtered file.
