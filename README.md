# EqualWeb PDF Checker (tool_eqwpdfcheck)

Free PDF accessibility checker for Moodle. Scans the PDF files on your site,
gives each one a clear 0-100 accessibility score against the PDF/UA standard,
and links a detailed per-file report listing every issue found - so you know
exactly where your documents stand and what to fix first.

## Features
- Automatic discovery of the site's PDF files, checked from one dashboard
  (Site administration > Reports > EqualWeb PDF Checker).
- A 0-100 PDF/UA accessibility score per file, with a detailed issue report.
- Course-level view for teachers (per-course files and scores).
- Free checks run against the EqualWeb checking service - no account, no API
  key and no payment is needed. Checks share a site-wide daily quota.

## External service
Checked files are sent to the EqualWeb service (https://www.equalweb.com) for
analysis and scoring. The Privacy API metadata in this plugin declares exactly
what is transmitted. No user account is required.

## Installation
Standard Moodle plugin installation: unzip into admin/tool/eqwpdfcheck (or
install the ZIP via Site administration > Plugins > Install plugins), then
visit Site administration > Notifications.

Requires Moodle 4.5 or later.

## Related
EqualWeb also offers a full PDF accessibility product with automatic AI
remediation (a separate plugin/service). This checker is complete on its own;
learn more at https://login.equalweb.com.

## License
GNU GPL v3 or later. Copyright 2026 EqualWeb.
