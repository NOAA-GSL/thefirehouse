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

## Update it

Upload the new version's zip the same way. WordPress shows the installed and uploaded versions side by side; choose **Replace current with uploaded**. The content and the settings stay.

An update that changes the FireHouse Editor role puts the role, and administrators' FireHouse capabilities, back as the plugin defines them. Changes made to either with a role editor don't last past it.

## Deactivate or delete it

- **Deactivating** stops the FireHouse: its project and topic pages no longer open, and the landing and About pages show none of their FireHouse parts. Everything stays in the database, and reactivating brings it back. People with the FireHouse Editor role can then only sign in.
- **Deleting** the plugin also removes the FireHouse Editor role and the FireHouse capabilities; people who had the role are left with no role on the site. The projects, topic areas, settings and pages stay in the database.
