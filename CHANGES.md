# Changes

## 1.1.0 (2026-10-07)
- The plugin page now explains why free checks are not set up yet. When the
  EqualWeb service cannot register the site, a notice at the top of the page
  names the reason (for example a firewall, a redirect to a sign-in page, a
  certificate problem or a site that is not reachable from the internet), says
  what to change, shows the exact address to allow, and offers a Try again
  button, a link to EqualWeb support and copyable technical details. Teachers
  on course pages get a one-line note instead. Messages saved by earlier
  versions are explained the same way after the upgrade.
- Registration runs after the page has loaded instead of delaying it, one
  attempt at a time, with a 10 second connection timeout. Automatic attempts
  back off (10 minutes, then 1 hour, 6 hours and 24 hours); a reason that needs
  a person to change something waits for Try again.
- Registration health appears in Site administration > Reports > System status.
- Registration tells the EqualWeb service which plugin and Moodle versions made
  the attempt and what started it, so failures can be diagnosed there as well.
- A check that reaches the daily limit, or finds the registration no longer
  valid, now shows a plain explanation instead of the service's raw message.
  A registration the service no longer accepts shows in the notice, with Try
  again.
- A site that moved to a new address, or a copy of another site, registers
  again at its own address instead of reporting that free checks are set up.
- Error messages and technical details no longer include the proxy password
  from the Moodle HTTP proxy settings.

## 1.0.5 (2026-08-06)
- Tidied internal developer comments out of the shipped code. No functional change.

## 1.0.4 (2026-08-06)
- Removed the unused "throttle" cache definition. It rate-limited the paid
  remediation endpoint, which this checker does not ship, so the definition was
  dead here - and Moodle labels cache definitions from a cachedef_<name> language
  string that therefore did not exist. Reported in the review of 1.0.1; thanks to
  Volodymyr Dovhan.

## 1.0.3 (2026-08-06)
- Renamed this plugin's course page title from "PDF Accessibility" to
  "PDF Checker", completing the 1.0.2 rename. The course-menu entry already said
  "PDF Checker", but the page it opened was still titled after the full EqualWeb
  PDF Accessibility product.

## 1.0.2 (2026-08-06)
- Renamed this plugin's course-menu entry from "PDF accessibility" to
  "PDF Checker", after its own product name. The checker and the full EqualWeb
  PDF Accessibility plugin are designed to install side by side on one Moodle,
  and both add a course-menu entry - sharing one label showed a teacher the same
  wording twice with no way to tell the two apart.

## 1.0.1 (2026-08-06)
Compatibility and wording fixes. No functional change to checking.
- Declared compatible with Moodle 5.x: the plugin no longer pins itself to the
  4.5 branch, so Moodle 5.0/5.1/5.2 no longer show a "may not be compatible"
  notice. Verified on Moodle 5.2.1, including the 5.1 public/ restructure.
- Reworded the messages that referred to a Settings tab, an API key, an EqualWeb
  account or remediation - none of which exist in this checker. In particular,
  a site that is not yet registered now explains that registration is automatic
  and needs the site to be reachable from the internet, instead of pointing at a
  settings screen this plugin does not have.
- Corrected the README: the plugin adds its own top-level entry in Site
  administration; it is not filed under Reports.

## 1.0.0 (2026-07-31)
First public release.
- Site-wide PDF discovery + accessibility checks (PDF/UA), 0-100 score per
  file with a detailed report link.
- Course-level checker page for teachers.
- One check at a time with a clear in-progress lock.
- Privacy API (external service disclosure), GDPR-ready.
