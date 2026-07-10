# Source Data

The repository does not track medical source artifacts from `source/` or `sources/`.

Demo seeders load extracted reference data from `sources/grafik_2026_07.extracted.json`.
Keep JPG files, extracted JSON and extraction notes local or provide them through a secure deployment artifact.

The fictional transport dataset is safe to distribute and is tracked as
`sources/vehicles_2026_07.demo.json`.

Seeder code must stay generic: it may reference JSON keys, but must not hardcode personal data.
