Description
------------

This application provides a iCal complient calendar by scraping the roster of west.basketball.nl

Installation
------------

Download the source or clone this respository.

Run Composer via terminal

    $ composer.phar install;

Configuration
------------

Copy and rename `config/config.yml.dist` to `config.yml`.

Each team requires a team ID and a competition ID these can be found on [http://west.basketball.nl][1]. First select the competition in which the team plays, press "Selecteren". Open the source of the page, the competion ID can be found in the HTML-element `<select name="cmp_ID">`. Since you've previously selected it, it is the one with `selected="selected". Search for the HTML-element `<select name="plg_ID">` to get the team ID for your team.

Fill the `team`-array in *config.yml* with teams like so:

    {team name}:
        {first year of season}:
            team_id: {plg_ID value}
            competion_id: {cmp_ID value}

This is an example configuration file for the first and second team of [Red Giants][2] during season 2014-2015:

    file_prefix: "redgiants"
    teams:
        h1:
            2014:
                team_id: 1780
                competition: 426
        h2:
            2014:
                team_id: 1788
                competition: 367

Usage
------------

Each teams match calendar can be reached by using the team name and year in the URL. Assuming your document root is the `web`-folder this should be the request format: /{team name}/{first year of season}

Every request is being cache for a day to prevent flooding of the roster page.

The URLs can be used for calendar subscriptions, instructions can be found here:

- [Apple Calendar][3]
- [Google Calendar][4]

[1]: http://west.basketball.nl/db/wedstrijd/uitslag.pl?
[2]: http://www.redgiants.nl
[3]: http://support.apple.com/kb/PH11523
[4]: https://support.google.com/calendar/answer/37100?hl=en
