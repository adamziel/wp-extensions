# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `ru-unimorph-rus-50dcabfd0a04`
- Language: `ru`
- Source repository: https://github.com/unimorph/rus
- Source commit: `50dcabfd0a04bd4d187ffb0d5cdd54af94863dec`
- Source file: `rus`
- Source SHA-256: `6c13f849fc12844b23f46e70a02a45a2f537e60c62c8ffd8798bdff3991dc880`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=ru --pack-id=ru-unimorph-rus-50dcabfd0a04 --version=50dcabfd0a04-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/rus/blob/50dcabfd0a04bd4d187ffb0d5cdd54af94863dec/rus --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `318767`
- Runtime files: `4`
- Runtime digest SHA-256: `ffe84689ceaaccee7b6db03054eeeb854f94febcb0d3548313f2dac75c0a7574`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
