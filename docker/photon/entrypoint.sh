#!/usr/bin/env bash
# Builds the region's Photon database on first start, then serves it.
#
#   /photon/downloads/<dump>        the GraphHopper dump, kept so a rebuild doesn't download again
#   /photon/<region>/photon_data    the database for PHOTON_REGION
#
# A region that already has a database starts serving at once. PHOTON_FORCE_REIMPORT=True
# rebuilds it from the dump on disk. A failed import never replaces a working database: the
# new one is built beside it and moved into place only when the import finishes.
set -euo pipefail

: "${PHOTON_REGION:?Set PHOTON_REGION in docker/photon/.env}"
: "${PHOTON_DUMP_URL:?Set PHOTON_DUMP_URL in docker/photon/.env}"
PHOTON_BBOX="${PHOTON_BBOX:-}"
PHOTON_LANGUAGES="${PHOTON_LANGUAGES:-en}"
PHOTON_JAVA_OPTS="${PHOTON_JAVA_OPTS:--Xmx2g}"
PHOTON_THREADS="${PHOTON_THREADS:-2}"
PHOTON_FORCE_REIMPORT="${PHOTON_FORCE_REIMPORT:-False}"

region_dir="/photon/${PHOTON_REGION}"
dump="/photon/downloads/$(basename "${PHOTON_DUMP_URL}")"

if [ ! -d "${region_dir}/photon_data" ] || [ "${PHOTON_FORCE_REIMPORT}" = "True" ]; then
    mkdir -p /photon/downloads "${region_dir}"

    if [ ! -f "${dump}.complete" ]; then
        echo "photon: downloading ${PHOTON_DUMP_URL}; an interrupted download resumes on the next start"
        curl -fL --retry 10 --retry-delay 15 -C - -o "${dump}" "${PHOTON_DUMP_URL}"
        touch "${dump}.complete"
    fi

    rm -rf "${region_dir}/building"
    mkdir -p "${region_dir}/building"

    if [ -n "${PHOTON_BBOX}" ]; then
        echo "photon: importing the places inside ${PHOTON_BBOX} for ${PHOTON_REGION}"
        zstd -dc "${dump}" \
            | python3 /opt/waypoint/region_filter.py --bbox="${PHOTON_BBOX}" \
            | java ${PHOTON_JAVA_OPTS} -jar /opt/photon.jar import -import-file - -data-dir "${region_dir}/building" -languages "${PHOTON_LANGUAGES}" -j "${PHOTON_THREADS}"
    else
        echo "photon: importing the whole dump for ${PHOTON_REGION}"
        zstd -dc "${dump}" \
            | java ${PHOTON_JAVA_OPTS} -jar /opt/photon.jar import -import-file - -data-dir "${region_dir}/building" -languages "${PHOTON_LANGUAGES}" -j "${PHOTON_THREADS}"
    fi

    rm -rf "${region_dir}/photon_data"
    mv "${region_dir}/building/photon_data" "${region_dir}/photon_data"
    rm -rf "${region_dir}/building"
    echo "photon: ${PHOTON_REGION} is ready"
fi

exec java ${PHOTON_JAVA_OPTS} -jar /opt/photon.jar serve \
    -data-dir "${region_dir}" \
    -listen-ip 0.0.0.0 \
    -listen-port 2322 \
    -default-language "${PHOTON_LANGUAGES%%,*}"
