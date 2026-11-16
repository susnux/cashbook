<!--
	SPDX-FileCopyrightText: 2024 Ferdinand Thiessen <opensource@fthiessen.de>
	SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Cashbook

Keep the cashbook of your shop or association in Nextcloud.

## Features

- Create cashbooks in any currency
- Browse the entries of a cashbook by month, quarter, year or a custom date range,
  grouped by day when the cash register was opened and closed
- Print the cashbook of a period
- Import cashbooks exported from point of sale systems

## Supported imports

| System | Files |
| --- | --- |
| SumUp POS Pro (German) | The cashbook export (`Kassenbuch_….csv`) and optionally the matching daily closing report to import day openings and closings |

The SumUp export does not contain timezone information, so the timezone of the export is selected when importing.

## Development

The app requires a Nextcloud development setup, see the
[developer documentation](https://docs.nextcloud.com/server/latest/developer_manual/getting_started/devenv.html).
Clone the repository into the `apps` (or `apps-extra`) folder of the server, then install the dependencies and build the frontend:

```sh
composer install
npm ci
npm run dev      # or `npm run watch` to rebuild on changes
```

Enable the app with `occ app:enable cashbook`.

### Tests and linting

```sh
npm run test         # frontend unit tests (vitest)
npm run lint         # ESLint
npm run stylelint    # Stylelint
composer run test:unit  # PHPUnit, requires an installed Nextcloud with the app enabled
composer run test:integration  # PHPUnit API tests against a running Nextcloud, see below
composer run cs:check   # PHP coding standard
composer run psalm      # PHP static analysis
composer run openapi    # regenerate openapi.json
```

The integration tests in `tests/integration` send HTTP requests to a running Nextcloud with the app enabled.
They create and delete their own users. Configure the instance with environment variables:

```sh
NEXTCLOUD_URL=http://nextcloud.local NEXTCLOUD_ADMIN_USER=admin NEXTCLOUD_ADMIN_PASSWORD=admin composer run test:integration
```

`NEXTCLOUD_URL` defaults to `http://localhost:8080`, the credentials default to `admin` / `admin`.

### Translations

The app is translated manually, see the
[developer documentation](https://docs.nextcloud.com/server/stable/developer_manual/app_development/translation.html#manual-translation).
This requires gettext and the translation tool from [nextcloud/docker-ci](https://github.com/nextcloud/docker-ci/tree/master/translations/translationtool).
Run all commands from the app folder:

1. Extract the strings into the template `translationfiles/templates/cashbook.pot`:
   ```sh
   php /path/to/translationtool.phar create-pot-files
   ```
2. Merge the new strings into the translation of a language, e.g. German:
   ```sh
   msgmerge --update --backup=none translationfiles/de/cashbook.po translationfiles/templates/cashbook.pot
   ```
   To add a new language, create `translationfiles/<lang>/cashbook.po` with `msginit` instead.
3. Translate the strings in the `.po` file, e.g. with [Poedit](https://poedit.net).
   Review entries marked as fuzzy and remove obsolete entries.
   `msgfmt --check -o /dev/null translationfiles/de/cashbook.po` reports format errors.
4. Generate the `l10n/<lang>.js` and `l10n/<lang>.json` files used by Nextcloud:
   ```sh
   php /path/to/translationtool.phar convert-po-files
   ```

Commit the template, the `.po` files and the generated `l10n` files.
