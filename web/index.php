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

$app = new Application();

// Register cache service
$app->register(new Silex\Provider\HttpCacheServiceProvider(), array(
    'http_cache.cache_dir' => __DIR__.'/../cache/',
));

// Loading config
$yaml = new Parser();
$app['config'] = $yaml->parse(file_get_contents('../config/config.yml'));

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
$app->get('/location/{locationId}', function(Application $app, $locationId) {
    $locationUrl = sprintf(
        "http://west.basketball.nl/cgi-bin/route.pl?loc_ID=%1d",
        $locationId
    );
        
    $curlResult = curlRequest($locationUrl);
    
    if($curlResult) {
        $crawler = new Crawler($curlResult);
        $crawler = $crawler->filter('table tr[class="dark"]')->each(function(Crawler $node, $i) {
            return $node->filter('td')->each(function(Crawler $node) { return $node->html(); });
        });
        if(sizeof($crawler) > 1) {
            $locationData = array();
            foreach($crawler as $data) {
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
                if($app['config']['gmaps_api_key'])
                {
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
            return new JsonResponse($locationData, 200);
        } else {
            $app->abort(417, "The page was found but there was no data");
        }
    } else {
        $app->abort(417, "There was an error requesting the location data");
    }
})
->assert('locationId', '\d+');

/**
 * Route for scraping the match schedule
 */
$app->get('/{team}/{year}', function(Application $app, $team, $year)
{
    if(!isset($app['config']['teams'][$team])) {
        $app->abort(501, "The requested team hasn't been configured.");
    } elseif(!isset($app['config']['teams'][$team][$year])) {
        $app->abort(501, "The requested season hasn't been configured.");
    } else {
        $teamConfig = $app['config']['teams'][$team][$year];
        
        $seasonUrl = sprintf(
            "http://west.basketball.nl/db/wedstrijd/uitslag.pl?szn_Naam=%1d-%2d&plg_ID=%3d&cmp_ID=%4d&Sorteer=wed_Datum&LVactie=Wedstrijdgegevens&nummer=off&statistieken=off&advertentie=off&menubalk=off&title=off&warning=off",
            $year,
            $year+1,
            $teamConfig['team_id'],
            $teamConfig['competition']
        );
        
        $curlResult = curlRequest($seasonUrl);
        
        if($curlResult) {
            $crawler = new Crawler($curlResult);
            $crawler = $crawler->filter('table table > tr')->each(function(Crawler $node, $i) {
                return $node->filter('td')->each(function(Crawler $node, $i) { return $node->text(); });
            });
            $vCalendar = new Calendar($app['request']->getPathInfo());
            foreach($crawler as $match)
            {
                if(sizeof($match) > 0) {
                    $startDate = \DateTime::createFromFormat('d-m-Y H:i', $match[0] .' '. $match[1], new \DateTimeZone('Europe/Amsterdam'));
                    $endDate = clone $startDate;
                    $vEvent = new Event();
                    $vEvent
                        ->setDtStart($startDate)
                        ->setDtEnd($endDate->add(new \DateInterval('PT2H')))
                        ->setSummary($match[2] .' - '. $match[3])
                        ->setUseTimezone(true)
                        ->setLocation($match[4])
                    ;
                    $score = trim($match[5]);
                    if($score !== '0 - 0') {
                        $vEvent->setSummary($match[2] .' - '. $match[3] .' ('. $score .')');
                    }
                    $vCalendar->addEvent($vEvent);
                }
            }
            
            if($app['debug'])
            {
                return new Response($vCalendar->render(), 200);
            } else {
                $response = new Response($vCalendar->render(), 200, array(
                    'Content-Type' => 'text/calendar; charset=utf-8',
                    'Content-Disposition' => 'inline; filename="'. $app['config']['file_prefix'] . $app['request']->getPathInfo() .'.ics"')
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
->assert('year', '\d{4}');

Request::setTrustedProxies(array('127.0.0.1'));
if($app['debug']) {
    $app->run();
} else {
    $app['http_cache']->run();
}
