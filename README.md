# Forgejo / Gitea integration for Nextcloud

Dashboard widgets and (later) unified search, link previews and notifications for [Forgejo](https://forgejo.org/) and [Gitea](https://gitea.io/) instances, from inside Nextcloud.

Two configurable dashboard widgets — **Open Issues** and **Closed Issues** — each with a per-widget repository picker and an issue-scope filter (assigned / created / mentioned / all). Add one, add both, filter each one independently.

---

## Status

Pre-release. See [CHANGELOG.md](CHANGELOG.md) for what has landed. Roadmap:

1. Scaffold + build pipeline (done)
2. OAuth authorization-code flow end-to-end
3. Both dashboard widgets rendering real issues from `/repos/issues/search`
4. Unified search, link previews (Talk/Text/Deck), notification background job
5. First tagged release, screenshots, appstore submission

---

## Requirements

- Nextcloud **30 – 34**
- PHP **8.1+**
- A Forgejo or Gitea instance with an OAuth application registered for this app
- Node **20+** and npm **10+** for building from source

---

## Installation (from source)

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/njordium/integration_forgejo_gitea.git
cd integration_forgejo_gitea
npm ci
npm run build
```

Then enable it in **Apps → Integration → Forgejo / Gitea integration**.

---

## Configuration

Fleshed out once the OAuth flow lands. Sketch:

**Admin** — register an OAuth application on your Forgejo/Gitea instance (Site Administration → Applications) with redirect URI `https://<your-nextcloud>/apps/integration_forgejo_gitea/oauth-redirect`. Enter the resulting client id and secret plus the instance URL in **Settings → Administration → Connected accounts → Forgejo / Gitea integration**.

**Per user** — open **Settings → Personal → Connected accounts → Forgejo / Gitea integration** and click **Connect**. After OAuth authorization, add the widgets to your dashboard and pick your repos and filter via the gear icon inside each widget.

---

## Development

```bash
# JS/Vue
npm ci
npm run watch       # dev build with file watching
npm run lint
npm run stylelint
npm run build       # production build

# PHP
composer install
vendor/bin/phpunit  # unit tests
vendor/bin/phpstan analyse -c phpstan.neon
```

---

## Contributing

Issues and pull requests welcome at [njordium/integration_forgejo_gitea](https://github.com/njordium/integration_forgejo_gitea/issues).

---

## License

AGPL-3.0-or-later. See [COPYING](COPYING).
