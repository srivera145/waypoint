#!/usr/bin/env python3
"""Keeps the places inside a bounding box from a Photon JSON dump.

Reads a decompressed dump on stdin and writes the filtered dump to stdout, ready for
`photon import -import-file -`. The header and country lines pass through unchanged. A place
line is kept with only those of its documents whose centroid falls inside the box, and is
dropped when none does.

    zstd -dc photon-dump-usa-1.0-latest.jsonl.zst \\
      | python3 region_filter.py --bbox=-114.06,36.99,-109.03,42.01 \\
      | java -jar photon.jar import -import-file -

Photon's own -country-codes filter stops at countries; this is how Waypoint gets one state.
Standard library only, so any Python 3.8 or later runs it.
"""

import argparse
import json
import re
import sys

# Nearly every place line holds one document with one centroid, written compactly by Photon.
# Matching the bytes avoids parsing tens of millions of JSON lines; anything else is parsed.
CENTROID = re.compile(rb'"centroid":\[(-?[0-9][0-9.eE+-]*),(-?[0-9][0-9.eE+-]*)\]')
PLACE = b'{"type":"Place"'


def parse_bbox(text):
    try:
        values = [float(part) for part in text.split(",")]
    except ValueError:
        values = []

    if len(values) != 4 or values[0] >= values[2] or values[1] >= values[3]:
        raise argparse.ArgumentTypeError("expected min_lon,min_lat,max_lon,max_lat")

    return values


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--bbox", required=True, type=parse_bbox, help="min_lon,min_lat,max_lon,max_lat")
    args = parser.parse_args()
    min_lon, min_lat, max_lon, max_lat = args.bbox

    def inside(lon, lat):
        return min_lon <= lon <= max_lon and min_lat <= lat <= max_lat

    def document_inside(document):
        centroid = document.get("centroid") if isinstance(document, dict) else None
        return isinstance(centroid, list) and len(centroid) == 2 and inside(float(centroid[0]), float(centroid[1]))

    source = sys.stdin.buffer
    target = sys.stdout.buffer
    kept = dropped = 0

    for line in source:
        if not line.lstrip().startswith(PLACE):
            target.write(line)
            continue

        matches = CENTROID.findall(line)

        if len(matches) == 1:
            if inside(float(matches[0][0]), float(matches[0][1])):
                target.write(line)
                kept += 1
            else:
                dropped += 1
            continue

        record = json.loads(line)
        documents = [document for document in record.get("content", []) if document_inside(document)]

        if documents:
            record["content"] = documents
            target.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n")
            kept += 1
        else:
            dropped += 1

    target.flush()
    print(f"region_filter: kept {kept:,} places, dropped {dropped:,}", file=sys.stderr)


if __name__ == "__main__":
    main()
