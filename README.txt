Subscription Proxy Viewer
=========================

Files:
- index.php   => admin login
- panel.php   => create/list/delete proxied subscription links
- sub.php     => customer-facing status page
- fetch.php   => server-side remote fetch + parser
- config.php  => config and helpers
- data.json   => storage
- .htaccess   => pretty URLs for /sub/{token}

Important setup:
1) Edit config.php and change ADMIN_USERNAME / ADMIN_PASSWORD.
2) Make sure PHP curl extension is enabled.
3) data.json must be writable by the web server.
4) Apache mod_rewrite must be enabled.

Notes:
- The parser in fetch.php is generic and supports common HTML/JSON label patterns.
- If your source page has a custom structure, parser rules may need a small source-specific adjustment.
- The original source URL is never exposed to the customer browser.
