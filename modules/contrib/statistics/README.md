# Statistics

## Introduction

The Statistics module provides anonymous analytics,
counting entity views for all users, including anonymous ones.

This module is currently equivalent to the legacy version previously in Drupal core:

- Counting views for nodes only in full-page mode.
- Maintaining only a running grand total and daily subtotal, with no historical data.

This version, however, aims to provide more comprehensive and flexible statistics in future releases.

For a full description of the module, visit the project page:
[https://www.drupal.org/project/statistics](https://www.drupal.org/project/statistics)


## Requirements

- Drupal ^10.3 or ^11.
- The core entity system, installed by default

### Web Server Configuration

This module uses its own front controller (`statistics.php`) for performance reasons.
Your web server must be configured to properly direct requests to this file.

If you are using **Apache 2.4+**, the provided `.htaccess` file handles this automatically,
provided your virtual host configuration allows `.htaccess` overrides.

For other web servers (like Nginx) or for improved performance on Apache,
you must add equivalent rewrite rules to your server's configuration directly.


## Installation

Install as you would normally install a contributed Drupal module.

From your project's root directory, run the following command:

```bash
composer require drupal/statistics
```

If this is a fresh Drupal 11.x site, there is nothing specific to do,
as Drupal core no longer ships with the legacy Statistics module.

If, however, you are adding the contrib module to an existing Drupal 10.3+ site,
you can still install this contrib version of Statistics using Composer,
and Drupal will automatically use it instead of the core Statistics module,
as described below.

### Replacing the Core Statistics Module

If you are upgrading from a Drupal 10.3+ site that used the core Statistics module,
Drupal will automatically detect and prefer this contributed version.

You do not need to uninstall or remove the deprecated core module.

Drupal's module discovery system prioritizes modules in the contrib directory over those in core,
looking for modules in `core/modules` last, after all the other possible places that modules can live,
ensuring a seamless transition.

For more details, see the official documentation:
[How to replace a deprecated core module with its contrib version](https://www.drupal.org/docs/core-modules-and-themes/deprecated-and-obsolete#s-how-to-replace-a-deprecated-core-module-with-its-contrib-version).

## Configuration

1.  After enabling the module, navigate to the configuration page at:
    `Administration > Configuration > System > Statistics` (`/admin/config/system/statistics`).
2.  Enable _Count content views_ and click _Save configuration_.


## Maintainers

Current maintainers :

- Frédéric G. MARAND _aka_ fgm (https://www.drupal.org/u/fgm)

This project has been sponsored by:

- OSInet (https://osinet.fr)
