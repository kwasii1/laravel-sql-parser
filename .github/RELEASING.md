# Releasing

Packagist reads versions from Git tags: there is no version number in `composer.json` to edit.
A release is a `vX.Y.Z` tag on `main`, a GitHub release for it, and Packagist picking the tag up.

## One-time setup

1. **Submit the package to Packagist.** Sign in at <https://packagist.org> with GitHub, choose
   *Submit*, and enter `https://github.com/kwasii1/laravel-sql-parser`. The repository must be
   public.
2. **Let Packagist see new tags.** In your Packagist profile, connect GitHub (*Settings →
   Connect GitHub*). Packagist then installs a webhook on the repository and updates on every push
   and tag. Check it under the package page's *Auto-update* notice.
3. **Optional: secrets for an immediate update.** Copy your API token from your Packagist profile
   and add two repository secrets (*Settings → Secrets and variables → Actions*):
   `PACKAGIST_USERNAME` (your Packagist username) and `PACKAGIST_TOKEN`. The release workflow
   then pings Packagist directly instead of waiting for the webhook.
4. **Branch protection.** The workflow commits the updated `CHANGELOG.md` to `main` as
   `github-actions[bot]`. If `main` is protected against direct pushes, allow GitHub Actions to
   bypass the rule, or that last step fails (the tag and release are already made by then).

## Making a release

1. Merge everything for the release into `main` and make sure the `tests` workflow is green.
2. Open *Actions → Release → Run workflow*, keep the branch on `main` and pick the bump:
   - `patch` (0.1.0 → 0.1.1): bug fixes only.
   - `minor` (0.1.1 → 0.2.0): new features. While the version is 0.x, also use this for
     breaking changes.
   - `major` (0.9.0 → 1.0.0): the first stable release, and breaking changes after it.
   The very first run always creates `v0.1.0`, whatever bump you pick.
3. The workflow runs `composer test`, creates and pushes the tag, publishes a GitHub release
   with notes generated from the merged pull requests (grouped by label, see
   `.github/release.yml`), adds those notes to `CHANGELOG.md`, and notifies Packagist.

Users can then `composer require kwasii/laravel-sql-parser` and get the new version.

## Release notes

Notes are generated from pull request titles and grouped by label (`breaking`, `enhancement`,
`bug`, `documentation`, `dependencies`, `maintenance`). Label pull requests before merging them
to get tidy notes; `skip-changelog` leaves one out. You can edit a release's notes on GitHub
afterwards.
