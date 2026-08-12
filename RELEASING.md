# Releasing the plugin

Two distribution channels, and they are not the same audience:

| Channel | Who uses it | How it updates |
|---|---|---|
| **GitHub Releases** | People we send to `PLUGIN_RELEASES_URL` from the Visibility UI | They download the zip manually |
| **wordpress.org** | Every existing install, worldwide | WordPress pushes an update notification automatically |

wordpress.org is the loud one. Committing there notifies **every site running the
plugin**. Treat it as a deploy, not a push.

---

## Credentials

- **wordpress.org user:** `rankth`
- **SVN password:** *not* the account password. WordPress.org issues a separate,
  SVN-only credential that cannot log into the account. Generate one at
  [profiles.wordpress.org/me/profile/edit/](https://profiles.wordpress.org/me/profile/edit/)
  → **Account & Security**.
  - It is randomly generated; you cannot choose it.
  - Generating a new one **invalidates every previous SVN password**.
  - wp.org never stores the raw value, so if you lose it, generate again.
  - wp.org requires 2FA on committer accounts (it does not apply to SVN itself).

**The password is not stored in this repo, and must not be.** It lives in two
places only:

1. Your password manager.
2. This repo's GitHub Actions secrets, for the automated release:
   - `WPORG_SVN_USERNAME` → `rankth`
   - `WPORG_SVN_PASSWORD` → the generated SVN password

Set them at *Settings → Secrets and variables → Actions*. Rotate the wp.org
password any time it has been pasted into a chat, terminal, or ticket, then
update the secret.

---

## Automated release (preferred)

`.github/workflows/publish-wordpress-org.yml` publishes to wordpress.org when a
**GitHub release is published** — never on an ordinary push, so day-to-day
commits are unaffected.

1. Bump the version in **three** places — they must agree or the workflow fails:
   - `agentic-seo-visibility/agentic-seo-visibility.php` → `* Version:`
   - the same file → `VISIBILITY_PLUGIN_VERSION`
   - `agentic-seo-visibility/readme.txt` → `Stable tag:`
2. Add a `readme.txt` changelog entry.
3. Commit, then tag `vX.Y.Z` and push the tag.
4. Build the zip and create the GitHub release with it attached:
   ```bash
   zip -rq agentic-seo-visibility-X.Y.Z.zip agentic-seo-visibility \
     -x "agentic-seo-visibility/.distignore" -x "agentic-seo-visibility/.git/*"
   gh release create vX.Y.Z agentic-seo-visibility-X.Y.Z.zip \
     --title "vX.Y.Z — short summary" --notes-file notes.md
   ```
5. Publishing that release triggers the wp.org push. Watch it in the Actions tab.

The workflow refuses to run if the git tag disagrees with the plugin version,
if `Stable tag` is stale, or if the wp.org tag already exists.

To re-run without cutting a new release, use **workflow_dispatch** and pass the
existing tag.

---

## Manual release (fallback)

```bash
SLUG=agentic-seo-visibility
svn co --depth immediates https://plugins.svn.wordpress.org/$SLUG wporg
cd wporg && svn up --set-depth infinity trunk && cd ..

# --delete is REQUIRED. Without it, files deleted from the plugin linger in
# trunk and get shipped again -- this is exactly how the admin screens removed
# in 0.8.0 nearly went back out in 0.9.1.
rsync -a --delete --exclude '.svn' --exclude '.git' --exclude '.github' \
  --exclude '.distignore' $SLUG/ wporg/trunk/

cd wporg
svn add --force trunk --auto-props --parents --depth infinity -q
svn status trunk | grep '^!' | awk '{print $2}' | xargs -r svn rm --force
svn status trunk          # review before committing

svn cp trunk tags/X.Y.Z
printf '%s' "$SVN_PASSWORD" | svn ci --username rankth --password-from-stdin \
  --no-auth-cache --non-interactive -m "Release X.Y.Z"
```

Never pass the password as a command-line argument — it lands in shell history
and in the process list. Pipe it to `--password-from-stdin`.

### Verify

```bash
svn ls https://plugins.svn.wordpress.org/$SLUG/tags/
svn cat https://plugins.svn.wordpress.org/$SLUG/trunk/readme.txt | grep 'Stable tag:'
```

wordpress.org serves whatever `trunk/readme.txt`'s `Stable tag` points at, so a
release is only live once that value matches and the matching `tags/X.Y.Z`
exists.

---

## Notes

- The plugin has **no self-updater**. GitHub-release users must upload the new
  zip by hand; only wordpress.org pushes updates automatically.
- Root `assets/` in SVN is the wordpress.org listing artwork (banner, icon) —
  it is *not* plugin code, and the release process must not touch it.
  `trunk/assets/` is the plugin's own assets and is synced normally.
- Test against a real WordPress before releasing, not just stubs. See the
  Podman rig notes; 0.9.1 existed only because the admin screen had never been
  opened in a browser.
