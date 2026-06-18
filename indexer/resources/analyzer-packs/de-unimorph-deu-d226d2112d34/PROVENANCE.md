# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `de-unimorph-deu-d226d2112d34`
- Language: `de`
- Source repository: https://github.com/unimorph/deu
- Source commit: `d226d2112d3490d8f04ece10d4538123d4297a39`
- Source file: `deu`
- Source SHA-256: `8bc98487e3caf86188e2c072ebf2dc734f2ca35afef016bb399eb5a43e69d7c0`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=de --pack-id=de-unimorph-deu-d226d2112d34 --version=d226d2112d34-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/deu/blob/d226d2112d3490d8f04ece10d4538123d4297a39/deu --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `170700`
- Runtime files: `2`
- Runtime digest SHA-256: `551ac706ad1502bdfb0e1b1cc1bd3648912380a1b28deb8d6162ea929e2434eb`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
