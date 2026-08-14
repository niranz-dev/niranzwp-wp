=== NiranzWP ===
Contributors: niranjan
Tags: cli, rest-api, abilities, seo, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Exposes safe, purpose-built abilities so CLIs and AI agents can work with this site through the WordPress Abilities API.

== Description ==

NiranzWP registers abilities through the WordPress Abilities API (core, 6.9+),
so any client that speaks that API can inspect and maintain this site.

Pair it with the NiranzWP CLI:

    npm install -g niranzwp
    niranzwp auth login yoursite.com

There is no configuration file to copy. WordPress asks you to approve the
connection in the browser, and the credential goes to your OS keychain.

Abilities in 0.1.0:

* niranzwp/site-info -- site name, URL, WordPress and PHP versions, theme, locale
* niranzwp/list-plugins -- installed plugins with version and active state
* niranzwp/autoload-report -- autoloaded option weight and the largest entries
* niranzwp/purge-cache -- purge LiteSpeed, W3 Total Cache and the object cache

All of them require an administrator. Three of the four are read-only.

== Security ==

Abilities are OFF after activation and must be switched on deliberately.

They are also locked to the domain they were enabled on. Restoring a database
onto a different host does not carry access with it -- abilities must be
re-enabled there.

There is deliberately no arbitrary code execution ability in this release. A
general execute-php endpoint hands full control of the site to anyone holding
the credential, and that belongs behind a considered decision rather than a
default.

== Installation ==

1. Upload the ZIP under Plugins > Add New > Upload Plugin, and activate.
2. Open NiranzWP > Configuration and switch abilities on.
3. Check NiranzWP > Troubleshoot if anything looks wrong.

== Changelog ==

= 0.1.0 =
* First release: four abilities, Configuration / Abilities / Troubleshoot screens.
