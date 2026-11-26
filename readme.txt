=== Dendrila Simple Edit ===
Contributors: juliane16
Donate link: https://app.lepotager.org/soutenir/
Tags: editor, permissions, newsletter, content, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A guided editing space for WordPress pages, posts, events, products and email newsletters with permissions by role.

== Description ==

Dendrila Simple Edit provides a restricted editing interface where site owners choose which content and which actions WordPress roles can perform. It does not grant the delegated role unrestricted access to WordPress settings, orders or payments.

Features:

* Guided editing by content type (pages, posts and events), with permissions per WordPress role, safe content blocks, image selection and optional approval before publication. Administrators keep their native access.
* Safe, selective Gutenberg editing: change plain text in supported paragraph, heading and list-item blocks without reserializing the surrounding group, theme or WooCommerce blocks. Inline-rich and unsupported blocks remain untouched.
* Role-based permissions with a five-step wizard shown on first installation only (never automatically on update or reactivation), inline mailing-list creation and an optional manual configuration review without erasing contacts or lists.
* Compact administration settings: enable or disable modules (including newsletters) independently, manage content types and media under Content, and configure per-role permissions in collapsible groups.
* Select additional active plugins under Permissions > Plugin access for the Simple Editing role. Registered plugin menus, owned custom content, dedicated Settings API forms and plugin permission checks are supported without assigning the administrator role or permanent global capabilities.
* Organize the primary navigation by drag-and-drop on touch and desktop, with keyboard/button alternatives, one-level submenus and an open/closed default for each mobile submenu. Converting an automatic Page List to manually managed navigation requires explicit confirmation; newly published pages will then need adding through WordPress.
* Optional integration with Advanced Custom Fields (supported text fields), The Events Calendar (events) and WooCommerce (products).
* WooCommerce simple, external, grouped and variable products with delegated creation, publishing, categories, variations and stock controls where authorized.
* A built-in newsletter composer with multiple table-based email layouts, customizable palettes saved per author, preview, mailing lists, sending queue and unsubscribe management.
* Optional WooCommerce checkout newsletter opt-in. Customers must actively select the checkbox and confirm their email address; no customer is automatically subscribed from a purchase.
* Historical unsubscribe protection: old opt-outs are recovered on upgrade, stored independently of mailing lists, and checked again before each email delivery.
* Shortcodes [dse_text field="phone"] and [dse_newsletter_inscription liste="1"].
* Green-themed administration and a support link displayed only in the plugin's administration, not on public pages.

Builder compatibility: content created by Divi and other page builders is not automatically rewritten. Unsupported layouts are intentionally excluded from delegated edits. Access and compatibility must be tested on the site's own plugins and themes.

== External services and privacy ==

Dendrila Simple Edit stores its settings, role assignments, mailing lists, email addresses, consent timestamps, campaign status and suppression history in the site's WordPress database. Suppression history uses normalized email hashes and is retained so previous unsubscribes remain effective after plugin updates. Site owners are responsible for providing appropriate privacy information and handling data-subject requests.

The newsletter module requires the separately installed and activated FluentSMTP plugin with at least one configured outgoing connection. This requirement applies only to newsletters, not to Simple Edit itself. Outgoing mail uses WordPress wp_mail() through FluentSMTP. The plugin does not send subscriber lists or site content to the plugin author and does not include analytics or advertising telemetry.

The optional WooCommerce checkout integration runs only when enabled by the administrator. Orders and payments remain managed by WooCommerce. An opt-in is never checked by default. The site must configure its own privacy and newsletter policy.

The support/donation link opens https://app.lepotager.org/soutenir/ only when an administrator or delegated editor clicks it in the plugin's administration screen.

== Installation ==

1. Back up your WordPress database, especially mailing data, before replacing an earlier experimental version.
2. Upload the ZIP through Plugins > Add New > Upload Plugin, then activate Dendrila Simple Edit.
3. On the first installation, complete the five-step configuration assistant as a user authorized to activate/install plugins. Updating or reactivating an existing configured installation does not restart it; use Settings > Modules > Reopen setup to review it manually.
4. Choose enabled modules, per-role actions and editable content types (not individual items). Administrators keep native access. Configure email transport before enabling real newsletters.
5. Check the WooCommerce product editor and email opt-in in a test environment before using them with real orders or contacts.
6. For a shortcode, insert it in a WordPress Shortcode block, not in a page-builder internal data structure.

== Frequently Asked Questions ==

= Can simple editors use another installed plugin? =
Yes. An administrator can select active extensions under Permissions > Plugin access. This applies to the whole Simple Editing role and is not limited to Dendrila Calendar. Unchecking an extension revokes access on the next action. Plugin functionality can include sensitive operations; grant access only to the extensions the team should manage. Plugins with direct administrator-role checks, early conditional initialization, additional undocumented capabilities, shared core settings groups or external services may need an adapter. Shared WordPress settings remain restricted.

= Can I delegate publishing without giving access to WooCommerce orders? =
Yes. Product creation, publication, variations, categories, stock and other operations are independently authorized for WordPress roles. Order, refund and payment administration is not delegated.

= What happens to a person who unsubscribed before an update? =
A database migration searches earlier mailing tables and records unsubscribed addresses in a separate suppression register. Campaign delivery is blocked if this migration has not completed. An opted-out address cannot be reactivated by importing a list; only a fresh voluntary confirmation records a new consent while preserving the earlier opt-out history.

= Can I use WooCommerce and newsletters independently? =
Yes. Each module is optional. Checkout opt-in is disabled by default and requires a mailing list and an email transport.

= Can I edit existing Gutenberg pages and posts? =
Yes, when they contain supported plain-text Paragraph, Heading or List Item blocks. Simple Edit updates only those text nodes and preserves other serialized Gutenberg blocks unchanged, including WooCommerce layouts. Blocks with inline links, rich formatting and unsupported/custom layouts are not yet editable here; they remain intact and can be edited in the native WordPress editor. Delegated permissions, content versions and optional approval still apply.

= Is a Divi layout editable? =
Not automatically. Unsupported layouts remain protected; administrators can choose safe shortcode placements, while native Divi content may need Divi's original editor.

= Does creating a submenu change page URLs? =
No. Simple Edit changes only the navigation structure, not the parent or permalink of any page. A WordPress automatic Page List is converted to an explicit menu only after a confirmation; newly created pages are then added manually in the WordPress site editor.

= How do I support development? =
The plugin's administration includes an optional link to https://app.lepotager.org/soutenir/. No donation is required to use the extension.

== Changelog ==

= 0.0.2 =
* Administrator selection of additional active plugins for simple editors, with detected menu labels and immediate revocation.
* Request-local plugin permissions, owned content/taxonomy editing and dedicated Settings API forms, without permanent administrator capabilities.
* Preserve restrictions on unselected plugins, WordPress settings and Simple Edit configuration.

= 0.0.1 =
* Initial guided content editor and granular WordPress role permissions.
* WooCommerce product types, categories, variation editing, stock and publishing workflows.
* Newsletter templates and palettes, optional WooCommerce opt-in and durable unsubscribe history.
* First-install-only guided setup, compact role permissions and module activation controls, and mobile administration improvements.
