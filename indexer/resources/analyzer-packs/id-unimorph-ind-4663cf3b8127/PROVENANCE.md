# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `id-unimorph-ind-4663cf3b8127`
- Language: `id`
- Source repository: https://github.com/unimorph/ind
- Source commit: `4663cf3b8127fb85cb7e8a0c1a4f07e96103fe34`
- Source file: `ind`
- Source SHA-256: `77c709f98b581a80051d0e18aa899e0d84d70436223054efa09831d71cdc2ac6`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=id --pack-id=id-unimorph-ind-4663cf3b8127 --version=4663cf3b8127-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/ind/blob/4663cf3b8127fb85cb7e8a0c1a4f07e96103fe34/ind --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `17421`
- Runtime files: `1`
- Runtime digest SHA-256: `cd59b41b8b1f0cb2f4bebe98ba589a24963bc7f57471b0171eee361bea8c7eaa`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
