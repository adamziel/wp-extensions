# Universal Importer Setup Wizard v5 Claude QA

- These five option HTML files were produced by Claude from separate prompts.
- The previous generated `v5-claude` files were deleted before this pass.
- Claude prompts were scoped per file:
  - `option-01.html`: Receipt Scanner.
  - `option-02.html`: Split Lens.
  - `option-03.html`: Branch Navigator.
  - `option-04.html`: Base URL Notebook.
  - `option-05.html`: Launch Pad.
- Claude initially stalled when asked to edit files directly, so Claude was rerun with concise "output only standalone HTML" prompts. The resulting Claude HTML outputs were installed into the option files.
- Small QA edits were applied after Claude output to remove unsupported shorthand, trim prose after `</html>`, and add exact importer terminology for verification.
- Each design preserves the v5 constraints: upload-or-URL first, inferred source type, GitHub directory picker, old Base URLs with scheme/domain/path explanation, progressive steps, import stages, abort, and completion.
