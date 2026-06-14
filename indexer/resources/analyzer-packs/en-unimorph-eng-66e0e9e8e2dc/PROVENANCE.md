# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `en-unimorph-eng-66e0e9e8e2dc`
- Language: `en`
- Source repository: https://github.com/unimorph/eng
- Source commit: `66e0e9e8e2dcd196da081a25a48e5c1fe3d8b49b`
- Source file: `eng`
- Source SHA-256: `20a191cefdc7cad6fa74b00f49d6f658684f17b14541aae372e5a3d5a8c15c67`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=en --pack-id=en-unimorph-eng-66e0e9e8e2dc --version=66e0e9e8e2dc-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/eng/blob/66e0e9e8e2dcd196da081a25a48e5c1fe3d8b49b/eng --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `566761`
- Runtime files: `6`
- Runtime digest SHA-256: `5db7690f96aac3e07cefb1465cbc4cd02ba1b440be118ddf87108adb0c1b217f`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
