# Sona Notifier

Sona Notifier is a PHP script that periodically checks an authenticated website for the availability of new research studies on the Sona web-based human subject pool management software. It would send an email with the list of studies when a new available study was posted.

# Setup

## Configuration file

In `cron.php`:

Set the variables to the appropriate values.

## Cron Job

Add a cron job to run cron.php while in its directory. Optionally, log the output by appending it to a file.

```
*/5 * * * * cd /path/to/sona-notifier/; php -q cron.php >> cron_log.txt;
```
