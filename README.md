Description
------------

This application provides a iCal complient calendar by scraping the roster of west.basketball.nl

Installation
------------

Download the source or clone this repository.

Run Composer via terminal

    $ composer.phar install;

Configuration
------------

Copy and rename `config/config.yml.dist` to `config.yml`.

Each team requires a team ID and a competition ID these can be found on [http://db.basketball.nl][1]. First select the competition in which the team plays, press "Selecteren". Open the source of the page, the competion ID can be found in the HTML-element `<select name="cmp_ID">`. Since you've previously selected it, it is the one with `selected="selected"`. Search for the HTML-element `<select name="plg_ID">` to get the team ID for your team.

Fill the `team`-array in *config.yml* with teams like so:

    {team name}:
        team_id: {plg_ID value}
        competion_id: {cmp_ID value}

This is an example configuration file for the first and second team of [Red Giants][2] during season 2014-2015:

    file_prefix: "redgiants"
    teams:
        h1:
            team_id: 1780
            competition: 426
        h2:
            team_id: 1788
            competition: 367

If you want to show the geo location of gyms (Only supported by Apple) request a [Google Maps API key][5] and paste it in to the `gmaps_api_key` configuration variable in `config.yml`.

The script automatically selects the current season.

Practices
------------

As of version 1.0 it is possible to add practices dates. First add the start and end date for the whole practices season to your configuration:

    practices:
        start: "2015-08-17"
        end: "2016-06-01"

Makes sure the format for these dates is `Y-m-d`.

After adding these settings, the days have to be added to the team configuration. 

Check `config.yml.dist` for an example.

Usage
------------

Each teams match calendar can be reached by using the team name and year in the URL. Assuming your document root is the `web`-folder this should be the request format: `/{team name}`

If you want a calendar with the practices, simply add the GET-parameter `practices` with value `1`. The URL will then be `/{team name}?practices=1`

Using the `webcal`-protocol adds the calendar as a subscription instead of individual events. (Preferred usage)

You can also use the `http`-protocol and manually add the URL for calendar subscriptions, instructions can be found here:

- [Apple Calendar][3]
- [Google Calendar][4]

Caching
------------

Every request is being cache for 12 hours to prevent flooding of the roster page.

Requests for gym details is being cached for 5 days.

Contributions
------------

You are more than welcome to suggest enhancements or new features.

If want to submit code, please submit your pull request to the develop branch.

[1]: http://db.basketball.nl/db/wedstrijd/uitslag.pl?
[2]: http://www.redgiants.nl
[3]: http://support.apple.com/kb/PH11523
[4]: https://support.google.com/calendar/answer/37100?hl=en
[5]: https://developers.google.com/maps/documentation/geocoding/?hl=en#api_key
