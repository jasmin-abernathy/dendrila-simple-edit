# Integration tests for delegated plugin administration

These tests use real WordPress capability mapping, menu registration, users, plugin callbacks, Settings API handlers, AJAX and REST. They complement the dependency-free contract tests. They require a **disposable WordPress installation with its own database and content folder**. The fixture installer creates users and options and activates fixture plugins; never point it at a production site.

## Preparation

Install WordPress with PHP 7.4 or later, a working MySQL/MariaDB database, and a local site URL such as `http://127.0.0.1:8098`. Ensure `wp-content/plugins/dendrila-simple-edit` points at this repository (a symlink is supported). Disable WP Cron and automatic updates in this test installation. PHP needs `mysqli`; the native HTTP suite also needs `proc_open` and local TCP sockets.

```bash
export DSE_WP_ROOT=/absolute/path/to/disposable-wordpress
DSE_PLUGIN_ACCESS_ALLOW_INSTALL=1 php tests/plugin-access/install-fixtures.php
php tests/plugin-access/integration.php
php tests/plugin-access/http-integration.php
```

`install-fixtures.php` installs the two small fixture plugins plus an MU plugin that blocks outbound WordPress HTTP and mail. Test users are created with random passwords; the HTTP tests use real WordPress sessions and authentication cookies without printing credentials. The test marker option prevents accidental execution against a site where the fixtures were not explicitly installed.

The native HTTP suite starts and stops a PHP development server at the test site's configured loopback address. No server should already be listening on that port. HTTP diagnostics and the administrator page HTML go to `DSE_TEST_OUTPUT` if specified, otherwise the system temporary directory's `dse-plugin-access` subdirectory. Do not publish these temporary outputs.

## Coverage

- No selected plugin, each of two independent plugins, and both plugins selected.
- Administrators keep their access; subscribers do not inherit the delegated role.
- Selected plugin menu and submenu access, including `add_options_page()` under Settings.
- Plugin-owned capability checks without granting direct core `manage_options`, installation, user administration or ordinary post/page editing.
- Immediate revocation without persistent role capability changes.
- Nested callback isolation in both directions, including an unselected plugin borrowing a selected plugin's authorization helper through AJAX, REST or `apply_filters()`.
- Selected plugin Settings API groups; mixed, shared core, protected option and special `adminhash` paths are denied.
- Real `options.php` save, sanitization and nonce enforcement, plus actual administrator selection POSTs with valid, invalid, unknown, nested and unauthorized submissions.
- Plugin-owned post types with meta-capability mapping enabled and disabled, native editor routes, taxonomy rights and actual object ownership; forged `post_type` cannot open core pages or another plugin's records.

The default integration suite does not require Calendar. To add the optional compatibility scenario, install an **unmodified** Dendrila Calendar in the disposable site's plugins directory before preparation:

```bash
DSE_TEST_CALENDAR=1 DSE_PLUGIN_ACCESS_ALLOW_INSTALL=1 php tests/plugin-access/install-fixtures.php
DSE_TEST_CALENDAR=1 php tests/plugin-access/integration.php
```

The optional scenario checks Calendar's menus, event AJAX, native event/profile/service/booking editors and immediate revocation using its existing `manage_options` checks. No Calendar source patch is required.

## CI entry

The `WordPress plugin access` job provisions WordPress 7.1.5 and a disposable MariaDB service, then runs the three commands above with `DSE_WP_ROOT` set. The PHP test scripts themselves deliberately do not download WordPress, create a database service or read credentials from another project. The ordinary PHP syntax jobs also lint this directory. The installable ZIP is generated only after both the contract and integration jobs pass.
