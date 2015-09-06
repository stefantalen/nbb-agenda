<?php
require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\DomCrawler\Crawler;

use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;
use Eluceo\iCal\Property\Event\RecurrenceRule;

$app = new Application();

// Register cache service
$app->register(new Silex\Provider\HttpCacheServiceProvider(), array(
    'http_cache.cache_dir' => __DIR__.'/../cache/',
));
// Register URL generator
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

// Loading config
$yaml = new Parser();
$app['config'] = $yaml->parse(file_get_contents('../config/config.yml'));

$app['debug'] = false;

$app->error(function (\Exception $e, $code) {
    return new Response($e->getMessage());
});

/**
 * Function for executing cURL requests
 *
 * @param $url string The URL to retrieve
 * @return string
 */
function curlRequest($url)
{
    $curlRequest = curl_init();
    curl_setopt_array($curlRequest, array(
        CURLOPT_URL => str_replace(' ', '', $url),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => 0,
    ));

    $curlResult = curl_exec($curlRequest);
    curl_close($curlRequest);
    return $curlResult;
}

/**
 * Route for retrieving location data of a gym
 */
$app->get('/location/{locationId}', function (Application $app, $locationId) {
    $locationUrl = sprintf(
        "http://db.basketball.nl/cgi-bin/route.pl?loc_ID=%1d",
        $locationId
    );
    $curlResult = curlRequest($locationUrl);
    if ($curlResult) {
        $crawler = new Crawler($curlResult);
        $crawler = $crawler->filter('table tr[class="dark"]')->each(function (Crawler $node, $i) {
            return $node->filter('td')->each(function(Crawler $node) { return $node->html(); });
        });
        if (sizeof($crawler[0]) > 0) {
            $locationData = array();
            foreach ($crawler as $data) {
                $addressData = explode('<br>', $data[1]);
                $zipcodeData = explode(' ', $addressData[1]);
                $locationData = array(
                    'title' =>preg_replace('/\s\((.*)\)$/', '', $data[0]),
                    'street' => $addressData[0],
                    'zipcode' => $zipcodeData[0] .' '. $zipcodeData[1],
                    'city' => $zipcodeData[2],
                    'phonenumber' => $addressData[2],
                    'lat' => null,
                    'lng' => null
                );
                // Only request latitude and longitude when an API key is provided
                if (isset($app['config']['gmaps_api_key'])) {
                    $gmapsUrl = sprintf(
                        "https://maps.googleapis.com/maps/api/geocode/json?address=%1s&key=%2s",
                        str_replace(' ', '+', $locationData['street'] .' '. $locationData['city']),
                        $app['config']['gmaps_api_key']
                    );
                    $gmapsData = json_decode(curlRequest($gmapsUrl));
                    if($gmapsData->status === "OK") {
                        $locationData['lat'] = $gmapsData->results[0]->geometry->location->lat;
                        $locationData['lng'] = $gmapsData->results[0]->geometry->location->lng;
                    }
                }
            }
            $jsonResponse = new JsonResponse($locationData, 200);
            $jsonResponse
                ->setPublic()
                ->setSharedMaxAge(86400*5) // 5 days
            ;
            return $jsonResponse;
        } else {
            $app->abort(417, "The page was found but there was no data");
        }
    } else {
        $app->abort(417, "There was an error requesting the location data");
    }
})
->bind('location')
->assert('locationId', '\d+');

/**
 * Route for scraping the match schedule
 */
$app->get('/{team}', function(Application $app, $team)
{
    if(!isset($app['config']['teams'][$team])) {
        $app->abort(501, "The requested team hasn't been configured.");
    } else {
        $teamConfig = $app['config']['teams'][$team];
        
        $currentDate = new \DateTime();
        $year = $currentDate->format("m") < 9 ? $currentDate->format('Y') - 1 : (int) $currentDate->format('Y');
        
        $seasonUrl = sprintf(
            'http://db.basketball.nl/db/wedstrijd/uitslag.pl?szn_Naam=%1$d-%2$d&plg_ID=%3$d&cmp_ID=%4$d&Sorteer=wed_Datum&LVactie=Wedstrijdgegevens&nummer=off&statistieken=off&advertentie=off&menubalk=off&title=off&warning=off',
            $year,
            $year+1,
            $teamConfig['team_id'],
            $teamConfig['competition']
        );

        $curlResult = curlRequest($seasonUrl);
        
        $vCalendar = new Calendar($app['request']->getPathInfo());

        if ($curlResult) {
            $crawler = new Crawler($curlResult);
            $crawler = $crawler->filter('table table > tr')->each(function(Crawler $node, $i) {
                return $node->filter('td')->each(function(Crawler $node, $i) { return $node->html(); });
            });
            foreach ($crawler as $match) {
                if (sizeof($match) > 0) {
                    $startDate = \DateTime::createFromFormat('d-m-Y H:i', $match[0] .' '. $match[1], new \DateTimeZone('Europe/Amsterdam'));
                    $endDate = clone $startDate;
                    $vEvent = new Event();
                    $vEvent
                        ->setDtStart($startDate)
                        ->setDtEnd($endDate->add(new \DateInterval('PT2H')))
                        ->setSummary($match[2] .' - '. $match[3])
                        ->setUseTimezone(true)
                    ;
                    $score = trim($match[5]);
                    if ($score !== '0 - 0') {
                        $vEvent->setSummary($match[2] .' - '. $match[3] .' ('. $score .')');
                    }
                    preg_match('/\?loc_ID=(\d+)"/', $match[4], $gymResult);
                    $geoData = json_decode(curlRequest($app['request']->server->get('HTTP_HOST').$app['url_generator']->generate('location', array('locationId' => $gymResult[1]))));

                    if ($geoData) {
                        $vEvent->setLocation(sprintf(
                            "%1s\n%2s\n%3s %4s",
                            $geoData->title,
                            $geoData->street,
                            $geoData->zipcode,
                            $geoData->city), $geoData->title, $geoData->lat .','. $geoData->lng);
                        $vEvent->setDescription('Telefoonnummer sporthal: '. $geoData->phonenumber);
                    } else {
                        // If there was no data available just use the table data
                        preg_match('/<a(.*)>(.*)<\/a>/', $match[4], $gymResult);
                        $vEvent->setLocation($gymResult[2]);
                    }

                    $vCalendar->addEvent($vEvent);
                }
            }
            // Add practices when requested
            if ($app['request']->query->get('practices') && $app['request']->query->get('practices') === '1') {
                // Add practices if they have been set and a start and end date have been set.
                if (array_key_exists('practices', $teamConfig) && array_key_exists('practices', $app['config']) && (array_key_exists('start', $app['config']['practices']) && array_key_exists('end', $app['config']['practices']))) {
                    $practiceStart = \DateTime::createFromFormat('Y-m-d H:i:s', $app['config']['practices']['start'] .'00:00:00');
                    $practiceEnd = \DateTime::createFromFormat('Y-m-d H:i:s', $app['config']['practices']['end'] .'23:59:59');
                    $practiceWeeks = (int) ceil($practiceStart->diff($practiceEnd)->days / 7);
                    
                    
                    foreach ($teamConfig['practices'] as $practiceDay => $practiceTime) {
                        preg_match_all('/[0-2]{1}[0-9]{1}:[0-5]{1}[0-9]{1}/', $practiceTime, $times);
                        $startTime = explode(':', $times[0][0]);
                        $endTime = explode(':', $times[0][1]);
                        
                        $startDate = clone $practiceStart;
                        $startDate->modify("next ". $practiceDay);
                        if ($startDate->diff($practiceStart)->days  === 7) {
                            $startDate = $practiceStart;
                        }
                        $endDate = clone $startDate;
                        // Setting the time
                        $startDate->setTime((int) $startTime[0], (int) $startTime[1]);
                        $endDate->setTime((int) $endTime[0], (int) $endTime[1]);
                     
                        $vEvent = new Event();
                        $vEvent
                            ->setDtStart($startDate)
                            ->setDtEnd($endDate)
                            ->setSummary('Training '. strtoupper($team))
                            ->setUseTimezone(true)
                        ;
                        $recurrenceRule = new RecurrenceRule();
                        $recurrenceRule
                            ->setFreq(RecurrenceRule::FREQ_WEEKLY)
                            ->setInterval(1)
                            ->setCount($practiceWeeks);
                        ;
                        $vEvent->setRecurrenceRule($recurrenceRule);
                        $vCalendar->addEvent($vEvent);
                    }
                }
            }

            if ($app['debug']) {
                return new Response($vCalendar->render(), 200);
            } else {
                $response = new Response($vCalendar->render(), 200, array(
                    'Content-Type' => 'text/calendar; charset=utf-8',
                    'Content-Disposition' => 'inline; filename="'. $app['config']['file_prefix'] . $team .'-'. $year .'-'. $year + 1 .'.ics"')
                );

                // Caching rules for the response
                $response
                    ->setPublic()
                    ->setSharedMaxAge(43200) // 12 hours
                ;

                return $response;
            }
        } else {
            $app->abort(417, "There was an error requesting the page");
        }
    }
})
->bind('agenda');

Request::setTrustedProxies(array('127.0.0.1'));
if ($app['debug']) {
    $app->run();
} else {
    $app['http_cache']->run();
}
