# Photon geocoder for Waypoint: the Photon release jar on a Java 21 runtime, plus what
# entrypoint.sh needs to build a regional database from a GraphHopper Photon JSON dump.
# Photon publishes no image of its own. The jar is checked against the SHA-256 that its
# GitHub release lists.
FROM eclipse-temurin:21.0.12.1_1-jre-noble

ARG PHOTON_VERSION
ARG PHOTON_SHA256

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl python3 zstd \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /opt/photon.jar "https://github.com/komoot/photon/releases/download/${PHOTON_VERSION}/photon-${PHOTON_VERSION}.jar" \
    && echo "${PHOTON_SHA256}  /opt/photon.jar" | sha256sum -c -

COPY entrypoint.sh region_filter.py /opt/waypoint/
RUN chmod +x /opt/waypoint/entrypoint.sh

EXPOSE 2322
ENTRYPOINT ["/opt/waypoint/entrypoint.sh"]
