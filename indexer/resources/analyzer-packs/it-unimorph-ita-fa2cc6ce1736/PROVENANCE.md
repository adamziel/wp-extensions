# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `it-unimorph-ita-fa2cc6ce1736`
- Language: `it`
- Source repository: https://github.com/unimorph/ita
- Source commit: `fa2cc6ce173643e748bd8f4162709365bdc98805`
- Source file: `ita`
- Source SHA-256: `6347bb15b3d2c3dafcbe4adc0e7beaa97a8bc61bcccc345079afb154c03f1321`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=it --pack-id=it-unimorph-ita-fa2cc6ce1736 --version=fa2cc6ce1736-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/ita/blob/fa2cc6ce173643e748bd8f4162709365bdc98805/ita --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `321279`
- Runtime files: `4`
- Runtime digest SHA-256: `5b949489569d73f0b02320ed82ace872d6e0eb0f4ff4c7a9502badf826830b17`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
