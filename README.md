# EqualWeb PDF Checker (tool_eqwpdfcheck)

Free PDF accessibility checker for Moodle. Scans the PDF files on your site,
gives each one a clear 0-100 accessibility score against the PDF/UA standard,
and links a detailed per-file report listing every issue found - so you know
exactly where your documents stand and what to fix first.

## Features
- Automatic discovery of the site's PDF files, checked from one dashboard
  (Site administration > EqualWeb PDF Checker - the plugin adds its own
  top-level entry, it is not filed under Reports).
- A 0-100 PDF/UA accessibility score per file, with a detailed issue report.
- Course-level view for teachers (per-course files and scores).
- Free checks run against the EqualWeb checking service - no account, no API
  key and no payment is needed. Checks share a site-wide daily quota.

## Requirements
- Moodle 4.5 or later.
- A public HTTPS site: wwwroot must be an https:// address that is reachable
  from the internet, with a certificate from a public certificate authority.
- Anonymous access to https://<your site>/admin/tool/eqwpdfcheck/verify.php
  while the site registers for free checks. The EqualWeb service fetches this
  one address to confirm that the site runs the plugin, so no single sign-on,
  login redirect, IP allow-list or firewall rule may stand in front of it.
- Outbound HTTPS (port 443) from the web server to
  eqw-pdf-plugins-free-check.wcag2worker.workers.dev, through any proxy, and
  not in the Moodle cURL blocked hosts list.

If registration does not complete, a notice at the top of the plugin page says
why and what to change, with a Try again button and technical details for
EqualWeb support (https://www.equalweb.com/platform/pdf_tools/support.html).
The same status appears in Site administration > Reports > System status.

## External service
Checked files are sent to the EqualWeb service (https://www.equalweb.com) for
analysis and scoring. The Privacy API metadata in this plugin declares exactly
what is transmitted. No user account is required.

To register the site for free checks, the plugin sends the EqualWeb service the
site address, the verification address, a one-time code, the plugin and Moodle
versions, and what started the attempt. For a failed attempt the service keeps
the reason, with the name and number (ASN) of the network and the country it
came from, for 30 days, so that failures can be diagnosed.

## Installation
Standard Moodle plugin installation: unzip into admin/tool/eqwpdfcheck (or
install the ZIP via Site administration > Plugins > Install plugins), then
visit Site administration > Notifications.

## Related
EqualWeb also offers a full PDF accessibility product with automatic AI
remediation (a separate plugin/service). This checker is complete on its own;
learn more at https://login.equalweb.com.

## License
GNU GPL v3 or later. Copyright 2026 EqualWeb.
