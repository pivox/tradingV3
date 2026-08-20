# #196 Golden scenario 15 implementation plan

1. Change catalog row 15 to executable and add it to the ordered runner keys;
   run catalog/execution tests to prove RED.
2. Implement a file-backed runner that disconnects after two acknowledgements,
   proves projection remains blocked, reconciles the exact Fake snapshot,
   appends an event in the snapshot-completion race window, completes resync at
   the captured watermark, resumes that newer event and proves the terminal
   drain is empty.
3. Freeze deterministic facts in the golden execution test and retain scenario
   16 unchanged.
4. Update the #196 audit/handbook from 18/2 to 19/1, run the broad Exchange/Fake
   verification, scoped PHPStan, lints, MkDocs strict and diff check.
5. Open a ready PR, request Codex review, resolve only real actionable feedback,
   merge on green and continue to scenario 20.
