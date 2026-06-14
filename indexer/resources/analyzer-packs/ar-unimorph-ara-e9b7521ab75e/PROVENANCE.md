# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `ar-unimorph-ara-e9b7521ab75e`
- Language: `ar`
- Source repository: https://github.com/unimorph/ara
- Source commit: `e9b7521ab75e3a7da6f996a428031164993e6f19`
- Source file: `ara`
- Source SHA-256: `01663d08dbc6bb2a4207749cb4c000926c72c7cf9ec3faa8521e4cb5e9d690f1`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=ar --pack-id=ar-unimorph-ara-e9b7521ab75e --version=e9b7521ab75e-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/ara/blob/e9b7521ab75e3a7da6f996a428031164993e6f19/ara --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `45516`
- Runtime files: `1`
- Runtime digest SHA-256: `8cff1bbcfeffd47cf52d3c4bdb13818a6f76d3a0ff0f982f3222160ea4944815`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
