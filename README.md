![](https://github.com/AzuraCast/AzuraCast/raw/main/resources/azuracast.png)![](https://static.scarf.sh/a.png?x-pxid=d5169ead-cdcf-482c-ab64-c7137d3f8769)

# AzuraCast: A Simple, Self-Hosted Web Radio Management Suite

[![Build Status](https://github.com/azuracast/azuracast/workflows/Build,%20Test%20and%20Publish/badge.svg)](https://github.com/AzuraCast/AzuraCast/actions)
[![Latest Stable Release](https://img.shields.io/packagist/v/azuracast/azuracast.svg?label=latest+stable+version)]()
[![AGPL-3.0 License](https://img.shields.io/github/license/azuracast/azuracast.svg)]()
[![Ethical Open Source](https://img.shields.io/badge/open-ethical-%234baaaa)](https://ethicalsource.dev/)
[![Mastodon Follow](https://img.shields.io/mastodon/follow/113766260463173065?domain=https%3A%2F%2Ffloss.social&style=social)](https://floss.social/@AzuraCast)
[![BlueSky Follow](https://img.shields.io/bluesky/followers/azuracast.com)](https://bsky.app/profile/azuracast.com)

[![Built with Depot](https://depot.dev/badges/built-with-depot.svg)](https://depot.dev/?utm_source=AzuraCast)

AzuraCast is built and maintained by passionate human beings, and 100% of our code is reviewed and approved by humans. See [our policy on generative AI](https://github.com/AzuraCast/.github/blob/main/CONTRIBUTING.md#our-policy-on-generative-ai) for more details.

![](https://www.azuracast.com/img/ScreenshotTour.gif)

You can learn more about AzuraCast via our [Documentation](https://www.azuracast.com/docs):

- [About AzuraCast](https://www.azuracast.com/docs/#core-features)
- [Included Software](https://www.azuracast.com/docs/#whats-included)
- [System Requirements](https://www.azuracast.com/docs/getting-started/requirements/)
- [Installation](https://www.azuracast.com/docs/getting-started/installation/)

AzuraCast is built and maintained by passionate human beings, and 100% of our code is reviewed and approved by humans. We do not allow AI to replace our role as software designers or developers, nor do we permit "vibe-coded" contributions. See [our policy on generative AI](https://github.com/AzuraCast/.github/blob/main/CONTRIBUTING.md#our-policy-on-generative-ai) for more details.

## Badlands fork deployment

The `feature/delegated-user-management` branch builds `ghcr.io/badlands-rp/azuracast:delegated`
through the **Build Badlands AzuraCast** GitHub Actions workflow. The first published package must be public
in GitHub Packages for your server and web updater to pull it without registry credentials.

Before switching an existing installation, download a full backup from AzuraCast. In Termius, open the server
and change to its AzuraCast installation directory (normally `/var/azuracast`). Add this setting to the existing
`docker-compose.override.yml`, or create that file if it does not exist:

```yaml
services:
  web:
    image: ghcr.io/badlands-rp/azuracast:delegated
```

Preserve any existing overrides. Then run `docker compose pull web` followed by `docker compose up -d web`.
The container applies the database migration on startup. This restarts the radio container. Subsequent web
updates pull the fork image configured above; update availability notices still describe upstream releases.
Keep this override when updating the standard Compose configuration.

As a super administrator, edit the Radio Admin role, grant the required Create Users, Manage Users, Assign Roles,
and Manage Roles permissions, and select the ordinary roles it may delegate. The acting administrator must also
have every permission in those roles, including the corresponding station access. Existing roles delegate nothing
until configured. Newly created roles require super-admin approval before delegates can assign them.
User administrators, API key administrators, protected accounts, and the acting user's own roles cannot be altered
through delegated administration. Password resets use the existing user editor; account deletion uses the existing
Delete action. This version of AzuraCast has no account deactivation field.

Run the security checks with `vendor/bin/phpunit --bootstrap tests/_bootstrap.php tests/Unit/DelegatedUserAdministrationTest.php`.
The image reuses the upstream runtime pinned in `Dockerfile.badlands`; update that digest together with the fork's
upstream source when upgrading PHP, radio services, or dependencies.

## Live Demo

Want to see AzuraCast for yourself? Visit our demo site at [demo.azuracast.com](https://demo.azuracast.com/):

* Username: `demo@azuracast.com`
* Password: `demo`

## Install AzuraCast

To install AzuraCast, you should have a basic understanding of the Linux shell terminal. Once installed, every aspect of
your radio station can be managed via AzuraCast's web interface.

Follow our **[installation guide](https://www.azuracast.com/docs/getting-started/installation/)** for instructions on
how
to install AzuraCast on your own server.

## License

AzuraCast is licensed under
the [Affero GNU General Public License (AGPL) version 3.0](https://github.com/AzuraCast/AzuraCast/blob/main/LICENSE.md).
This project is free
and open-source software, and pull requests are always welcome.

## Need Help?

If you need help with AzuraCast, the first place you should visit is
our [Support page](https://www.azuracast.com/docs/help/troubleshooting/), which features solutions to a number of
commonly encountered issues and questions, as well as instructions on how to
check your server's log files for more details. If you do need our help via GitHub, supplying these logs is absolutely
essential in helping us diagnose and resolve your issue.

New feature requests are powered by FeatureUpvote. You can visit
our [Feature Request Page](https://features.azuracast.com/) to submit a new feature request or vote on existing ones.

For bug and error reports, we rely exclusively on
our [GitHub Issues board](https://github.com/AzuraCast/AzuraCast/issues?q=is%3Aissue+is%3Aopen+sort%3Aupdated-desc) to
diagnose, track and update these reports. First, check to make sure the issue you're experiencing isn't already reported
on GitHub. If it is, you can subscribe to the existing ticket for updates on the issue's progress. If your issue or
request isn't already reported, click the "New Issue" button to create it. Make sure to follow the template provided, as
it asks important details that are very important to our team.

Please keep in mind that AzuraCast is a free software project built and maintained by volunteers, so there may be some
delays in getting back to you. We will make the absolute best effort possible to resolve your issues and answer your
questions.

## Communities and Social Media

We frequently post to social media any time there are significant updates to our software, security issues that users
should be aware of, or upcoming changes to third-party software. You can get these updates in a more timely fashion by
following our accounts:

- On BlueSky at [@azuracast.com](https://bsky.app/profile/azuracast.com)

- On Mastodon at [@AzuraCast@floss.social](https://floss.social/@AzuraCast)

If you are an AzuraCast user, station owner, developer or other contributor, you can also join our Discord community,
where you can ask questions, share your station and more:

- [Discord](https://discord.gg/azuracast)

Note that our social media channels aren't the best way to report issues to us; instead, you should use the GitHub
issues instructions above, as this allows our whole team to help resolve and track the progress of the issue in one
location.

## Friends of AzuraCast

We would like to thank the following organizations for their support of AzuraCast's ongoing development:

- [JetBrains](https://www.jetbrains.com/) for making our development faster, easier and more productive with tools like
  PhpStorm
- [CrowdIn](https://crowdin.com/) for giving us a simple and powerful tool to help translate our application for users
  around the world
- [Netlify](https://www.netlify.com/) for supporting open-source software like ours and for serving as the host of our
  primary [azuracast.com](https://www.azuracast.com/) website.
- [BrowserStack](https://www.browserstack.com/) for giving us tools to help test our software's accessibility and
  features across multiple browsers and operating systems.
- [Depot](https://depot.dev/?utm_source=AzuraCast) for powering our Docker image builds.

- The creators and maintainers of the many free and open-source tools that AzuraCast is built on, who have done so much
  to help move FOSS forward

## Support AzuraCast Development

AzuraCast will always be available free of charge, but if you find the software useful and would like to support the
project's lead developer, visit either of the links below. Your support is greatly appreciated.

<a href="https://ko-fi.com/silvereagle" target="_blank" title="Buy me a coffee!"><img height='32' style='border:0px;height:32px;' src='https://az743702.vo.msecnd.net/cdn/kofi1.png?v=b' border='0' alt='Buy Me a Coffee at ko-fi.com' /></a>

<a href="https://www.patreon.com/bePatron?u=232463" target="_blank" title="Become a Patron"><img src="https://c5.patreon.com/external/logo/become_a_patron_button.png"></a>
