<?php
require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;

use Symfony\Component\HttpFoundation\Response;
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

$app->get('/{team}/{year}', function(Application $app, $team, (int) $year)
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
        
        $curlRequest = curl_init();
        curl_setopt_array($curlRequest, array(
            CURLOPT_URL => str_replace(' ', '', $seasonUrl),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => 0,
        ));
        
        $curlResult = curl_exec($curlRequest);
        curl_close($curlRequest);
        
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