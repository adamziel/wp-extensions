# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `nl-unimorph-nld-7654cbfbb815`
- Language: `nl`
- Source repository: https://github.com/unimorph/nld
- Source commit: `7654cbfbb81589d03f98231cec2e3ad21ee9e8aa`
- Source file: `nld`
- Source SHA-256: `86f3504cc426c9beffe8566406294f7f71505d46a2bba8773bdcf2e9c5a3d750`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=nl --pack-id=nl-unimorph-nld-7654cbfbb815 --version=7654cbfbb815-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/nld/blob/7654cbfbb81589d03f98231cec2e3ad21ee9e8aa/nld --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `26405`
- Runtime files: `1`
- Runtime digest SHA-256: `17ca2aa0026044cedd58ba3d61f812ef38d37626d47a7ffd596402a697db3815`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
