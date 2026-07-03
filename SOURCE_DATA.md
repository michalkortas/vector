# Source Data

The repository intentionally does not track `source/` or `sources/`.

Demo seeders load extracted reference data from `sources/grafik_2026_07.extracted.json`.
Keep JPG files, extracted JSON and extraction notes local or provide them through a secure deployment artifact.

Seeder code must stay generic: it may reference JSON keys, but must not hardcode personal data.
