# Installing and updating the plugin

<!-- help audience: administrators; screens: -->

How to install the FireHouse plugin from its zip, update it, and what deactivating or deleting it does.

## Get the zip

Each released version's zip is attached to its release on the [FireHouse repository's Releases page](https://github.com/NOAA-GSL/thefirehouse/releases). Install only a released version on a public site: each one has passed GSL's security review.

A zip of any other commit is on that commit's "WordPress plugin" run in the repository's Actions, under Artifacts. Use those only on a local or test site.

## Install it

1. In the dashboard, go to **Plugins → Add New Plugin** and choose **Upload Plugin**.
2. Choose the zip, then **Install Now**.
3. Choose **Activate Plugin**. The FireHouse menu appears.
4. Upload the team's portraits under **Media → Add New Media File**, then load the content on **FireHouse → Import**: see [Importing content](09-importing.md).
5. Check **FireHouse → Settings**, above all the survey address the Submit a Finding buttons go to.
6. Give the people who keep the FireHouse up to date the FireHouse Editor role: see [People and portraits](10-people-and-portraits.md).

## Check it

**Tools → Site Health** lists three FireHouse checks, marked FireHouse. Open it after installing the plugin on a site, and again after anything about the server changes.

- **Outside services:** whether the server can reach doi.org and the services it sends DOI lookups on to, and Cloudflare when the site has a Workers AI key. If one can't be reached, ask whoever runs the server to let it through.
- **Search engines:** a test or local site should keep search engines out, under **Settings → Reading → Search engine visibility**, so its pages aren't taken for the public site's; the public site should let them in. Which kind of site it is comes from WP_ENVIRONMENT_TYPE in the site's wp-config.php.
- **Page caching:** whether a cache in front of the site keeps FireHouse's pages. If it does, a project you hide stays on the pages that showed it until the cache expires: turn the cache off for /firehouse/, or tell the developer. It's marked critical when Cloudflare says it keeps them, and recommended when another cache's headers suggest one does. The server checks its own page, which may not pass through Cloudflare as a visitor's does; the check says how to look from outside.

## Update it

Upload the new version's zip the same way. WordPress shows the installed and uploaded versions side by side; choose **Replace current with uploaded**. The content and the settings stay.

An update that changes the FireHouse Editor role puts the role, and administrators' FireHouse capabilities, back as the plugin defines them. Changes made to either with a role editor don't last past it.

## Deactivate or delete it

- **Deactivating** stops the FireHouse: its project and topic pages no longer open, and the landing and About pages show none of their FireHouse parts. Everything stays in the database, and reactivating brings it back. People with the FireHouse Editor role can then only sign in.
- **Deleting** the plugin also removes the FireHouse Editor role and the FireHouse capabilities, and Analyze results' drafts; people who had the role are left with no role on the site. The projects, topic areas, settings and pages stay in the database.
