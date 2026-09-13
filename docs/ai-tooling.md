# AI tooling

What AI-powered tools the project uses, what they're configured to do, and what they cost.

For our **policy** on how AI may be used by contributors and maintainers — what's expected, what's allowed, what isn't — see [`ai-policy.md`](ai-policy.md). This document only describes the tooling itself.

## GitHub Copilot Code Review

Every PR to this repository is automatically reviewed by GitHub Copilot. Copilot leaves inline comments on lines it finds suspicious; the maintainer either addresses them or marks the threads resolved with a justification.

**Configuration** lives in [`.github/copilot-instructions.md`](../.github/copilot-instructions.md). That file is read on every review and tells Copilot:

- The architecture in one screen (provider abstraction, stream wrapper, state machine, connection-health model).
- Hard rules to enforce (security: escaping, sanitisation, nonces, no logged credentials; WordPress conventions; PHP 7.4+ compatibility; structured logging; no premature optimisation).
- Style rules **not** to comment on (Yoda conditions, snake_case, mixed file naming with the DTO/Enum exceptions, base64 in crypto code, `--` long-comment style, unused-param suppressions on hook callbacks).
- What to actively look for (missing escaping/sanitisation, missing nonces, missing timeouts on HTTP calls, missing i18n, prepared-statement issues, decryption failure handling).

The maintainer keeps the file aligned with the codebase as it evolves. New patterns or new "don't flag this" rules go in there, not in PR-by-PR review threads.

**Required reviewer.** [`.github/CODEOWNERS`](../.github/CODEOWNERS) assigns Copilot as a reviewer on every PR. Branch protection requires Copilot's review to be in place before merge — though our rule is "Copilot's comments addressed", not "Copilot says LGTM" (Copilot only ever leaves `COMMENTED`, not `APPROVED`). Resolving the threads it raises (after fixing or after a written justification) is what unblocks the merge.

**Cost.** Copilot Code Review is a *premium feature*. Each review session consumes **one premium request** from the maintainer's monthly Copilot allowance. Several pushes on the same PR ⇒ several reviews ⇒ several requests. The project doesn't pay for this directly — it comes out of the maintainer's personal Copilot subscription. See <https://docs.github.com/copilot/concepts/billing/copilot-requests> for the current quota tables.

**Model.** GitHub describes Copilot Code Review as "a purpose-built product that uses a carefully tuned mix of models". They don't disclose the specific model and don't allow switching. As of this writing, the maintainer is on Copilot Student, which has the same access as the paid tiers.

## WordPress.org MCP server (Copilot Cloud Agent)

The maintainer occasionally drives Copilot via the WordPress.org Model Context Protocol server, which gives Copilot direct read access to the live wp.org plugin page (download counts, ratings, support thread state, the published `readme.txt`, …). It's used for tasks like "what version is the wp.org listing showing right now" or "are there open support threads on the latest release" — questions that would otherwise require manually opening browser tabs.

This is a maintainer-only convenience and isn't required for any contributor workflow. If you don't have it configured locally, nothing in this repo will ask you to.

## What is **not** automated

- **Code generation / patching.** Copilot Code Review **comments** but never pushes code on its own. Patches come from the human or, when the human is using a Copilot agent locally, from a controlled session the human supervises commit-by-commit.
- **Automatic merging.** No bot has the right to merge a PR. Branch protection requires explicit human action via `gh pr merge` (or the GitHub UI).
- **Auto-applied review fixes.** Copilot offers "apply suggestion" buttons in its review comments. The maintainer ignores them — every fix that lands on `main` was deliberately written and reviewed by a human, even if a model proposed the wording.

## Why we use it

The project is one maintainer (see [README — About](../README.md#about)). A second pair of eyes catches things a tired human will miss at 1 a.m., and Copilot Code Review is good at the boring catches: missed escaping, missed nonce, copy-paste mistake in a return type, off-by-one in a sprintf placeholder. None of it is a substitute for the human check that follows — it's a pre-filter that makes the human check tighter.

It also creates an audit trail. Every PR carries a public record of what was flagged and what was decided about each flag. That is far more useful than a private "code review by myself" lap.
