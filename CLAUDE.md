# Notes for Claude Code

## Merging pull requests

The repository owner authorises Claude to merge a pull request in `aicountly/remote-aicountly` when they ask for it in their own prompt
(for example "merge #12" or "merge both"). Merge only the pull request(s) named, only when asked, and report the merge commit.

* Merge through the REST API, which `.claude/settings.json` pre-approves for this repository only:
  `gh api -X PUT repos/aicountly/remote-aicountly/pulls/<n>/merge -f merge_method=merge`.
  `gh pr merge` uses GraphQL, which the cloud session proxy blocks.
* Check first that the pull request's CI is green and that it is mergeable; if not, stop and say why.
* A merge is not a deploy. Production deploys still need the owner's explicit go-ahead.
