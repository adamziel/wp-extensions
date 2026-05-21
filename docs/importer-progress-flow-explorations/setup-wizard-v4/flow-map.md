# Universal Importer Setup Wizard Flow Map

## Setup

1. Choose a supported source:
   - GitHub repo, branch, or subdirectory URL.
   - WordPress site or REST root.
   - RSS, Atom, RDF, OPML, or feed list.
   - Reachable URL or remote page.
   - Server path to a file, folder, or archive.
   - Uploaded files or uploaded folder.
2. Optional GitHub directory picker:
   - Enter a GitHub repository URL.
   - Open directory picker.
   - Filter/select a directory path.
   - Use selected directory as source.
3. Configure URL treatment:
   - Ask when old URLs are found.
   - Keep URLs unchanged.
   - Rewrite listed domains.
   - Provide old site domains when needed.
4. Configure write mode:
   - Publish imported pages by default.
   - Import as drafts.
   - Dry run without writing WordPress posts.
5. Start import.

## Import Run

1. Read source.
2. Prepare content.
3. URL treatment.
4. Import media.
5. Write pages.
6. Finish.

## Possible Decisions

- URL treatment may pause the run with: "Rewrite old-site URLs to this site?"
- Available actions:
  - Rewrite selected domains.
  - Yes, rewrite all.
  - No, keep all URLs.

## Progress Details

- Status can be pending, running, needs attention, done, or aborted.
- Progress may show item counts, source items, prepared documents, media references, comments, recent events, and technical details.
- Running imports expose Abort.
- Completed imports expose View imported content.
