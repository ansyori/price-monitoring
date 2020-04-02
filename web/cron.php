<?php

require(__DIR__.'/../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Goutte\Client;


$app = new Silex\Application();


// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/app',
));

// database
$dbopts = parse_url(getenv('DATABASE_URL'));
$app->register(new Csanquer\Silex\PdoServiceProvider\Provider\PDOServiceProvider('pdo'),
               array(
                'pdo.server' => array(
                   'driver'   => 'pgsql',
                   'user' => $dbopts["user"],
                   'password' => $dbopts["pass"],
                   'host' => $dbopts["host"],
                   'port' => $dbopts["port"],
                   'dbname' => ltrim($dbopts["path"],'/')
                   )
               )
);


$st = $app['pdo']->prepare(" SELECT * FROM product_data ");
$st->execute();

$client = new Client();

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    try{
        $crawler = $client->request('GET', $row['url']);
        $new_price = $crawler->filter('.price-box.price-final_price .price-wrapper')->first()->attr('data-price-amount');

        $price_update = $app['pdo']->prepare('UPDATE  product_data
                                              SET price = ? 
                                              WHERE id = ? 
                                            ');
        $price_update->execute([$new_price,$row['id']]);

        echo 'Price update '.$row['name'].' success '. "\n";

    }catch(\Exception $x)
    {
        echo 'Price update '.$row['name'].' failed :  '. $x->getMessage() . "\n";
    }
    
};


$app->get('/', function() use($app) {
    return 'cron successfully executed';
});


$app->run();
