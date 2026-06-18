# UniMorph Analyzer Pack Provenance

This pack is generated from source-approved UniMorph data through the repository importer. It contains normalized surface-to-lemma runtime rows and no runtime network access.

- Pack ID: `uk-unimorph-ukr-d7d0284e926b`
- Language: `uk`
- Source repository: https://github.com/unimorph/ukr
- Source commit: `d7d0284e926b7d4947c52aaafd1d7f8a40a2dec0`
- Source file: `ukr`
- Source SHA-256: `c0ad5ba0348b829b2363a215f712b84c4d302535ebb97557d93082e4f32eab42`
- License: `CC-BY-SA-3.0` https://creativecommons.org/licenses/by-sa/3.0/
- Importer command: `php indexer/tools/import-unimorph-lemma-pack.php --source=<unimorph-source> --out=<pack-dir> --language=uk --pack-id=uk-unimorph-ukr-d7d0284e926b --version=d7d0284e926b-unimorph-v1 --source-name=<approved-unimorph-source-name> --source-url=https://github.com/unimorph/ukr/blob/d7d0284e926b7d4947c52aaafd1d7f8a40a2dec0/ukr --license=CC-BY-SA-3.0 --attribution=<required-attribution> --runtime-compression=gzip`
- Runtime rows: `14229`
- Runtime files: `1`
- Runtime digest SHA-256: `e9ca8689afdcde99f5b6ab77e91c66ef6a551561349afc7917e3c37e708c6088`

The generated pack is default-disabled. Callers must opt in through `lemma_packs_by_lang` or `lemmatizer_packs_by_lang`.
