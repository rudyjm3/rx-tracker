# Project instructions for Claude Code

## PR review handling

Whenever this account is subscribed to a pull request's activity (via
`subscribe_pr_activity`) and a review comment comes in:

- Always reply directly on that comment thread — summarize the fix that was
  pushed, or explain why no change was made. Never leave a review comment
  unanswered, even a minor or non-blocking one.
- Always resolve the thread afterward (`resolve_review_thread`), on the
  user's behalf, once it's been addressed or answered.
- This applies to every PR this account subscribes to going forward, not
  just the one active when this preference was set.

This is in addition to (not a replacement for) the standard PR-subscription
behavior: driving CI failures on owned PRs to green, keeping a live status
in the thread, and merge-conflict/base-branch-recovery handling.
