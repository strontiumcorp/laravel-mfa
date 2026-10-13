# Installing from a private repository

How apps install `strontiumcorp/laravel-mfa` (or any other private Strontium package) once its GitHub repository is private: on developer machines, in GitHub Actions, in Docker builds and on servers.

Every place that runs `composer install` needs read access to the repository. We use **one read-only deploy key per private repository**. A deploy key belongs to the repository (not to a person), never expires, can't push, and can't read anything else. In this guide:

| Thing | Value for laravel-mfa | Pattern for another package |
|---|---|---|
| Repository | `git@github.com:strontiumcorp/laravel-mfa.git` | `git@github.com:strontiumcorp/<repo>.git` |
| Key file | `laravel_mfa_deploy` | `<repo_snake>_deploy` |
| GitHub Actions secret | `LARAVEL_MFA_DEPLOY_KEY` | `<REPO_SNAKE>_DEPLOY_KEY` |
| Docker SSH id | `laravel_mfa` | `<repo_snake>` |
| SSH host alias | `github-laravel-mfa` | `github-<repo>` |

**Order matters:** switch every app to the setup below and deploy each one *before* making the repository private. Otherwise the next build of any app fails.

## Why SSH, and why `"type": "git"`

A deploy key only works over SSH, so Composer has to clone over `git@github.com:`. GitHub's zip archives (`api.github.com/.../zipball/...`) need a token, and a deploy key isn't one.

Use a `git` repository, not `vcs`. With `vcs`, Composer still writes the zipball into `composer.lock` as the `dist`. Composer 2.10 no longer falls back from a failed dist to the git source ("Source fallback is disabled", and the option goes away in 2.11), so the install fails. With `"type": "git"`, the lock has no `dist`: Composer always clones over SSH.

## 1. Create the deploy key (once per private repository)

```bash
ssh-keygen -t ed25519 -N "" -C "git@github.com:strontiumcorp/laravel-mfa.git" -f laravel_mfa_deploy
```

- The comment must be the repository's SSH URL: `webfactory/ssh-agent` uses it to route each key to its repository (see [Several private packages](#several-private-packages)).
- No passphrase: CI and Docker can't type one.
- **laravel-mfa → Settings → Deploy keys → Add deploy key:** paste `laravel_mfa_deploy.pub`, leave **Allow write access unchecked**.
- Store the private key `laravel_mfa_deploy` in the team password manager, then delete the local copies. It's the value for the secrets and servers below.

A public key can be a deploy key on only one repository, which is why each private package gets its own key.

## 2. The app's `composer.json`

```json
"repositories": [
    { "name": "laravel-mfa", "type": "git", "url": "git@github.com:strontiumcorp/laravel-mfa.git" }
]
```

Then re-lock just the package (with your own GitHub SSH access, see step 3):

```bash
composer update strontiumcorp/laravel-mfa
```

Check the result: in `composer.lock`, the package's `source.url` is the `git@github.com:` URL and it has **no `dist`** entry. The version doesn't change.

## 3. Developer machines

Nothing to add. A developer who belongs to the organization and has an SSH key on their GitHub account can install over SSH already (`ssh -T git@github.com` greets them by name).

When Composer runs in a container (Sail, a `php` Docker wrapper), the container needs the host's SSH agent and an `ssh` binary. Either run `composer install` on the host, or mount the agent (`-v "$SSH_AUTH_SOCK:/ssh-agent" -e SSH_AUTH_SOCK=/ssh-agent`). OpenSSH refuses to run as a user ID with no passwd entry ("No user exists for uid 1000"), so use a container user that exists.

## 4. The secret in GitHub Actions

The secret goes on each **app** repository (the one whose workflow runs `composer install`), not on laravel-mfa:

**App repository → Settings → Secrets and variables → Actions → New repository secret**, name `LARAVEL_MFA_DEPLOY_KEY`, value: the whole private key, from `-----BEGIN OPENSSH PRIVATE KEY-----` to the `END` line.

Or from a terminal:

```bash
gh secret set LARAVEL_MFA_DEPLOY_KEY --repo <owner>/<app> < laravel_mfa_deploy
```

- A repository secret is also visible to jobs that use `environment:`. Use an environment secret only to give environments different keys.
- A reusable workflow (`on: workflow_call`) that declares its `secrets:` must declare this one too, and the caller must pass it (or use `secrets: inherit`).
- Dependabot pull requests can't read Actions secrets. Add the same key under **Settings → Secrets and variables → Dependabot** if Dependabot runs these workflows.
- Add the secret **before** merging the workflow changes below: a missing secret fails the job.

## 5. Composer on a GitHub Actions runner

Load the key into an SSH agent before `composer install`. If the install is skipped on a cache hit, skip this step too:

```yaml
      - name: Load private Composer package keys
        if: steps.composer-deps.outputs.cache-hit != 'true'
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.LARAVEL_MFA_DEPLOY_KEY }}

      - name: Install PHP deps
        if: steps.composer-deps.outputs.cache-hit != 'true'
        run: composer install --no-interaction --no-progress --prefer-dist
```

The action also adds GitHub's host keys to `known_hosts`.

## 6. Docker builds

The key is passed to the build as a BuildKit SSH mount: it's available only while that one `RUN` step runs and never ends up in a layer, the image or the build cache.

### Dockerfile

```dockerfile
# syntax=docker/dockerfile:1

# The stage that runs composer install needs git and an SSH client
# (Alpine: apk add git openssh-client; Debian: apt-get install git openssh-client).

# Route each private repository to its key's socket.
RUN mkdir -p -m 0700 /root/.ssh \
 && echo 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl' > /root/.ssh/known_hosts \
 && printf '%s\n' 'Host github-laravel-mfa' '  HostName github.com' '  User git' '  IdentityAgent /run/ssh/laravel_mfa' >> /root/.ssh/config \
 && git config --global url.'git@github-laravel-mfa:strontiumcorp/laravel-mfa.git'.insteadOf 'git@github.com:strontiumcorp/laravel-mfa.git'

COPY composer.json composer.lock ./
RUN --mount=type=ssh,id=laravel_mfa,target=/run/ssh/laravel_mfa \
    composer install --no-dev --prefer-dist --no-interaction --no-scripts
```

- The `known_hosts` line is GitHub's published ed25519 host key (compare it with `curl -s https://api.github.com/meta`). Pinning it beats `ssh-keyscan` during the build, which trusts whatever answers.
- `/root/.ssh` assumes Composer runs as root. If the stage switches `USER`, write that user's `~/.ssh` and `git config --global` instead.
- `git config` and `~/.ssh/config` hold no secrets, so carrying them into later stages is harmless.
- Packages installed from git keep their `.git` directory. Add `-o -name .git` to the stage's vendor cleanup (`find vendor -type d \( ... \) -prune -exec rm -rf '{}' +`) so it doesn't reach the runtime image.
- The image contains the package's source, like the rest of `vendor/`. Push it only to a private registry.

### Building in GitHub Actions (`docker/build-push-action`)

Write the secret to a key file and pass it with `ssh:`:

```yaml
      - name: Write private Composer package keys
        env:
          LARAVEL_MFA_DEPLOY_KEY: ${{ secrets.LARAVEL_MFA_DEPLOY_KEY }}
        run: |
          if [ -z "$LARAVEL_MFA_DEPLOY_KEY" ]; then
            echo "::error::The LARAVEL_MFA_DEPLOY_KEY secret is not set."
            exit 1
          fi
          install -d -m 700 "$RUNNER_TEMP/composer-keys"
          printf '%s\n' "$LARAVEL_MFA_DEPLOY_KEY" > "$RUNNER_TEMP/composer-keys/laravel_mfa"
          chmod 600 "$RUNNER_TEMP/composer-keys/laravel_mfa"

      - uses: docker/build-push-action@v6
        with:
          # ...
          ssh: |
            laravel_mfa=${{ runner.temp }}/composer-keys/laravel_mfa
```

A `type=gha` build cache stores the `composer install` layer (`vendor/`, the package source included) in the app repository's Actions cache. That's fine for a private app repository.

### Building on a server with Docker Compose

Put the key on the server once (see [Servers](#7-servers-that-run-composer-install)), then pass it in the service's `build`:

```yaml
x-build: &default_build
  context: ./
  dockerfile: Dockerfile
  ssh:
    - laravel_mfa=${HOME}/.ssh/laravel_mfa_deploy
```

### Building locally

```bash
docker buildx build --ssh laravel_mfa=$SSH_AUTH_SOCK .
```

This uses your own GitHub key from your agent instead of the deploy key.

## 7. Servers that run `composer install`

Some apps deploy by pulling the code onto the server and running Composer there. Set up each server once, as the user that runs Composer:

```bash
install -d -m 700 ~/.ssh
# Paste the private key from the password manager:
cat > ~/.ssh/laravel_mfa_deploy && chmod 600 ~/.ssh/laravel_mfa_deploy

cat >> ~/.ssh/config <<'EOF'

Host github-laravel-mfa
    HostName github.com
    User git
    IdentityFile ~/.ssh/laravel_mfa_deploy
    IdentitiesOnly yes
EOF

git config --global url."git@github-laravel-mfa:strontiumcorp/laravel-mfa.git".insteadOf "git@github.com:strontiumcorp/laravel-mfa.git"
```

Check it:

```bash
ssh -T git@github-laravel-mfa
```

```bash
git ls-remote git@github.com:strontiumcorp/laravel-mfa.git HEAD
```

The first command should say `Hi strontiumcorp/laravel-mfa! You've successfully authenticated`, naming the repository, not a person. The second goes through the `insteadOf` rewrite, so it proves Composer will use the deploy key.

The alias matters because of how GitHub authenticates. SSH offers keys one at a time, and GitHub accepts the **first key it knows**, before it knows which repository you want. If the server's agent or `~/.ssh` also holds another GitHub key (the app repository's deploy key, a personal key), GitHub may accept that key and then answer "Repository not found" for laravel-mfa. `IdentitiesOnly yes` makes the alias offer only the laravel-mfa key, agent or not.

## Several private packages

One package's key doesn't break another's, as long as each repository is routed to its own key. Without routing, two GitHub keys in one agent (or in `~/.ssh`) fail as described above: whichever key is offered first wins, and the other repository gets "Repository not found". Each place above already routes per repository. To add a package `strontiumcorp/acme-billing`:

1. Create its own deploy key (step 1), comment `git@github.com:strontiumcorp/acme-billing.git`.
2. Add its `"type": "git"` repository to the app's `composer.json` and re-lock it.
3. Add a secret `ACME_BILLING_DEPLOY_KEY` to each app repository.
4. **Runner:** list both keys in the agent step. `webfactory/ssh-agent` reads each key's comment and rewrites that repository's URLs to that key:
   ```yaml
          ssh-private-key: |
            ${{ secrets.LARAVEL_MFA_DEPLOY_KEY }}
            ${{ secrets.ACME_BILLING_DEPLOY_KEY }}
   ```
5. **Dockerfile:** one more host alias (`Host github-acme-billing`, `IdentityAgent /run/ssh/acme_billing`), one more `insteadOf`, and one more mount on the same `RUN`:
   ```dockerfile
   RUN --mount=type=ssh,id=laravel_mfa,target=/run/ssh/laravel_mfa \
       --mount=type=ssh,id=acme_billing,target=/run/ssh/acme_billing \
       composer install ...
   ```
6. **Build:** write the second key file and add `acme_billing=<path>` to `ssh:` (build-push-action) or to the Compose `build.ssh` list.
7. **Servers:** one more key file, `Host github-acme-billing` block and `insteadOf`.

With more than a handful of private packages, a GitHub App or a machine user with read access to all of them (one credential, `COMPOSER_AUTH`) becomes less work than one key per repository.

## Rotating or revoking a key

1. Create a new key (step 1) and add it as a second deploy key.
2. Replace the secret in every app repository and the key file on every server.
3. Delete the old deploy key on GitHub. That revokes it everywhere at once.

If a key leaks, delete it on GitHub first: it can only read this one repository, so nothing else needs to change.

## Troubleshooting

| Error | Cause |
|---|---|
| `Permission denied (publickey)` | No key reached SSH: the secret is empty, the Docker build has no `--ssh`/`ssh:` for that id, or the key file isn't readable. |
| `ERROR: Repository not found` | Another GitHub key was offered first. Route the repository to its key (host alias + `insteadOf`, or the webfactory key comment). |
| `Host key verification failed` | No GitHub entry in `known_hosts` (add the pinned line). |
| `Source fallback is disabled. Not trying alternative sources.` | The lock still has the zipball `dist`: the repository is `vcs`, not `git`. Fix `composer.json` and re-lock. |
| `No user exists for uid ...` | OpenSSH in a container running as a user ID with no passwd entry. Run as an existing user. |
| `The ssh-private-key argument is empty` | `webfactory/ssh-agent` ran without the secret (missing secret, a fork or Dependabot pull request). |
